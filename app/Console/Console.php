<?php
namespace Omps\Console;

use Omps\Core\Auth;
use Omps\Core\Env;
use Omps\Database\DB;
use Omps\Database\Migrator;
use Omps\Database\Seeder;

/**
 * Command dispatcher for `php omps`. Output is plain text for operators (not translated UI).
 */
class Console
{
    const COMMANDS = array(
        'help' => 'List commands',
        'db:create' => 'Create the database if it does not exist (migration account)',
        'migrate' => 'Run pending migrations (migration account); --seed also syncs base data; backs up first when upgrading (--no-backup to skip)',
        'migrate:status' => 'Show ran and pending migrations',
        'migrate:fresh' => 'Drop all tables and migrate again (only when APP_ENV=testing)',
        'seed' => 'Sync base data: languages, roles, permissions, menus, dictionaries',
        'admin:create' => 'Create a super admin (password typed at the prompt; --password-stdin for scripts)',
        'cleanup' => 'Delete expired temporary files; purge recycle-bin files older than 30 days and read notifications older than 180 days',
        'schedule:run' => 'Send due reminders and make the daily automatic backup (run every 5 minutes from Windows Task Scheduler)',
        'backup:create' => 'Back up the database and the uploaded files now (zip in BACKUP_PATH)',
        'backup:restore' => 'Restore a backup: php omps backup:restore <file name in BACKUP_PATH> (makes a safety backup first; --force ignores another APP_KEY)',
        'key:generate' =>'Create APP_KEY in .env (encryption key for ID numbers etc.; only when missing)',
        'env:check' => 'Check PHP version, extensions, folders and the database',
        'compat:check' => 'Scan the code for syntax not supported on PHP 7.2 or removed in PHP 8',
        'i18n:check' => 'Check language packs and scan for hard-coded text',
        'test' => 'Run the automated tests (uses the testing database from .env.testing)',
    );

    public static function run(array $argv)
    {
        $cmd = isset($argv[1]) ? $argv[1] : 'help';
        $args = array_slice($argv, 2);
        $opts = self::options($args);
        try {
            switch ($cmd) {
                case 'help':
                    return self::help();
                case 'db:create':
                    return self::dbCreate();
                case 'migrate':
                    return self::migrate($opts);
                case 'migrate:status':
                    return self::status();
                case 'migrate:fresh':
                    return self::fresh($opts);
                case 'seed':
                    return self::seed();
                case 'admin:create':
                    return self::adminCreate($opts);
                case 'key:generate':
                    return self::keyGenerate($opts);
                case 'cleanup':
                    return self::cleanup();
                case 'schedule:run':
                    return self::scheduleRun($opts);
                case 'backup:create':
                    return self::backupCreate();
                case 'backup:restore':
                    return self::backupRestore($args, $opts);
                case 'env:check':
                    return EnvCheck::run();
                case 'compat:check':
                    return CompatCheck::run($args);
                case 'i18n:check':
                    return I18nCheck::run();
                case 'test':
                    return TestRunner::run($args);
            }
            self::line('Unknown command: ' . $cmd);
            self::help();
            return 1;
        } catch (\Exception $e) {
            self::line('ERROR: ' . $e->getMessage());
            return 1;
        }
    }

    public static function line($s = '')
    {
        fwrite(STDOUT, $s . PHP_EOL);
    }

    private static function options(array $args)
    {
        $o = array();
        foreach ($args as $a) {
            if (strncmp($a, '--', 2) === 0) {
                $p = strpos($a, '=');
                if ($p === false) {
                    $o[substr($a, 2)] = true;
                } else {
                    $o[substr($a, 2, $p - 2)] = substr($a, $p + 1);
                }
            }
        }
        return $o;
    }

    private static function help()
    {
        self::line('OMPS command line. Usage: php omps <command> [options]');
        self::line('');
        foreach (self::COMMANDS as $k => $v) {
            self::line('  ' . str_pad($k, 16) . $v);
        }
        return 0;
    }

    private static function dbCreate()
    {
        $name = (string) config('database.database');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \RuntimeException('Invalid database name');
        }
        $pdo = DB::serverConnection(true);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::line('Database ready: ' . $name);
        return 0;
    }

    private static function migrate(array $opts)
    {
        // upgrading an installation with data: back up first (design §19)
        if (empty($opts['no-backup']) && (string) config('app.env') !== 'testing') {
            DB::useConnection('migrate');
            $m0 = new Migrator();
            $pending = $m0->pending();
            $installed = (bool) $m0->ran();
            DB::useConnection('app');
            if ($pending && $installed) {
                self::line('Backing up before the upgrade ...');
                $b = \Omps\Engines\Backup::create('pre_migrate', null);
                if ((string) $b['status'] !== 'ok') {
                    self::line('Backup failed (' . $b['message'] . '); nothing migrated. Use --no-backup to migrate anyway.');
                    return 1;
                }
                self::line('  backup  ' . \Omps\Engines\Backup::dir() . '/' . $b['file_name']);
            }
        }
        DB::useConnection('migrate');
        $m = new Migrator();
        $ran = $m->migrate(function ($name) {
            Console::line('  migrated  ' . $name);
        });
        self::line($ran ? count($ran) . ' migration(s) ran.' : 'Nothing to migrate.');
        DB::useConnection('app');
        DB::disconnect();
        if (!empty($opts['seed'])) {
            return self::seed();
        }
        return 0;
    }

    private static function status()
    {
        $m = new Migrator();
        $ran = $m->ran();
        foreach ($m->files() as $f) {
            self::line((in_array($f, $ran, true) ? '  [ran]     ' : '  [pending] ') . $f);
        }
        return 0;
    }

    private static function fresh(array $opts)
    {
        if ((string) config('app.env') !== 'testing') {
            self::line('Refused: migrate:fresh only runs when APP_ENV=testing (it deletes every table).');
            return 1;
        }
        DB::useConnection('migrate');
        $m = new Migrator();
        $m->dropAll();
        $m->migrate();
        DB::useConnection('app');
        DB::disconnect();
        self::line('Fresh database migrated.');
        if (!empty($opts['seed'])) {
            return self::seed();
        }
        return 0;
    }

    private static function seed()
    {
        $s = new Seeder(function ($msg) {
            Console::line('  seeded  ' . $msg);
        });
        $s->run();
        self::line('Base data in sync.');
        return 0;
    }

    private static function prompt($label)
    {
        fwrite(STDOUT, $label);
        $v = fgets(STDIN);
        return $v === false ? '' : trim($v);
    }

    /** Read a password without echo where possible (PowerShell on Windows, stty elsewhere). */
    private static function promptHidden($label)
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            fwrite(STDOUT, $label);
            $cmd = 'powershell -NoProfile -Command "$s = Read-Host -AsSecureString; $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)"';
            $v = shell_exec($cmd);
            if (is_string($v)) {
                return rtrim($v, "\r\n");
            }
            self::line('(could not hide input; the password will be visible)');
            return self::prompt('');
        }
        fwrite(STDOUT, $label);
        @shell_exec('stty -echo');
        $v = fgets(STDIN);
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $v === false ? '' : rtrim($v, "\r\n");
    }

    private static function adminCreate(array $opts)
    {
        $username = isset($opts['username']) && $opts['username'] !== true ? (string) $opts['username'] : self::prompt('User name: ');
        $name = isset($opts['name']) && $opts['name'] !== true ? (string) $opts['name'] : self::prompt('Display name: ');
        if (!preg_match('/^[A-Za-z0-9_.\-@]{3,50}$/', $username)) {
            self::line('User name: 3-50 characters, letters, digits and _ . - @');
            return 1;
        }
        if ($name === '') {
            $name = $username;
        }
        if (DB::table('users')->where('username', $username)->exists()) {
            self::line('That user name already exists.');
            return 1;
        }
        if (!empty($opts['password-stdin'])) {
            $line = fgets(STDIN);
            $pass = $line === false ? '' : rtrim($line, "\r\n");
            $pass2 = $pass;
        } else {
            $pass = self::promptHidden('Password: ');
            $pass2 = self::promptHidden('Repeat password: ');
        }
        if ($pass !== $pass2) {
            self::line('The passwords do not match.');
            return 1;
        }
        $problem = Auth::passwordProblem($pass, $username);
        if ($problem !== null) {
            self::line('Password rejected: ' . $problem);
            return 1;
        }
        $roleId = DB::value('SELECT id FROM roles WHERE code = ?', array('super'));
        if ($roleId === null) {
            self::line('Run "php omps migrate --seed" first.');
            return 1;
        }
        $id = DB::table('users')->insert(array('org_id' => (int) config('app.org_id', 1), 'username' => $username, 'display_name' => mb_substr($name, 0, 50),
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role_id' => (int) $roleId, 'status' => 'active', 'password_changed_at' => now(), 'created_at' => now()));
        \Omps\Engines\Audit::log(null, 'users', 'admin_created_cli', 'user', $id, $username, null, null, 'cli');
        self::line('Super admin created: ' . $username);
        return 0;
    }

    /** Temporary import files older than 1 hour; recycle-bin files older than 30 days (design §11.5). */
    public static function cleanup()
    {
        $n = 0;
        foreach (glob(storage_path('temp') . '/import-*') ?: array() as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600 && @unlink($f)) {
                $n++;
            }
        }
        $purged = 0;
        $limit = date('Y-m-d H:i:s', time() - 30 * 86400);
        foreach (DB::select("SELECT id, rel_path FROM files WHERE status = 'trash' AND deleted_at < ?", array($limit)) as $f) {
            $path = \Omps\Engines\Files::root() . '/' . $f['rel_path'];
            if (is_file($path)) {
                @unlink($path);
            }
            DB::table('file_relations')->where('file_id', (int) $f['id'])->delete();
            DB::table('file_trash_relations')->where('file_id', (int) $f['id'])->delete();
            DB::table('files')->where('id', (int) $f['id'])->delete();
            $purged++;
        }
        $old = DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', date('Y-m-d H:i:s', time() - 180 * 86400))->delete();
        self::line('Temporary files removed: ' . $n . '; recycle-bin files purged: ' . $purged . '; old notifications removed: ' . $old);
        return 0;
    }

    /** One reminder run (design §13). --at="2026-11-30 09:05" acts as that time (for checking rules). */
    private static function scheduleRun(array $opts)
    {
        if (!class_exists('Omps\Modules\Affairs\Services\Scheduler')) {
            self::line('Affairs module not installed.');
            return 1;
        }
        $at = isset($opts['at']) && $opts['at'] !== true ? strtotime((string) $opts['at']) : null;
        if ($at === false) {
            self::line('Invalid --at time.');
            return 1;
        }
        $res = \Omps\Modules\Affairs\Services\Scheduler::run($at, 'cli');
        if ($res === null) {
            self::line('Another run is in progress; nothing done.');
            return 0;
        }
        self::line($res['skipped'] === 'off_hours' ? 'Outside work hours: no reminders sent.' : 'Reminders sent: ' . $res['sent']);
        $b = \Omps\Engines\Backup::autoIfDue($at === null ? null : $at);
        if ($b !== null) {
            self::line('Automatic backup: ' . $b['status'] . ' ' . $b['file_name']);
        }
        return 0;
    }

    private static function backupCreate()
    {
        $b = \Omps\Engines\Backup::create('manual', null);
        if ((string) $b['status'] !== 'ok') {
            self::line('Backup failed: ' . $b['message']);
            return 1;
        }
        self::line('Backup written: ' . \Omps\Engines\Backup::dir() . '/' . $b['file_name'] . ' (' . round($b['size'] / 1048576, 1) . ' MB)');
        return 0;
    }

    private static function backupRestore(array $args, array $opts)
    {
        $name = '';
        foreach ($args as $a) {
            if (strncmp($a, '--', 2) !== 0) {
                $name = basename($a);
                break;
            }
        }
        if ($name === '') {
            self::line('Usage: php omps backup:restore <file name in ' . \Omps\Engines\Backup::dir() . '>');
            return 1;
        }
        self::line('Restoring ' . $name . ' (a safety backup is made first) ...');
        $r = \Omps\Engines\Backup::restore($name, null, !empty($opts['force']));
        \Omps\Engines\Audit::log(null, 'backup', 'backup_restored', 'backup', null, $name, null, array('before' => $r['before']), 'cli');
        self::line('Restored. Safety backup: ' . $r['before']);
        return 0;
    }

    /**
     * Write APP_KEY into .env (or the file given with --file=). Refuses to replace an existing key:
     * values encrypted with the old key could never be read again.
     */
    private static function keyGenerate(array $opts)
    {
        $file = isset($opts['file']) && $opts['file'] !== true ? (string) $opts['file'] : OMPS_ROOT . '/.env';
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        if (preg_match('/^APP_KEY=\S+/m', $text)) {
            self::line('APP_KEY already exists in ' . $file . '; not replaced (existing encrypted data needs it). Back up this file.');
            return 0;
        }
        $line = 'APP_KEY=' . \Omps\Core\Crypto::newKey();
        $text = preg_match('/^APP_KEY=\s*$/m', $text) ? preg_replace('/^APP_KEY=\s*$/m', $line, $text) : rtrim($text) . PHP_EOL . $line . PHP_EOL;
        file_put_contents($file, $text);
        self::line('APP_KEY written to ' . $file . '. Keep a copy of this file with your backups.');
        return 0;
    }

    /** Load .env.testing for test runs (or the file named by OMPS_TESTING_ENV, e.g. a second database server). */
    public static function useTestingEnv()
    {
        $file = getenv('OMPS_TESTING_ENV');
        Env::load(is_string($file) && $file !== '' ? $file : OMPS_ROOT . '/.env.testing', true);
        Env::set('APP_ENV', 'testing');
        \Omps\Core\Config::reset();
        DB::disconnect();
    }
}
