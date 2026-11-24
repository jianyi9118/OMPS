<?php
namespace Omps\Engines;

use Omps\Core\Logger;
use Omps\Core\Settings;
use Omps\Database\DB;
use Omps\Database\Migrator;
use Omps\Database\Seeder;

/**
 * Backups (design §15, phase 9): the database and the uploaded files in one zip.
 * No external programs: the database is written as SQL by PHP itself, so it works on a stock XAMPP
 * without mysqldump on the PATH.
 *
 * A backup is two things with the same name in the backup folder:
 *  - <name>.zip: manifest.json and database.sql (one statement per line) - small, downloadable;
 *  - <name>/: the uploaded files in the platform's folders. A file that is unchanged since the previous
 *    backup is a hard link to it (NTFS), so daily backups take little extra space; this also keeps
 *    every single file small, which matters on the 32-bit PHP of a stock XAMPP (2 GB limits).
 * The manifest holds a fingerprint of APP_KEY; a backup made with another key is refused on restore,
 * because encrypted ID numbers would not be readable.
 * Older or copied-in zips that carry files/ inside the zip are restored too.
 *
 * Restore: a "before restore" backup is made first; then all tables are replaced (migration account),
 * newer migrations and base data are applied, and the files folder is swapped. The backup history
 * (backup_logs) is kept as it is on the server.
 */
class Backup
{
    const KINDS = array('manual', 'auto', 'pre_restore', 'pre_migrate');
    const CHUNK = 500;

    public static function dir()
    {
        $d = (string) config('app.backup_path', '');
        if ($d === '') {
            $d = OMPS_ROOT . '/storage/backups';
        }
        return rtrim(str_replace('\\', '/', $d), '/');
    }

    /** File size as a float, correct above 2 GB on 32-bit PHP (up to 4 GB per file). */
    public static function size($path)
    {
        $s = @filesize($path);
        if ($s === false) {
            return 0.0;
        }
        return $s < 0 ? (float) $s + 4294967296.0 : (float) $s;
    }

    /** The files folder of a backup (zip name without .zip). */
    public static function filesDir($name)
    {
        return self::dir() . '/' . substr(basename((string) $name), 0, -4);
    }

    private static function keyCheck()
    {
        return substr(hash_hmac('sha256', 'omps-backup', (string) env('APP_KEY', '')), 0, 16);
    }

    private static function logsReady()
    {
        try {
            DB::value('SELECT COUNT(*) FROM backup_logs');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Exclusive lock so that two backups or a backup and a restore never run together. */
    private static function lock()
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('backup.err.folder');
        }
        $h = fopen($dir . '/.lock', 'c');
        if (!$h || !flock($h, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('backup.err.busy');
        }
        return $h;
    }

    private static function unlock($h)
    {
        flock($h, LOCK_UN);
        fclose($h);
    }

    // ---------- writing ----------

    private static function sqlValue($v)
    {
        if ($v === null) {
            return 'NULL';
        }
        $v = (string) $v;
        if (!mb_check_encoding($v, 'UTF-8')) {
            return $v === '' ? "''" : '0x' . bin2hex($v);
        }
        return "'" . str_replace(array('\\', "\0", "\n", "\r", "'", "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\Z'), $v) . "'";
    }

    private static function tables()
    {
        $out = array();
        foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $vals = array_values($row);
            $out[] = (string) $vals[0];
        }
        sort($out);
        return $out;
    }

    /** Write the database as SQL; returns [tables, rows]. */
    private static function dumpDatabase($path)
    {
        $h = fopen($path, 'wb');
        fwrite($h, "-- OMPS backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");
        $tables = self::tables();
        $rows = 0;
        foreach ($tables as $t) {
            $q = '`' . str_replace('`', '``', $t) . '`';
            $create = DB::selectOne('SHOW CREATE TABLE ' . $q);
            $vals = array_values($create);
            fwrite($h, 'DROP TABLE IF EXISTS ' . $q . ";\n" . str_replace(array("\r", "\n"), ' ', (string) $vals[1]) . ";\n");
            for ($off = 0; ; $off += self::CHUNK) {
                $batch = DB::select('SELECT * FROM ' . $q . ' ORDER BY 1 LIMIT ' . self::CHUNK . ' OFFSET ' . $off);
                if (!$batch) {
                    break;
                }
                $cols = '(`' . implode('`,`', array_map(function ($c) {
                    return str_replace('`', '``', $c);
                }, array_keys($batch[0]))) . '`)';
                // statements stay well below max_allowed_packet (1 MB on a stock XAMPP)
                $buf = array();
                $len = 0;
                foreach ($batch as $r) {
                    $line = '(' . implode(',', array_map(array(__CLASS__, 'sqlValue'), array_values($r))) . ')';
                    if ($buf && $len + strlen($line) > 400000) {
                        fwrite($h, 'INSERT INTO ' . $q . ' ' . $cols . ' VALUES ' . implode(',', $buf) . ";\n");
                        $buf = array();
                        $len = 0;
                    }
                    $buf[] = $line;
                    $len += strlen($line);
                    $rows++;
                }
                if ($buf) {
                    fwrite($h, 'INSERT INTO ' . $q . ' ' . $cols . ' VALUES ' . implode(',', $buf) . ";\n");
                }
                if (count($batch) < self::CHUNK) {
                    break;
                }
            }
        }
        fwrite($h, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($h);
        return array(count($tables), $rows);
    }

    /** All files under a folder: [relative path => absolute path]. */
    private static function walk($root)
    {
        $out = array();
        if (!is_dir($root)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $abs = str_replace('\\', '/', $f->getPathname());
                $out[substr($abs, strlen($root) + 1)] = $abs;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Make a backup. Returns the backup_logs row (or a plain array when the table does not exist yet).
     * @param string   $kind   manual | auto | pre_restore | pre_migrate
     */
    public static function create($kind, $userId = null)
    {
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'manual';
        }
        @set_time_limit(0);
        $lock = self::lock();
        $logs = self::logsReady();
        $name = 'omps_' . date('Ymd_His') . '_' . $kind . '.zip';
        $dir = self::dir();
        $path = $dir . '/' . $name;
        $id = $logs ? DB::table('backup_logs')->insert(array('file_name' => $name, 'kind' => $kind, 'status' => 'running', 'started_at' => now(), 'created_by' => $userId)) : null;
        $started = microtime(true);
        $sql = $dir . '/.dump-' . getmypid() . '.sql';
        $fdir = self::filesDir($name);
        try {
            $files = self::walk(Files::root());
            $filesBytes = 0.0;
            foreach ($files as $abs) {
                $filesBytes += self::size($abs);
            }
            $prevDirs = self::previousFilesDirs();
            $source = array();
            $newBytes = 0.0;
            foreach ($files as $rel => $abs) {
                $source[$rel] = null;
                foreach ($prevDirs as $pd) {
                    if (self::same($abs, $pd . '/' . $rel)) {
                        $source[$rel] = $pd . '/' . $rel;
                        break;
                    }
                }
                if ($source[$rel] === null) {
                    $newBytes += self::size($abs);
                }
            }
            $free = @disk_free_space($dir);
            if ($free !== false && $free < $newBytes * 1.1 + 50 * 1048576) {
                throw new \RuntimeException('backup.err.space');
            }
            list($tables, $rows) = self::dumpDatabase($sql);
            $dbBytes = self::size($sql);
            // files: hard link to the previous backup when unchanged, else copy (keeping the time)
            if (!@mkdir($fdir, 0775, true) && !is_dir($fdir)) {
                throw new \RuntimeException('backup.err.folder');
            }
            foreach ($files as $rel => $abs) {
                $target = $fdir . '/' . $rel;
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('backup.err.folder');
                }
                $linked = $source[$rel] !== null && @link($source[$rel], $target);
                if (!$linked) {
                    if (!@copy($abs, $target)) {
                        throw new \RuntimeException('backup.err.write');
                    }
                    @touch($target, (int) filemtime($abs));
                }
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('backup.err.folder');
            }
            $manifest = array('app' => 'omps', 'version' => (string) config('app.version'), 'created_at' => now(), 'kind' => $kind, 'key_check' => self::keyCheck(),
                'tables' => $tables, 'rows' => $rows, 'db_bytes' => $dbBytes, 'files' => count($files), 'files_bytes' => $filesBytes, 'files_dir' => basename($fdir),
                'database' => (string) DB::value('SELECT VERSION()'), 'php' => PHP_VERSION);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $zip->addFile($sql, 'database.sql');
            if (!$zip->close()) {
                throw new \RuntimeException('backup.err.write');
            }
            @unlink($sql);
            // byte counts as integer strings: exact in BIGINT columns even on 32-bit PHP
            $row = array('status' => 'ok', 'db_bytes' => sprintf('%.0f', $dbBytes), 'files_bytes' => sprintf('%.0f', $filesBytes), 'files_count' => count($files),
                'size' => sprintf('%.0f', self::size($path) + $newBytes),
                'sha256' => hash_file('sha256', $path), 'finished_at' => now(), 'message' => null);
        } catch (\Exception $e) {
            @unlink($sql);
            @unlink($path);
            self::rmTree($fdir);
            $msg = strpos($e->getMessage(), 'backup.err.') === 0 ? $e->getMessage() : 'backup.err.write';
            Logger::error('Backup failed', array('error' => $e->getMessage()));
            $row = array('status' => 'failed', 'finished_at' => now(), 'message' => $msg);
        }
        self::unlock($lock);
        $row['duration'] = round(microtime(true) - $started, 1);
        if ($logs) {
            DB::table('backup_logs')->where('id', $id)->update(array_diff_key($row, array('duration' => 1)));
            $out = DB::table('backup_logs')->find($id);
            $out['duration'] = $row['duration'];
            return $out;
        }
        return array_merge(array('file_name' => $name, 'kind' => $kind), $row);
    }

    /** Same content as far as size and time tell (stored files never change in place). */
    private static function same($a, $b)
    {
        return is_file($b) && self::size($a) === self::size($b) && (int) filemtime($a) === (int) filemtime($b);
    }

    /** Files folders of the latest backups, newest first (where unchanged files are linked from). */
    private static function previousFilesDirs()
    {
        $out = array();
        foreach (glob(self::dir() . '/omps_*', GLOB_ONLYDIR) ?: array() as $d) {
            if (is_file($d . '.zip')) {
                $out[] = $d;
            }
        }
        rsort($out);
        return array_slice($out, 0, 10);
    }

    /** Delete a backup: the zip and its files folder. */
    public static function remove($name)
    {
        $p = self::path($name);
        if ($p !== null) {
            @unlink($p);
        }
        self::rmTree(self::filesDir($name));
    }

    // ---------- reading ----------

    /** A backup file name in the folder (no paths). */
    public static function path($name)
    {
        $name = basename((string) $name);
        if (!preg_match('/^[A-Za-z0-9_.\-]+\.zip$/', $name) || !is_file(self::dir() . '/' . $name)) {
            return null;
        }
        return self::dir() . '/' . $name;
    }

    /** Manifest of a backup zip, or null when it is not an OMPS backup. */
    public static function manifest($path)
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        $hasSql = $zip->locateName('database.sql') !== false;
        $zip->close();
        if (!is_array($m) || !isset($m['app']) || $m['app'] !== 'omps' || !$hasSql) {
            return null;
        }
        $m['key_ok'] = isset($m['key_check']) && $m['key_check'] === self::keyCheck();
        return $m;
    }

    // ---------- restoring ----------

    public static function rmTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Restore a backup from the backup folder. Returns ['before' => name of the safety backup, 'manifest' => ...].
     * @throws \RuntimeException with a translation key (backup.err.*)
     */
    public static function restore($name, $userId = null, $force = false)
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $path = self::path($name);
        if ($path === null) {
            throw new \RuntimeException('backup.err.not_found');
        }
        $m = self::manifest($path);
        if ($m === null) {
            throw new \RuntimeException('backup.err.not_backup');
        }
        if (!$m['key_ok'] && !$force) {
            throw new \RuntimeException('backup.err.key');
        }
        $before = self::create('pre_restore', $userId);
        if ((string) $before['status'] !== 'ok') {
            throw new \RuntimeException('backup.err.before');
        }
        $lock = self::lock();
        $root = Files::root();
        $stage = $root . '.restore-' . date('YmdHis');
        $old = $root . '.before-' . date('YmdHis');
        try {
            // 1. files into a staging folder: from the backup's files folder, else from files/ in the zip
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('backup.err.not_backup');
            }
            @mkdir($stage, 0775, true);
            $fdir = isset($m['files_dir']) ? self::dir() . '/' . basename((string) $m['files_dir']) : '';
            if ($fdir !== '' && is_dir($fdir)) {
                foreach (self::walk($fdir) as $rel => $abs) {
                    $target = $stage . '/' . $rel;
                    if (!is_dir(dirname($target))) {
                        @mkdir(dirname($target), 0775, true);
                    }
                    if (!@copy($abs, $target)) {
                        throw new \RuntimeException('backup.err.files');
                    }
                    @touch($target, (int) filemtime($abs));
                }
            } elseif ($fdir !== '' && (int) $m['files'] > 0) {
                throw new \RuntimeException('backup.err.files_missing');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string) $zip->getNameIndex($i);
                if (strpos($n, 'files/') !== 0 || substr($n, -1) === '/') {
                    continue;
                }
                $rel = substr($n, 6);
                if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, ':') !== false || $rel[0] === '/' || $rel[0] === '\\') {
                    continue;
                }
                $target = $stage . '/' . $rel;
                if (!is_dir(dirname($target))) {
                    @mkdir(dirname($target), 0775, true);
                }
                $in = $zip->getStream($n);
                $out = fopen($target, 'wb');
                if (!$in || !$out) {
                    throw new \RuntimeException('backup.err.files');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $st = $zip->statIndex($i);
                if ($st && !empty($st['mtime'])) {
                    @touch($target, (int) $st['mtime']);
                }
            }
            // 2. database (migration account), keeping the server's own backup history
            $sql = $stage . '.sql';
            $s = $zip->getStream('database.sql');
            $o = fopen($sql, 'wb');
            stream_copy_to_stream($s, $o);
            fclose($s);
            fclose($o);
            $zip->close();
            $history = self::logsReady() ? DB::table('backup_logs')->get() : array();
            DB::useConnection('migrate');
            try {
                $mig = new Migrator();
                $mig->dropAll();
                DB::exec('SET FOREIGN_KEY_CHECKS = 0');
                $h = fopen($sql, 'rb');
                while (($line = fgets($h)) !== false) {
                    $line = rtrim($line, "\r\n");
                    if ($line === '' || strncmp($line, '--', 2) === 0) {
                        continue;
                    }
                    DB::exec($line);
                }
                fclose($h);
                DB::exec('SET FOREIGN_KEY_CHECKS = 1');
                $mig->migrate();
                DB::exec('DELETE FROM backup_logs');
                foreach ($history as $r) {
                    DB::table('backup_logs')->insert($r);
                }
            } finally {
                DB::useConnection('app');
            }
            @unlink($sql);
            (new Seeder())->run();
            // 3. swap the files folder
            if (is_dir($root) && !@rename($root, $old)) {
                throw new \RuntimeException('backup.err.files');
            }
            if (!@rename($stage, $root)) {
                @rename($old, $root);
                throw new \RuntimeException('backup.err.files');
            }
            self::rmTree($old);
        } catch (\Exception $e) {
            self::rmTree($stage);
            @unlink($stage . '.sql');
            self::unlock($lock);
            Logger::error('Restore failed', array('error' => $e->getMessage()));
            throw strpos($e->getMessage(), 'backup.err.') === 0 ? $e : new \RuntimeException('backup.err.restore');
        }
        self::unlock($lock);
        \Omps\Core\Lang::clearCache();
        Settings::clearCache();
        return array('before' => (string) $before['file_name'], 'manifest' => $m);
    }

    // ---------- schedule ----------

    /** Daily automatic backup after the set time (called by schedule:run); prunes old automatic backups. */
    public static function autoIfDue($now = null)
    {
        $now = $now === null ? time() : $now;
        if ((string) Settings::get('backup_auto', '1') !== '1' || !self::logsReady()) {
            return null;
        }
        $at = (string) Settings::get('backup_time', '02:00');
        if (!preg_match('/^\d{2}:\d{2}$/', $at) || date('H:i', $now) < $at) {
            return null;
        }
        $done = DB::value("SELECT COUNT(*) FROM backup_logs WHERE kind = 'auto' AND status IN ('ok', 'running') AND started_at >= ?", array(date('Y-m-d 00:00:00', $now)));
        if ((int) $done > 0) {
            return null;
        }
        $r = self::create('auto', null);
        self::prune();
        return $r;
    }

    /** Remove automatic backups older than the kept number of days (manual ones are kept). */
    public static function prune()
    {
        $days = (int) Settings::get('backup_keep_days', 14);
        if ($days <= 0) {
            return 0; // kept for ever
        }
        $limit = date('Y-m-d H:i:s', time() - $days * 86400);
        $n = 0;
        foreach (DB::select("SELECT * FROM backup_logs WHERE kind IN ('auto', 'pre_restore', 'pre_migrate') AND started_at < ?", array($limit)) as $r) {
            self::remove($r['file_name']);
            DB::table('backup_logs')->where('id', (int) $r['id'])->delete();
            $n++;
        }
        return $n;
    }
}
