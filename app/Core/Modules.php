<?php
namespace Omps\Core;

/**
 * Module discovery: every app/Modules/<Name>/module.json
 * (code, version, order, depends). Routes, permissions and menus live in the module folder.
 */
class Modules
{
    /** @var array|null */
    private static $list = null;

    public static function all()
    {
        if (self::$list === null) {
            $out = array();
            $dirs = glob(OMPS_ROOT . '/app/Modules/*', GLOB_ONLYDIR);
            foreach ($dirs === false ? array() : $dirs as $dir) {
                $file = $dir . '/module.json';
                if (!is_file($file)) {
                    continue;
                }
                $raw = file_get_contents($file);
                $m = $raw === false ? null : json_decode($raw, true);
                if (!is_array($m) || !isset($m['code'])) {
                    throw new \RuntimeException('Invalid module.json: ' . $file);
                }
                $m['path'] = $dir;
                $m['name'] = basename($dir);
                $out[] = $m;
            }
            usort($out, function ($a, $b) {
                $oa = isset($a['order']) ? (int) $a['order'] : 100;
                $ob = isset($b['order']) ? (int) $b['order'] : 100;
                return $oa === $ob ? strcmp($a['code'], $b['code']) : ($oa < $ob ? -1 : 1);
            });
            self::$list = $out;
        }
        return self::$list;
    }

    /** Merge a per-module definition file (permissions.php, menus.php). */
    public static function collect($file)
    {
        $out = array();
        foreach (self::all() as $m) {
            $path = $m['path'] . '/' . $file;
            if (is_file($path)) {
                $items = require $path;
                foreach ((array) $items as $it) {
                    $it['module'] = $m['code'];
                    $out[] = $it;
                }
            }
        }
        return $out;
    }
}
