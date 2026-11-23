<?php
namespace Omps\Core;

use Omps\Database\DB;

/**
 * Key/value settings in system_settings (values JSON-encoded), with defaults from config.
 */
class Settings
{
    /** @var array|null */
    private static $cache = null;

    public static function defaults()
    {
        return array_merge(array(
            'unit_name' => '',
            'unit_address' => '',
            'unit_phone' => '',
        ), (array) config('security.defaults', array()));
    }

    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = self::defaults();
            try {
                foreach (DB::table('system_settings')->where('org_id', (int) config('app.org_id', 1))->get() as $r) {
                    $v = json_decode((string) $r['svalue'], true);
                    self::$cache[(string) $r['skey']] = $v;
                }
            } catch (\Exception $e) {
                // database not ready yet (installation); defaults apply
            }
        }
        return self::$cache;
    }

    public static function get($key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function int($key)
    {
        return (int) self::get($key, 0);
    }

    public static function set($key, $value, $userId = null)
    {
        $org = (int) config('app.org_id', 1);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $exists = DB::table('system_settings')->where('org_id', $org)->where('skey', $key)->exists();
        if ($exists) {
            DB::table('system_settings')->where('org_id', $org)->where('skey', $key)->update(array('svalue' => $json, 'updated_at' => now(), 'updated_by' => $userId));
        } else {
            DB::table('system_settings')->insert(array('org_id' => $org, 'skey' => $key, 'svalue' => $json, 'updated_at' => now(), 'updated_by' => $userId));
        }
        self::$cache = null;
    }

    public static function clearCache()
    {
        self::$cache = null;
    }
}
