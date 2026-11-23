<?php
namespace Omps\Core;

/**
 * URL generation. With mod_rewrite: /omps/users. Without: /omps/index.php?r=/users
 */
class Url
{
    /** @var string|null */
    private static $base = null;
    /** @var bool|null */
    private static $rewrite = null;

    public static function basePath()
    {
        if (self::$base === null) {
            $cfg = (string) config('app.base_path', '');
            if ($cfg !== '') {
                self::$base = '/' . trim($cfg, '/');
            } else {
                $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
                $dir = $script === '' ? '' : rtrim(dirname($script), '/');
                self::$base = ($dir === '.' || $dir === '/') ? '' : $dir;
            }
            if (self::$base === '/') {
                self::$base = '';
            }
        }
        return self::$base;
    }

    public static function rewrite()
    {
        if (self::$rewrite === null) {
            $mode = (string) config('app.rewrite', 'auto');
            if ($mode === 'on') {
                self::$rewrite = true;
            } elseif ($mode === 'off') {
                self::$rewrite = false;
            } else {
                // public/.htaccess sets OMPS_REWRITE=1 only when mod_rewrite is loaded.
                self::$rewrite = !empty($_SERVER['OMPS_REWRITE']) || !empty($_SERVER['REDIRECT_OMPS_REWRITE']);
            }
        }
        return self::$rewrite;
    }

    /** For tests and the CLI. */
    public static function configure($base, $rewrite)
    {
        self::$base = $base;
        self::$rewrite = $rewrite;
    }

    public static function to($path = '/', array $query = array())
    {
        $path = '/' . ltrim((string) $path, '/');
        if (self::rewrite()) {
            $u = self::basePath() . $path;
            return $query ? $u . '?' . http_build_query($query) : $u;
        }
        $q = array_merge(array('r' => $path), $query);
        return self::basePath() . '/index.php?' . http_build_query($q);
    }

    public static function asset($path)
    {
        $rel = 'assets/' . ltrim((string) $path, '/');
        $file = OMPS_ROOT . '/public/' . $rel;
        $v = is_file($file) ? (string) filemtime($file) : '0';
        return self::basePath() . '/' . $rel . '?v=' . $v;
    }
}
