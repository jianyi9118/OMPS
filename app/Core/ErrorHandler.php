<?php
namespace Omps\Core;

/**
 * Error policy (design §01): in development and testing every notice, warning
 * and deprecation becomes an exception so it is fixed immediately. In production
 * they are logged and execution continues, so an upgrade never breaks a page
 * because of a new deprecation notice.
 */
class ErrorHandler
{
    public static function register()
    {
        error_reporting(E_ALL);
        $debug = (bool) config('app.debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false; // silenced with @
            }
            if (self::strict()) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            Logger::warning('PHP ' . self::severityName($severity) . ': ' . $message . ' at ' . $file . ':' . $line);
            return true;
        });

        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                Logger::error('Fatal: ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line']);
            }
        });
    }

    public static function strict()
    {
        $env = (string) config('app.env', 'production');
        return $env !== 'production';
    }

    public static function severityName($s)
    {
        $map = array(E_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_ERROR => 'User error', E_USER_WARNING => 'User warning',
            E_USER_NOTICE => 'User notice', E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'User deprecated', E_RECOVERABLE_ERROR => 'Recoverable error');
        return isset($map[$s]) ? $map[$s] : 'Error ' . $s;
    }
}
