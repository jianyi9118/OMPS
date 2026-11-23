<?php
namespace Omps\Modules\System\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;

/**
 * Roles (design §06): 4 built-in roles that cannot be deleted, plus custom roles.
 * Custom role names are stored per enabled language in `translations` (namespace 'role').
 */
class RoleService
{
    const MATRIX_ACTIONS = array('view', 'add', 'edit', 'del', 'export', 'audit', 'assign', 'sensitive');

    public static function label($code, $builtin)
    {
        return $builtin ? __('role.' . $code) : Lang::db('role', (string) $code);
    }

    public static function find($id)
    {
        return DB::table('roles')->find((int) $id);
    }

    public static function all()
    {
        $roles = DB::table('roles')->orderBy('sort')->orderBy('id')->get();
        $counts = array();
        foreach (DB::select('SELECT role_id, COUNT(*) AS n FROM users WHERE deleted_at IS NULL GROUP BY role_id') as $r) {
            $counts[(int) $r['role_id']] = (int) $r['n'];
        }
        $out = array();
        foreach ($roles as $r) {
            $id = (int) $r['id'];
            $builtin = (int) $r['is_builtin'] === 1;
            $names = array();
            if (!$builtin) {
                foreach (DB::table('translations')->where('namespace', 'role')->where('tkey', $r['code'])->get() as $t) {
                    $names[(string) $t['lang']] = (string) $t['text'];
                }
            }
            $out[] = array(
                'id' => $id, 'code' => (string) $r['code'], 'builtin' => $builtin, 'name' => self::label($r['code'], $builtin), 'names' => $names,
                'scope' => (string) $r['scope'], 'rank' => (int) $r['role_rank'], 'description' => str_or_empty($r['description']),
                'users' => isset($counts[$id]) ? $counts[$id] : 0,
                'perms' => (string) $r['code'] === 'super' ? array('*') : array_keys(Permission::roleCodes($id)),
            );
        }
        return $out;
    }

    /** Permission matrix definition: groups (rows) x actions (columns); a cell exists only for defined codes. */
    public static function matrix()
    {
        $rows = DB::table('permissions')->whereIn('type', array('action', 'sensitive'))->orderBy('sort')->orderBy('id')->get();
        $groups = array();
        foreach ($rows as $p) {
            list($g, $a) = explode('.', (string) $p['code'], 2);
            if (!isset($groups[$g])) {
                $groups[$g] = array('group' => $g, 'title' => __('perm.group.' . $g), 'codes' => array());
            }
            $groups[$g]['codes'][$a] = (string) $p['code'];
        }
        $actions = array();
        foreach (self::MATRIX_ACTIONS as $a) {
            $actions[] = array('action' => $a, 'title' => __('perm.action.' . $a));
        }
        return array('groups' => array_values($groups), 'actions' => $actions);
    }

    /** Validate names: every enabled language, not empty, unique per language. */
    private static function checkNames(array $names, $exceptCode)
    {
        $errors = array();
        $clean = array();
        foreach (Lang::enabledCodes() as $code) {
            $v = isset($names[$code]) && is_string($names[$code]) ? trim($names[$code]) : '';
            if ($v === '') {
                $errors['name_' . $code] = __('role.err.name_required', array('lang' => LanguageService::name($code)));
                continue;
            }
            if (mb_strlen($v) > 30) {
                $errors['name_' . $code] = __('validation.max', array('field' => __('role.field.name'), 'max' => 30));
                continue;
            }
            foreach (self::all() as $r) {
                if ($r['code'] === $exceptCode) {
                    continue;
                }
                $other = $r['builtin'] ? Lang::get('role.' . $r['code'], array(), $code) : (isset($r['names'][$code]) ? $r['names'][$code] : '');
                if ($other !== '' && mb_strtolower($other) === mb_strtolower($v)) {
                    $errors['name_' . $code] = __('role.err.name_taken', array('name' => $v));
                }
            }
            $clean[$code] = $v;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    private static function saveNames($roleCode, array $names, $userId)
    {
        foreach ($names as $lang => $text) {
            $exists = DB::table('translations')->where('namespace', 'role')->where('tkey', $roleCode)->where('lang', $lang)->exists();
            if ($exists) {
                DB::table('translations')->where('namespace', 'role')->where('tkey', $roleCode)->where('lang', $lang)->update(array('text' => $text, 'updated_at' => now(), 'updated_by' => $userId));
            } else {
                DB::table('translations')->insert(array('namespace' => 'role', 'tkey' => $roleCode, 'lang' => $lang, 'text' => $text, 'updated_at' => now(), 'updated_by' => $userId));
            }
        }
        Lang::clearCache();
    }

    private static function checkScope($scope)
    {
        if (!in_array($scope, array('self', 'dept', 'unit'), true)) {
            throw ValidationException::field('scope', 'validation.in', array('field' => __('role.field.scope')));
        }
        return $scope;
    }

    public static function create(Request $req, array $in)
    {
        $template = self::find(isset($in['template_id']) ? (int) $in['template_id'] : 0);
        if (!$template) {
            throw ValidationException::field('template_id', 'validation.required', array('field' => __('role.field.template')));
        }
        $code = 'custom_' . bin2hex(random_bytes(5));
        $names = self::checkNames(isset($in['names']) && is_array($in['names']) ? $in['names'] : array(), $code);
        $scope = self::checkScope(isset($in['scope']) ? (string) $in['scope'] : 'self');
        $desc = isset($in['description']) ? mb_substr(trim((string) $in['description']), 0, 200) : '';
        $id = DB::transaction(function () use ($req, $code, $names, $scope, $desc, $template) {
            $rank = min(3, max(1, (int) $template['role_rank']));
            $id = DB::table('roles')->insert(array('code' => $code, 'name_key' => null, 'is_builtin' => 0, 'role_rank' => $rank, 'scope' => $scope,
                'description' => $desc, 'sort' => 100, 'created_at' => now(), 'created_by' => $req->userId()));
            self::saveNames($code, $names, $req->userId());
            if ((string) $template['code'] === 'super') {
                $codes = DB::table('permissions')->whereIn('type', array('action', 'sensitive'))->pluck('code');
            } else {
                $codes = array_keys(Permission::roleCodes((int) $template['id']));
            }
            self::writePerms($id, $codes);
            return $id;
        });
        Permission::clearCache();
        Audit::change($req, 'roles', 'role_created', 'role', $id, implode(' / ', $names), null, array('names' => $names, 'scope' => $scope, 'template' => $template['code']));
        return $id;
    }

    public static function update(Request $req, $id, array $in)
    {
        $r = self::find($id);
        if (!$r) {
            throw HttpException::notFound();
        }
        $builtin = (int) $r['is_builtin'] === 1;
        $upd = array('updated_at' => now(), 'updated_by' => $req->userId());
        $before = array('scope' => $r['scope'], 'description' => $r['description']);
        if ((string) $r['code'] !== 'super' && isset($in['scope'])) {
            $upd['scope'] = self::checkScope((string) $in['scope']);
        }
        if (isset($in['description'])) {
            $upd['description'] = mb_substr(trim((string) $in['description']), 0, 200);
        }
        $names = null;
        if (!$builtin) {
            $names = self::checkNames(isset($in['names']) && is_array($in['names']) ? $in['names'] : array(), (string) $r['code']);
        }
        DB::transaction(function () use ($id, $upd, $names, $r, $req) {
            DB::table('roles')->where('id', (int) $id)->update($upd);
            if ($names !== null) {
                self::saveNames((string) $r['code'], $names, $req->userId());
            }
        });
        Audit::change($req, 'roles', 'role_updated', 'role', $id, self::label($r['code'], $builtin), $before, array('scope' => isset($upd['scope']) ? $upd['scope'] : $r['scope'], 'description' => isset($upd['description']) ? $upd['description'] : $r['description'], 'names' => $names));
    }

    public static function setPermissions(Request $req, $id, array $codes)
    {
        $r = self::find($id);
        if (!$r) {
            throw HttpException::notFound();
        }
        if ((string) $r['code'] === 'super') {
            throw HttpException::bad('role.err.super_locked');
        }
        $valid = DB::table('permissions')->whereIn('type', array('action', 'sensitive'))->pluck('code');
        $codes = array_values(array_intersect($valid, array_map('strval', $codes)));
        $before = array_keys(Permission::roleCodes((int) $id));
        DB::transaction(function () use ($id, $codes) {
            DB::table('role_permissions')->where('role_id', (int) $id)->delete();
            self::writePerms((int) $id, $codes);
        });
        Permission::clearCache();
        sort($before);
        sort($codes);
        Audit::change($req, 'roles', 'permissions_changed', 'role', $id, self::label($r['code'], (int) $r['is_builtin'] === 1),
            array('removed' => array_values(array_diff($before, $codes))), array('added' => array_values(array_diff($codes, $before))));
    }

    private static function writePerms($roleId, array $codes)
    {
        if (!$codes) {
            return;
        }
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        foreach ($ids as $pid) {
            DB::table('role_permissions')->insert(array('role_id' => (int) $roleId, 'permission_id' => (int) $pid));
        }
    }

    public static function delete(Request $req, $id)
    {
        $r = self::find($id);
        if (!$r) {
            throw HttpException::notFound();
        }
        if ((int) $r['is_builtin'] === 1) {
            throw HttpException::bad('role.err.builtin_delete');
        }
        $users = DB::table('users')->active()->where('role_id', (int) $id)->orderBy('id')->pluck('display_name');
        if ($users) {
            throw HttpException::conflict('role.err.in_use', array('n' => count($users), 'names' => implode(__('common.list_sep'), array_slice($users, 0, 10))));
        }
        $label = self::label($r['code'], false);
        DB::transaction(function () use ($id, $r) {
            DB::table('role_permissions')->where('role_id', (int) $id)->delete();
            DB::table('translations')->where('namespace', 'role')->where('tkey', (string) $r['code'])->delete();
            DB::table('roles')->where('id', (int) $id)->delete();
        });
        Lang::clearCache();
        Audit::change($req, 'roles', 'role_deleted', 'role', $id, $label, array('code' => $r['code']), null);
    }

    /** Roles a user may assign to others: never above their own rank; only super assigns super. */
    public static function assignable($actor)
    {
        $out = array();
        foreach (self::all() as $r) {
            if ($r['code'] === 'super' && !Permission::isSuper($actor)) {
                continue;
            }
            if (!Permission::isSuper($actor) && $r['rank'] > $actor['role_rank']) {
                continue;
            }
            $out[] = array('id' => $r['id'], 'name' => $r['name'], 'code' => $r['code']);
        }
        return $out;
    }
}
