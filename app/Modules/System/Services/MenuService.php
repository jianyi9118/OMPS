<?php
namespace Omps\Modules\System\Services;

use Omps\Database\DB;
use Omps\Engines\Permission;

/**
 * Left menu for a user: an item shows when the user has its permission
 * (device items also for device admins); a group shows when any child shows.
 */
class MenuService
{
    public static function forUser($user)
    {
        // order and names chosen in 菜单管理 come first (phase 9)
        $rows = DB::select('SELECT * FROM menus WHERE enabled = 1 ORDER BY COALESCE(user_sort, sort), sort, id');
        $byParent = array();
        foreach ($rows as $r) {
            $byParent[$r['parent_id'] === null ? 0 : (int) $r['parent_id']][] = $r;
        }
        $out = array();
        foreach (isset($byParent[0]) ? $byParent[0] : array() as $top) {
            $kids = array();
            foreach (isset($byParent[(int) $top['id']]) ? $byParent[(int) $top['id']] : array() as $k) {
                if (self::visible($user, $k)) {
                    $kids[] = self::item($k);
                }
            }
            if ($top['route'] !== null && $top['route'] !== '') {
                if (self::visible($user, $top)) {
                    $out[] = self::item($top);
                }
            } elseif ($kids) {
                $g = self::item($top);
                $g['children'] = $kids;
                $out[] = $g;
            }
        }
        return $out;
    }

    private static function visible($user, array $m)
    {
        // members have no workbench: they land on 我的任务 (design §17)
        if ((string) $m['code'] === 'dashboard' && !WorkbenchService::has($user) && Permission::can($user, 'plan.view')
            && class_exists('Omps\Modules\Plan\Services\PlanService')) {
            return false;
        }
        if ((int) $m['requires_device_admin'] === 1 && !$user['is_device_admin'] && !Permission::isSuper($user)) {
            if ($m['permission_code'] === null || !Permission::can($user, (string) $m['permission_code'])) {
                return false;
            }
        }
        if ($m['permission_code'] === null || $m['permission_code'] === '') {
            return true;
        }
        return Permission::can($user, (string) $m['permission_code']);
    }

    private static function item(array $m)
    {
        $custom = \Omps\Core\Lang::dbText('menu', (string) $m['code']);
        return array('code' => (string) $m['code'], 'title' => $custom !== null ? $custom : __((string) $m['title_key']), 'icon' => (string) $m['icon'],
            'route' => $m['route'] === null ? '' : (string) $m['route'], 'children' => array());
    }

    /** Title of the group and item matching a path (for the breadcrumb). */
    public static function locate(array $menu, $path)
    {
        foreach ($menu as $m) {
            if ($m['route'] !== '' && self::matches($m['route'], $path)) {
                return array('', $m['title']);
            }
            foreach ($m['children'] as $k) {
                if (self::matches($k['route'], $path)) {
                    return array($m['title'], $k['title']);
                }
            }
        }
        return array('', '');
    }

    public static function matches($route, $path)
    {
        if ($route === '/') {
            return $path === '/';
        }
        return $path === $route || strpos($path, $route . '/') === 0;
    }
}
