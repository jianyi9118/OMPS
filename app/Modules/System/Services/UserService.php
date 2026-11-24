<?php
namespace Omps\Modules\System\Services;

use Omps\Core\Auth;
use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;

/**
 * User accounts. No default passwords: new accounts and resets get a random temporary
 * password shown once; the user must change it at first login.
 */
class UserService
{
    /** Users the actor may see: unit/all scope = everyone; dept scope = accounts of people in their departments. */
    private static function scoped($actor)
    {
        $q = DB::table('users u')->leftJoin('personnel_profiles p', 'p.id', '=', 'u.person_id')->join('roles r', 'r.id', '=', 'u.role_id')->whereNull('u.deleted_at');
        $ids = Permission::deptIds($actor);
        if ($ids !== null) {
            $q->whereIn('p.dept_id', $ids);
        }
        if (!Permission::isSuper($actor)) {
            $q->where('r.code', '<>', 'super');
        }
        return $q;
    }

    /** Keyword fields of the user list (the browser offers the same keys). */
    const SEARCH = array('username' => 'u.username', 'name' => array('u.display_name', 'p.name'), 'dept' => 'd.name');

    /** $f = role_id, status and the list filters (period on the day the account was created). */
    public static function search($actor, array $f, $page, $size)
    {
        $q = self::scoped($actor)->leftJoin('departments d', 'd.id', '=', 'p.dept_id')
            ->select(array('u.id', 'u.username', 'u.display_name', 'u.person_id', 'u.role_id', 'u.is_device_admin', 'u.status', 'u.locked_until', 'u.last_login_at',
                'u.must_change_password', 'r.code as role_code', 'r.is_builtin as role_builtin', 'p.name as person_name', 'd.name as dept_name'));
        $q->whereFilters($f, 'u.created_at', self::SEARCH);
        if (!empty($f['role_id'])) {
            $q->where('u.role_id', (int) $f['role_id']);
        }
        if (isset($f['status']) && in_array($f['status'], array('active', 'disabled', 'locked'), true)) {
            if ($f['status'] === 'locked') {
                $q->where('u.locked_until', '>', now());
            } else {
                $q->where('u.status', $f['status']);
            }
        }
        $res = $q->orderBy('u.id')->paginate($page, $size);
        foreach ($res['rows'] as &$r) {
            $r = self::present($r);
        }
        unset($r);
        return $res;
    }

    public static function present(array $r)
    {
        return array(
            'id' => (int) $r['id'], 'username' => (string) $r['username'],
            'name' => $r['person_name'] !== null ? (string) $r['person_name'] : (string) $r['display_name'],
            'display_name' => (string) $r['display_name'], 'person_id' => int_or_null($r['person_id']),
            'dept' => str_or_empty($r['dept_name']), 'role_id' => (int) $r['role_id'],
            'role' => RoleService::label($r['role_code'], (int) $r['role_builtin'] === 1), 'role_code' => (string) $r['role_code'],
            'device_admin' => (int) $r['is_device_admin'] === 1, 'status' => (string) $r['status'],
            'locked' => $r['locked_until'] !== null && strtotime((string) $r['locked_until']) > time(),
            'must_change' => (int) $r['must_change_password'] === 1,
            'last_login' => str_or_empty($r['last_login_at']),
        );
    }

    /** Load one user the actor may manage, else 404 (and log the attempt). */
    public static function manageable(Request $req, $id)
    {
        $row = self::scoped($req->user)->select(array('u.*', 'r.code as role_code', 'r.role_rank as role_rank'))->where('u.id', (int) $id)->first();
        if (!$row) {
            Audit::denied($req, 'user', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        if (!Permission::isSuper($req->user) && (int) $row['role_rank'] > $req->user['role_rank']) {
            Audit::denied($req, 'user', array('id' => (int) $id, 'reason' => 'rank'));
            throw HttpException::forbidden();
        }
        return $row;
    }

    private static function checkRole($actor, $roleId)
    {
        foreach (RoleService::assignable($actor) as $r) {
            if ($r['id'] === (int) $roleId) {
                return (int) $roleId;
            }
        }
        throw ValidationException::field('role_id', 'user.err.role_not_allowed');
    }

    private static function checkPerson(Request $req, $personId, $exceptUserId)
    {
        if ($personId === null) {
            return null;
        }
        $p = DB::table('personnel_profiles p')->active()->where('p.id', (int) $personId)->first();
        if (!$p || !Permission::personInScope($req->user, $p)) {
            throw ValidationException::field('person_id', 'user.err.person_not_found');
        }
        $q = DB::table('users')->active()->where('person_id', (int) $personId);
        if ($exceptUserId) {
            $q->where('id', '<>', (int) $exceptUserId);
        }
        if ($q->exists()) {
            throw ValidationException::field('person_id', 'user.err.person_has_account');
        }
        return $p;
    }

    public static function create(Request $req, array $in)
    {
        $v = Validator::check($in, array(
            'username' => array('required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.\-@]{3,50}$/'),
            'person_id' => 'nullable|int',
            'display_name' => 'nullable|string|max:50',
            'role_id' => 'required|int',
            'is_device_admin' => 'nullable|bool',
        ), array('username' => 'user.field.username', 'person_id' => 'user.field.person', 'display_name' => 'user.field.display_name', 'role_id' => 'user.field.role', 'is_device_admin' => 'user.field.device_admin'));
        $person = self::checkPerson($req, isset($v['person_id']) ? $v['person_id'] : null, 0);
        $display = $person ? (string) $person['name'] : (isset($v['display_name']) ? (string) $v['display_name'] : '');
        if ($display === '') {
            throw ValidationException::field('display_name', 'validation.required', array('field' => __('user.field.display_name')));
        }
        $roleId = self::checkRole($req->user, $v['role_id']);
        if (DB::table('users')->where('username', $v['username'])->exists()) {
            throw ValidationException::field('username', 'user.err.username_taken');
        }
        $temp = Auth::tempPassword();
        $id = DB::table('users')->insert(array(
            'org_id' => (int) config('app.org_id', 1), 'person_id' => $person ? (int) $person['id'] : null, 'username' => $v['username'], 'display_name' => $display,
            'password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'role_id' => $roleId, 'is_device_admin' => empty($v['is_device_admin']) ? 0 : 1,
            'status' => 'active', 'must_change_password' => 1, 'created_at' => now(), 'created_by' => $req->userId(),
        ));
        Audit::change($req, 'users', 'user_created', 'user', $id, $v['username'], null, array('role_id' => $roleId, 'person_id' => $person ? (int) $person['id'] : null, 'device_admin' => empty($v['is_device_admin']) ? 0 : 1));
        return array('id' => $id, 'temp_password' => $temp);
    }

    public static function update(Request $req, $id, array $in)
    {
        $row = self::manageable($req, $id);
        $v = Validator::check($in, array(
            'person_id' => 'nullable|int', 'display_name' => 'nullable|string|max:50', 'role_id' => 'required|int',
            'is_device_admin' => 'nullable|bool', 'status' => 'required|in:active,disabled',
        ), array('person_id' => 'user.field.person', 'display_name' => 'user.field.display_name', 'role_id' => 'user.field.role', 'is_device_admin' => 'user.field.device_admin', 'status' => 'user.field.status'));
        $self = (int) $id === $req->userId();
        if ($self && ((int) $v['role_id'] !== (int) $row['role_id'] || $v['status'] !== 'active')) {
            throw HttpException::bad('user.err.self_role_status');
        }
        $roleId = (int) $v['role_id'] === (int) $row['role_id'] ? (int) $row['role_id'] : self::checkRole($req->user, $v['role_id']);
        $person = self::checkPerson($req, isset($v['person_id']) ? $v['person_id'] : null, (int) $id);
        $display = $person ? (string) $person['name'] : (isset($v['display_name']) && $v['display_name'] !== '' ? (string) $v['display_name'] : (string) $row['display_name']);
        $upd = array('person_id' => $person ? (int) $person['id'] : null, 'display_name' => $display, 'role_id' => $roleId,
            'is_device_admin' => empty($v['is_device_admin']) ? 0 : 1, 'status' => $v['status'], 'updated_at' => now(), 'updated_by' => $req->userId());
        $before = array('person_id' => int_or_null($row['person_id']), 'display_name' => $row['display_name'], 'role_id' => (int) $row['role_id'], 'is_device_admin' => (int) $row['is_device_admin'], 'status' => $row['status']);
        DB::table('users')->where('id', (int) $id)->update($upd);
        if ($v['status'] === 'disabled' && $row['status'] !== 'disabled') {
            Auth::revokeAll($id, 'disabled');
        }
        unset($upd['updated_at'], $upd['updated_by']);
        list($b, $a) = Audit::diff($before, $upd);
        if ($a) {
            Audit::change($req, 'users', 'user_updated', 'user', $id, $row['username'], $b, $a);
        }
    }

    public static function resetPassword(Request $req, $id)
    {
        $row = self::manageable($req, $id);
        $temp = Auth::tempPassword();
        DB::table('users')->where('id', (int) $id)->update(array('password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'must_change_password' => 1,
            'failed_count' => 0, 'locked_until' => null, 'updated_at' => now(), 'updated_by' => $req->userId()));
        Auth::revokeAll($id, 'password_reset');
        Audit::change($req, 'users', 'password_reset', 'user', $id, $row['username'], null, null);
        return $temp;
    }

    public static function unlock(Request $req, $id)
    {
        $row = self::manageable($req, $id);
        DB::table('users')->where('id', (int) $id)->update(array('failed_count' => 0, 'locked_until' => null, 'updated_at' => now(), 'updated_by' => $req->userId()));
        Audit::change($req, 'users', 'user_unlocked', 'user', $id, $row['username'], null, null);
    }

    public static function forceLogout(Request $req, $id)
    {
        $row = self::manageable($req, $id);
        if ((int) $id === $req->userId()) {
            throw HttpException::bad('user.err.self_logout');
        }
        Auth::revokeAll($id, 'forced');
        Audit::change($req, 'users', 'force_logout', 'user', $id, $row['username'], null, null);
    }

    public static function delete(Request $req, $id)
    {
        $row = self::manageable($req, $id);
        if ((int) $id === $req->userId()) {
            throw HttpException::bad('user.err.self_delete');
        }
        // free the user name for reuse; the audit log keeps the original
        $newName = mb_substr((string) $row['username'], 0, 30) . '~del' . (int) $id;
        DB::table('users')->where('id', (int) $id)->update(array('deleted_at' => now(), 'deleted_by' => $req->userId(), 'username' => $newName, 'person_id' => null, 'status' => 'disabled'));
        Auth::revokeAll($id, 'deleted');
        Audit::change($req, 'users', 'user_deleted', 'user', $id, $row['username'], array('username' => $row['username'], 'person_id' => int_or_null($row['person_id'])), null);
    }

    /** People in the actor's scope who have no account yet (for the account form). */
    public static function personOptions(Request $req, $includeId = 0)
    {
        $q = DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()
            ->select(array('p.id', 'p.name', 'p.staff_no', 'd.name as dept_name'))->where('p.status', 'active');
        Permission::scopePersonnel($q, $req->user, 'p');
        $taken = DB::table('users')->active()->whereNotNull('person_id')->pluck('person_id');
        $out = array();
        foreach ($q->orderBy('p.name')->limit(2000)->get() as $p) {
            $pid = (int) $p['id'];
            if ($pid !== (int) $includeId && in_array($pid, array_map('intval', $taken), true)) {
                continue;
            }
            $out[] = array('id' => $pid, 'name' => (string) $p['name'], 'no' => (string) $p['staff_no'], 'dept' => str_or_empty($p['dept_name']));
        }
        return $out;
    }
}
