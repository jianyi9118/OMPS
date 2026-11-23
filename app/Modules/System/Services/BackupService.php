<?php
namespace Omps\Modules\System\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\Settings;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Backup;

/**
 * 数据备份 (phase 9, super administrator): back up now, daily automatic backup settings, list,
 * download, delete, restore. Restoring asks for the administrator's password again.
 */
class BackupService
{
    private static function present(array $r)
    {
        // sizes as floats: correct above 2 GB on 32-bit PHP
        return array('name' => (string) $r['file_name'], 'kind' => (string) $r['kind'], 'status' => (string) $r['status'], 'size' => (float) $r['size'],
            'db_bytes' => (float) $r['db_bytes'], 'files_bytes' => (float) $r['files_bytes'], 'files_count' => (int) $r['files_count'], 'sha256' => str_or_empty($r['sha256']),
            'message' => $r['message'] !== null && $r['message'] !== '' ? __((string) $r['message']) : '', 'at' => (string) $r['started_at'],
            'seconds' => $r['finished_at'] !== null ? max(0, strtotime((string) $r['finished_at']) - strtotime((string) $r['started_at'])) : null,
            'by' => str_or_empty(isset($r['by_name']) ? $r['by_name'] : null), 'exists' => Backup::path((string) $r['file_name']) !== null, 'external' => false);
    }

    public static function index()
    {
        $rows = array();
        $known = array();
        foreach (DB::select('SELECT b.*, u.display_name AS by_name FROM backup_logs b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.started_at DESC, b.id DESC') as $r) {
            $rows[] = self::present($r);
            $known[(string) $r['file_name']] = true;
        }
        // zips copied into the folder by hand (e.g. from another server)
        foreach (glob(Backup::dir() . '/*.zip') ?: array() as $f) {
            $n = basename($f);
            if (!isset($known[$n])) {
                $rows[] = array('name' => $n, 'kind' => 'external', 'status' => 'ok', 'size' => Backup::size($f), 'db_bytes' => 0, 'files_bytes' => 0, 'files_count' => 0,
                    'sha256' => '', 'message' => '', 'at' => date('Y-m-d H:i:s', (int) filemtime($f)), 'seconds' => null, 'by' => '', 'exists' => true, 'external' => true);
            }
        }
        $free = @disk_free_space(is_dir(Backup::dir()) ? Backup::dir() : OMPS_ROOT);
        return array('rows' => $rows, 'dir' => str_replace('/', DIRECTORY_SEPARATOR, Backup::dir()), 'free' => $free === false ? null : (float) $free,
            'settings' => array('auto' => (string) Settings::get('backup_auto', '1') === '1', 'time' => (string) Settings::get('backup_time', '02:00'),
                'keep_days' => (int) Settings::get('backup_keep_days', 14)));
    }

    public static function create(Request $req)
    {
        $r = Backup::create('manual', $req->userId());
        Audit::op($req->user, 'backup', 'backup_created', 'backup', null, (string) $r['file_name'], array('status' => (string) $r['status']), $req->ip());
        if ((string) $r['status'] !== 'ok') {
            throw HttpException::bad((string) $r['message']);
        }
        $r['by_name'] = (string) $req->user['name'];
        return self::present($r);
    }

    public static function saveSettings(Request $req, array $in)
    {
        $auto = !empty($in['auto']);
        $time = isset($in['time']) ? (string) $in['time'] : '';
        $keep = isset($in['keep_days']) ? (int) $in['keep_days'] : 0;
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::field('time', 'validation.format', array('field' => __('backup.time')));
        }
        if ($keep < 1 || $keep > 365) {
            throw ValidationException::field('keep_days', 'backup.err.keep');
        }
        $before = array('auto' => Settings::get('backup_auto', '1'), 'time' => Settings::get('backup_time', '02:00'), 'keep_days' => Settings::get('backup_keep_days', 14));
        Settings::set('backup_auto', $auto ? '1' : '0', $req->userId());
        Settings::set('backup_time', $time, $req->userId());
        Settings::set('backup_keep_days', $keep, $req->userId());
        Audit::change($req, 'backup', 'backup_settings', 'settings', null, null, $before, array('auto' => $auto ? '1' : '0', 'time' => $time, 'keep_days' => $keep));
        return self::index();
    }

    private static function file($name)
    {
        $p = Backup::path($name);
        if ($p === null) {
            throw HttpException::notFound();
        }
        return $p;
    }

    public static function download(Request $req, $name)
    {
        $p = self::file($name);
        Audit::log($req->user, 'backup', 'backup_downloaded', 'backup', null, basename($p), null, null, $req->ip());
        return Response::file($p, basename($p), 'application/zip');
    }

    public static function delete(Request $req, $name)
    {
        $p = self::file($name);
        Backup::remove(basename($p));
        DB::table('backup_logs')->where('file_name', basename($p))->delete();
        Audit::op($req->user, 'backup', 'backup_deleted', 'backup', null, basename($p), null, $req->ip());
        return array('ok' => true);
    }

    /** What a backup holds, before restoring it. */
    public static function inspect($name)
    {
        $m = Backup::manifest(self::file($name));
        if ($m === null) {
            throw HttpException::bad('backup.err.not_backup');
        }
        return array('version' => isset($m['version']) ? (string) $m['version'] : '', 'created_at' => isset($m['created_at']) ? (string) $m['created_at'] : '',
            'tables' => isset($m['tables']) ? (int) $m['tables'] : 0, 'rows' => isset($m['rows']) ? (int) $m['rows'] : 0, 'files' => isset($m['files']) ? (int) $m['files'] : 0,
            'files_bytes' => isset($m['files_bytes']) ? (float) $m['files_bytes'] : 0, 'key_ok' => (bool) $m['key_ok'], 'current' => (string) config('app.version'));
    }

    /** Restore after checking the administrator's password; everyone signs in again afterwards. */
    public static function restore(Request $req, $name, array $in)
    {
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', array($req->userId()));
        if (!isset($in['password']) || !password_verify((string) $in['password'], $hash)) {
            Audit::denied($req, 'backup_restore', array('file' => basename((string) $name)));
            throw ValidationException::field('password', 'backup.err.password');
        }
        self::file($name);
        try {
            $r = Backup::restore($name, $req->userId());
        } catch (\RuntimeException $e) {
            throw HttpException::conflict(strpos($e->getMessage(), 'backup.err.') === 0 ? $e->getMessage() : 'backup.err.restore');
        }
        Audit::log($req->user, 'backup', 'backup_restored', 'backup', null, basename((string) $name), null, array('before' => $r['before']), $req->ip());
        return array('ok' => true, 'before' => $r['before']);
    }
}
