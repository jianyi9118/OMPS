<?php
namespace Omps\Core;

/**
 * Minimal .env loader (KEY=VALUE lines, # comments, optional quotes).
 */
class Env
{
    /** @var array */
    private static $vars = array();

    public static function load($file, $override = false)
    {
        if (!is_file($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            $len = strlen($val);
            if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            if ($override || !array_key_exists($key, self::$vars)) {
                self::$vars[$key] = $val;
            }
        }
    }

    public static function set($key, $value)
    {
        self::$vars[$key] = $value;
    }

    public static function get($key, $default = null)
    {
        if (array_key_exists($key, self::$vars)) {
            $v = self::$vars[$key];
        } else {
            $g = getenv($key);
            if ($g === false) {
                return $default;
            }
            $v = $g;
        }
        $l = strtolower((string) $v);
        if ($l === 'true') {
            return true;
        }
        if ($l === 'false') {
            return false;
        }
        if ($l === 'null') {
            return null;
        }
        return $v;
    }
}
