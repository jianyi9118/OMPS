<?php
namespace Omps\Core;

/**
 * Daily log files in storage/logs (not reachable by URL).
 */
class Logger
{
    public static function info($msg, array $ctx = array())
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function warning($msg, array $ctx = array())
    {
        self::write('WARNING', $msg, $ctx);
    }

    public static function error($msg, array $ctx = array())
    {
        self::write('ERROR', $msg, $ctx);
    }

    private static function write($level, $msg, array $ctx)
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $level . ' ' . $msg;
        if ($ctx) {
            $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        }
        @file_put_contents($dir . '/omps-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
