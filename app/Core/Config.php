<?php
namespace Omps\Core;

/**
 * Configuration from config/*.php, accessed with dot keys: config('app.name').
 */
class Config
{
    /** @var array */
    private static $items = array();

    public static function get($key, $default = null)
    {
        $parts = explode('.', (string) $key);
        $file = array_shift($parts);
        if (!array_key_exists($file, self::$items)) {
            $path = OMPS_ROOT . '/config/' . $file . '.php';
            self::$items[$file] = is_file($path) ? require $path : array();
        }
        $v = self::$items[$file];
        foreach ($parts as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) {
                return $default;
            }
            $v = $v[$p];
        }
        return $v;
    }

    public static function set($key, $value)
    {
        $parts = explode('.', (string) $key);
        $file = array_shift($parts);
        self::get($file . '.__probe');
        $ref = &self::$items[$file];
        foreach ($parts as $p) {
            if (!isset($ref[$p]) || !is_array($ref[$p])) {
                $ref[$p] = array();
            }
            $ref = &$ref[$p];
        }
        $ref = $value;
    }

    /** Forget cached config (used by tests after changing env). */
    public static function reset()
    {
        self::$items = array();
    }
}
