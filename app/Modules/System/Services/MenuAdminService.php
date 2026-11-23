<?php
namespace Omps\Modules\System\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;

/**
 * 菜单管理 (phase 9, super administrator): rename menu entries (a name for every enabled language,
 * kept in translations namespace 'menu'), change the order, hide entries. Which pages exist and who
 * may open them is fixed by the modules and the roles; a hidden entry is only left out of the menu.
 * Module updates keep these choices (menus.user_sort, menus.enabled, the names).
 */
class MenuAdminService
{
    /** Entries that cannot be hidden, so the menu can always be managed. */
    const LOCKED = array('dashboard', 'g-sys', 'sys-menus');

    private static function names($code)
    {
        $out = array();
        foreach (Lang::enabledCodes() as $l) {
            $t = Lang::dbText('menu', $code, $l);
            if ($t !== null) {
                $out[$l] = $t;
            }
        }
        return $out;
    }

    private static function present(array $m)
    {
        $names = self::names((string) $m['code']);
        $default = __((string) $m['title_key']);
        return array('code' => (string) $m['code'], 'title' => isset($names[Lang::locale()]) ? $names[Lang::locale()] : $default, 'default' => $default,
            'names' => $names, 'custom' => (bool) $names, 'enabled' => (int) $m['enabled'] === 1, 'route' => (string) $m['route'], 'icon' => (string) $m['icon'],
            'locked' => in_array((string) $m['code'], self::LOCKED, true), 'children' => array());
    }

    private static function ordered()
    {
        return DB::select('SELECT * FROM menus ORDER BY COALESCE(user_sort, sort), sort, id');
    }

    public static function tree()
    {
        $rows = self::ordered();
        $kids = array();
        foreach ($rows as $r) {
            if ($r['parent_id'] !== null) {
                $kids[(int) $r['parent_id']][] = $r;
            }
        }
        $out = array();
        foreach ($rows as $r) {
            if ($r['parent_id'] !== null) {
                continue;
            }
            $g = self::present($r);
            foreach (isset($kids[(int) $r['id']]) ? $kids[(int) $r['id']] : array() as $k) {
                $g['children'][] = self::present($k);
            }
            $out[] = $g;
        }
        $langs = array();
        foreach (Lang::languages() as $l) {
            if ((int) $l['enabled'] === 1) {
                $langs[] = array('code' => (string) $l['code'], 'name' => (string) $l['name']);
            }
        }
        return array('menu' => $out, 'languages' => $langs);
    }

    /**
     * Save the whole menu: groups and items in their new order, each with enabled and names
     * (names = [] or null: back to the built-in name; otherwise one for every enabled language).
     */
    public static function save(Request $req, array $in)
    {
        $groups = isset($in['menu']) && is_array($in['menu']) ? $in['menu'] : array();
        $byCode = array();
        foreach (DB::table('menus')->get() as $m) {
            $byCode[(string) $m['code']] = $m;
        }
        $langs = Lang::enabledCodes();
        $plan = array();
        $errors = array();
        $seen = array();
        $walk = function (array $list, $parentCode) use (&$walk, &$plan, &$errors, &$seen, $byCode, $langs) {
            foreach ($list as $i => $it) {
                $code = isset($it['code']) ? (string) $it['code'] : '';
                if (!isset($byCode[$code]) || isset($seen[$code])) {
                    throw HttpException::bad('menu.err.unknown');
                }
                $seen[$code] = true;
                $m = $byCode[$code];
                $parent = $m['parent_id'] === null ? null : (int) $m['parent_id'];
                $expected = $parentCode === null ? null : (int) $byCode[$parentCode]['id'];
                if ($parent !== $expected) {
                    throw HttpException::bad('menu.err.parent');
                }
                $enabled = !isset($it['enabled']) || (bool) $it['enabled'];
                if (!$enabled && in_array($code, self::LOCKED, true)) {
                    throw HttpException::conflict('menu.err.locked');
                }
                $names = isset($it['names']) && is_array($it['names']) ? array_filter(array_map(function ($v) {
                    return trim((string) $v);
                }, $it['names']), 'strlen') : array();
                if ($names) {
                    foreach ($langs as $l) {
                        if (!isset($names[$l])) {
                            $errors[$code] = __('menu.err.names');
                        }
                    }
                    foreach ($names as $l => $v) {
                        if (mb_strlen($v) > 30) {
                            $errors[$code] = __('validation.max', array('field' => $v, 'max' => 30));
                        }
                    }
                }
                $plan[] = array('code' => $code, 'sort' => ($i + 1) * 10, 'enabled' => $enabled, 'names' => $names);
                if (isset($it['children']) && is_array($it['children'])) {
                    $walk($it['children'], $code);
                }
            }
        };
        $walk($groups, null);
        if ($errors) {
            throw new ValidationException($errors);
        }
        $before = self::tree();
        $uid = $req->userId();
        DB::transaction(function () use ($plan, $langs, $uid) {
            foreach ($plan as $p) {
                DB::table('menus')->where('code', $p['code'])->update(array('user_sort' => $p['sort'], 'enabled' => $p['enabled'] ? 1 : 0));
                // names typed for a language still being prepared stay untouched
                $q = DB::table('translations')->where('namespace', 'menu')->where('tkey', $p['code']);
                if ($p['names']) {
                    $q->whereIn('lang', $langs);
                }
                $q->delete();
                foreach ($p['names'] as $l => $v) {
                    if (in_array($l, $langs, true)) {
                        DB::table('translations')->insert(array('namespace' => 'menu', 'tkey' => $p['code'], 'lang' => $l, 'text' => $v, 'updated_at' => now(), 'updated_by' => $uid));
                    }
                }
            }
        });
        Lang::clearCache();
        Audit::change($req, 'menu', 'menus_saved', 'menu', null, '', array('n' => count($before['menu'])), array('n' => count($plan)));
        return self::tree();
    }

    /** Back to the modules' order, names and visibility. */
    public static function reset(Request $req)
    {
        DB::transaction(function () {
            DB::statement('UPDATE menus SET user_sort = NULL, enabled = 1');
            DB::table('translations')->where('namespace', 'menu')->delete();
        });
        Lang::clearCache();
        Audit::change($req, 'menu', 'menus_reset', 'menu', null, '', null, null);
        return self::tree();
    }
}
