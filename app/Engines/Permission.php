<?php
namespace Omps\Engines;

use Omps\Database\DB;
use Omps\Database\QueryBuilder;

/**
 * Permission engine (design §05/§06).
 *  - Function permissions: role_permissions (codes like "staff.add"); super has everything.
 *  - Technical permissions (type = technical): super only, never grantable.
 *  - Device admin flag grants the device.* permissions.
 *  - Data scope comes from the role: all / unit (no restriction), dept (own department
 *    and its sub-groups), self (own record only).
 */
class Permission
{
    /** @var array role_id => [code => true] */
    private static $roleCache = array();
    /** @var array|null code => type */
    private static $types = null;

    public static function clearCache()
    {
        self::$roleCache = array();
        self::$types = null;
    }

    public static function isSuper($user)
    {
        return $user && $user['role_code'] === 'super';
    }

    public static function types()
    {
        if (self::$types === null) {
            self::$types = array();
            foreach (DB::table('permissions')->get() as $p) {
                self::$types[(string) $p['code']] = (string) $p['type'];
            }
        }
        return self::$types;
    }

    public static function roleCodes($roleId)
    {
        $roleId = (int) $roleId;
        if (!isset(self::$roleCache[$roleId])) {
            self::$roleCache[$roleId] = array();
            $rows = DB::select('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', array($roleId));
            foreach ($rows as $r) {
                self::$roleCache[$roleId][(string) $r['code']] = true;
            }
        }
        return self::$roleCache[$roleId];
    }

    public static function can($user, $code)
    {
        if (!$user) {
            return false;
        }
        if (self::isSuper($user)) {
            return true;
        }
        $types = self::types();
        if (!isset($types[$code])) {
            return false;
        }
        if ($types[$code] === 'technical') {
            return false;
        }
        if ($user['is_device_admin'] && strncmp($code, 'device.', 7) === 0) {
            return true;
        }
        $codes = self::roleCodes($user['role_id']);
        return isset($codes[$code]);
    }

    /** All codes the user holds (for the front end to show or hide buttons). */
    public static function codesFor($user)
    {
        $out = array();
        foreach (array_keys(self::types()) as $code) {
            if (self::can($user, $code)) {
                $out[] = $code;
            }
        }
        sort($out);
        return $out;
    }

    /** 'all' | 'unit' | 'dept' | 'self' */
    public static function scope($user)
    {
        if (self::isSuper($user)) {
            return 'all';
        }
        return (string) $user['role_scope'];
    }

    /**
     * Department ids the user may see: null = no restriction, array = these ids (may be empty).
     */
    public static function deptIds($user)
    {
        $scope = self::scope($user);
        if ($scope === 'all' || $scope === 'unit') {
            return null;
        }
        if ($scope === 'dept' && $user['dept_id']) {
            return self::subtree((int) $user['dept_id']);
        }
        return array();
    }

    /** Department and all its sub-groups (path based, no recursive SQL). */
    public static function subtree($deptId)
    {
        $d = DB::selectOne('SELECT id, path FROM departments WHERE id = ? AND deleted_at IS NULL', array((int) $deptId));
        if (!$d) {
            return array();
        }
        $ids = array((int) $d['id']);
        $like = addcslashes((string) $d['path'] . (int) $d['id'] . '/', '%_\\') . '%';
        foreach (DB::select('SELECT id FROM departments WHERE path LIKE ? AND deleted_at IS NULL', array($like)) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /** Restrict a personnel query to the user's data scope. */
    public static function scopePersonnel(QueryBuilder $q, $user, $alias = 'p')
    {
        $scope = self::scope($user);
        if ($scope === 'all' || $scope === 'unit') {
            return $q;
        }
        if ($scope === 'dept') {
            return $q->whereIn($alias . '.dept_id', self::deptIds($user));
        }
        return $q->where($alias . '.id', $user['person_id'] ? (int) $user['person_id'] : 0);
    }

    /** Is one person record inside the user's scope? */
    public static function personInScope($user, array $person)
    {
        $scope = self::scope($user);
        if ($scope === 'all' || $scope === 'unit') {
            return true;
        }
        if ($scope === 'dept') {
            return $person['dept_id'] !== null && in_array((int) $person['dept_id'], self::deptIds($user), true);
        }
        return $user['person_id'] !== null && (int) $person['id'] === (int) $user['person_id'];
    }
}
