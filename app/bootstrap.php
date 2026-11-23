<?php
/**
 * OMPS bootstrap: autoloader, helpers, configuration, error handling.
 * Compatible with PHP 7.2 – 8.x (see design §01 coding rules).
 */

if (PHP_VERSION_ID < 70200) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo 'OMPS requires PHP 7.2 or later. Current: ' . PHP_VERSION;
    exit(1);
}

define('OMPS_ROOT', dirname(__DIR__));
define('OMPS_START', microtime(true));

spl_autoload_register(function ($class) {
    $prefix = 'Omps\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = OMPS_ROOT . '/app/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require OMPS_ROOT . '/app/Helpers/functions.php';

Omps\Core\Env::load(OMPS_ROOT . '/.env');
if (getenv('OMPS_ENV_FILE')) {
    Omps\Core\Env::load((string) getenv('OMPS_ENV_FILE'), true);
}

date_default_timezone_set((string) config('app.timezone', 'Asia/Shanghai'));
mb_internal_encoding('UTF-8');

Omps\Core\ErrorHandler::register();
