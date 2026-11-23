<?php
namespace Omps\Console;

use Omps\Database\DB;

/**
 * `php omps env:check`: PHP version and extensions, writable folders, database version,
 * least-privilege database account. Optional extensions only warn (pure-PHP fallbacks exist).
 */
class EnvCheck
{
    public static function run()
    {
        $fail = 0;
        $warn = 0;
        $ok = function ($label, $detail) {
            Console::line('  [ok]   ' . str_pad($label, 26) . $detail);
        };
        $bad = function ($label, $detail) use (&$fail) {
            $fail++;
            Console::line('  [FAIL] ' . str_pad($label, 26) . $detail);
        };
        $wn = function ($label, $detail) use (&$warn) {
            $warn++;
            Console::line('  [warn] ' . str_pad($label, 26) . $detail);
        };

        Console::line('OMPS environment check');
        if (PHP_VERSION_ID >= 70200) {
            $ok('PHP version', PHP_VERSION);
        } else {
            $bad('PHP version', PHP_VERSION . ' (7.2 or later required)');
        }
        foreach (array('pdo_mysql', 'openssl', 'mbstring', 'json', 'tokenizer') as $ext) {
            extension_loaded($ext) ? $ok('extension ' . $ext, 'loaded') : $bad('extension ' . $ext, 'missing (required)');
        }
        foreach (array('zip' => '.xlsx/.docx export (falls back to XML/.doc)', 'gd' => 'image thumbnails', 'fileinfo' => 'file type detection (falls back to magic bytes)', 'intl' => 'not required') as $ext => $why) {
            extension_loaded($ext) ? $ok('extension ' . $ext, 'loaded') : $wn('extension ' . $ext, 'missing: ' . $why);
        }
        foreach (array('logs', 'temp', 'cache', 'uploads', 'backups') as $d) {
            $path = storage_path($d);
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            is_dir($path) && is_writable($path) ? $ok('storage/' . $d, 'writable') : $bad('storage/' . $d, 'not writable: ' . $path);
        }
        \Omps\Core\Crypto::hasKey() ? $ok('APP_KEY', 'present') : $bad('APP_KEY', 'missing; run php omps key:generate');
        $fp = (string) config('app.files_path');
        if (!is_dir($fp)) {
            @mkdir($fp, 0775, true);
        }
        is_dir($fp) && is_writable($fp) ? $ok('files folder', $fp) : $bad('files folder', 'not writable: ' . $fp);
        $umax = ini_get('upload_max_filesize') . ' / post ' . ini_get('post_max_size');
        $ok('php upload limits', $umax);
        $env = (string) config('app.env');
        $env === 'production' && config('app.debug') ? $bad('APP_DEBUG', 'must be false in production') : $ok('APP_ENV', $env . (config('app.debug') ? ' (debug on)' : ''));
        if (!is_file(OMPS_ROOT . '/.env')) {
            $bad('.env', 'missing (copy .env.example)');
        }
        try {
            $ver = (string) DB::value('SELECT VERSION()');
            $ok('database server', $ver);
            $mode = (string) DB::value('SELECT @@SESSION.sql_mode');
            strpos($mode, 'STRICT_TRANS_TABLES') !== false ? $ok('session sql_mode', 'strict') : $bad('session sql_mode', $mode);
            $cs = (string) DB::value('SELECT @@character_set_database');
            $cs === 'utf8mb4' ? $ok('database charset', $cs) : $wn('database charset', $cs . ' (utf8mb4 expected)');
            $grants = implode("\n", array_map(function ($r) {
                $v = array_values($r);
                return (string) $v[0];
            }, DB::select('SHOW GRANTS FOR CURRENT_USER()')));
            if (preg_match('/GRANT ALL PRIVILEGES ON \*\.\*|\b(CREATE|DROP|ALTER)\b/i', $grants)) {
                $env === 'production' ? $bad('app DB account', 'has schema privileges; use a SELECT/INSERT/UPDATE/DELETE-only account') : $wn('app DB account', 'has schema privileges (acceptable only for development)');
            } else {
                $ok('app DB account', 'least privilege');
            }
            $pending = (new \Omps\Database\Migrator())->pending();
            $pending ? $wn('migrations', count($pending) . ' pending; run php omps migrate') : $ok('migrations', 'up to date');
            if (!$pending) {
                $cli = (string) \Omps\Core\Settings::get('scheduler_last_cli', '');
                $cli !== '' && strtotime($cli) > time() - 3600 ? $ok('reminder task', 'last run ' . $cli)
                    : $wn('reminder task', ($cli === '' ? 'never run from Task Scheduler' : 'last run ' . $cli) . '; reminders then only run while someone has a page open (see docs/INSTALL.md)');
            }
        } catch (\Exception $e) {
            $bad('database', $e->getMessage());
        }
        Console::line('');
        Console::line($fail ? $fail . ' problem(s), ' . $warn . ' warning(s).' : 'All required checks passed' . ($warn ? ' (' . $warn . ' warning(s)).' : '.'));
        return $fail ? 1 : 0;
    }
}
