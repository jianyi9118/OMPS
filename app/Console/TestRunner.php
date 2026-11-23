<?php
namespace Omps\Console;

use Omps\Core\App;
use Omps\Database\DB;
use Omps\Database\Migrator;
use Omps\Database\Seeder;

/**
 * Built-in test runner (no Composer / PHPUnit). Uses the testing database from
 * .env.testing, rebuilds it, then runs every tests/**\/*Test.php class.
 *   php omps test            all tests
 *   php omps test Auth       only classes whose name contains "Auth"
 */
class TestRunner
{
    /** Remove the testing files folder (only ever called with the testing FILES_PATH). */
    private static function removeDir($dir)
    {
        if ($dir === '' || !is_dir($dir) || strpos(str_replace('\\', '/', $dir), '/test-files') === false) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }

    public static function run(array $args)
    {
        if (!is_file(OMPS_ROOT . '/.env.testing') && !getenv('OMPS_TESTING_ENV')) {
            Console::line('Missing .env.testing (copy .env.example, set APP_ENV=testing and a separate DB_DATABASE such as omps_test).');
            return 1;
        }
        $prodDb = (string) config('database.database');
        Console::useTestingEnv();
        $db = (string) config('database.database');
        if ($db === '' || $db === $prodDb) {
            Console::line('Refusing to run tests against database "' . $db . '" (use a separate test database in .env.testing)');
            return 1;
        }
        try {
            DB::serverConnection(true)->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\Exception $e) {
            // no global CREATE privilege: the test database must already exist
        }
        self::removeDir((string) config('app.files_path'));
        foreach (glob(storage_path('temp') . '/import-*') ?: array() as $f) {
            @unlink($f);
        }
        DB::useConnection('migrate');
        $m = new Migrator();
        $m->dropAll();
        $m->migrate();
        DB::useConnection('app');
        DB::disconnect();
        (new Seeder())->run();

        require_once OMPS_ROOT . '/tests/TestCase.php';
        $filter = isset($args[0]) && strncmp($args[0], '--', 2) !== 0 ? $args[0] : '';
        $files = array();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(OMPS_ROOT . '/tests', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (substr($f->getFilename(), -8) === 'Test.php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        $pass = 0;
        $fail = 0;
        $start = microtime(true);
        foreach ($files as $file) {
            $class = 'Omps\\Tests\\' . basename($file, '.php');
            if ($filter !== '' && stripos($class, $filter) === false) {
                continue;
            }
            require_once $file;
            $methods = array_filter(get_class_methods($class), function ($m) {
                return strncmp($m, 'test', 4) === 0;
            });
            Console::line(basename($file, '.php'));
            foreach ($methods as $method) {
                App::reset();
                \Omps\Core\Lang::clearCache();
                \Omps\Engines\Permission::clearCache();
                \Omps\Core\Settings::clearCache();
                $t = new $class();
                try {
                    $t->setUp();
                    $t->$method();
                    $pass++;
                    Console::line('  ok    ' . $method . ' (' . $t->assertions . ')');
                } catch (\Exception $e) {
                    $fail++;
                    Console::line('  FAIL  ' . $method . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
                } catch (\Throwable $e) {
                    $fail++;
                    Console::line('  FAIL  ' . $method . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
                }
            }
        }
        Console::line('');
        Console::line(sprintf('%d passed, %d failed (%.1fs) on PHP %s, %s', $pass, $fail, microtime(true) - $start, PHP_VERSION, (string) DB::value('SELECT VERSION()')));
        return $fail ? 1 : 0;
    }
}
