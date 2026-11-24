<?php
namespace Omps\Modules\Organization\Services;

use Omps\Core\Auth;
use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;

/**
 * Basic personnel records (phase 1 fields) and assignment history.
 *
 * What a user may do follows the role permissions and the role's data scope (design v0.30):
 *  - staff.add  : add people (department scope: only into own department and its groups)
 *  - staff.edit : edit records; whole-unit scope may also transfer
 *  - staff.del  : department scope = remove from department (leave / retire / move out);
 *                 whole-unit scope = delete the record
 * Records outside the scope answer 404 and the attempt is written to the audit log.
 */
class StaffService
{
    public static function caps($user)
    {
        $scope = Permission::scope($user);
        $wide = $scope === 'unit' || $scope === 'all';
        return array(
            'add' => Permission::can($user, 'staff.add') && $scope !== 'self',
            'edit' => Permission::can($user, 'staff.edit') && $scope !== 'self',
            'transfer' => Permission::can($user, 'staff.edit') && $wide,
            'remove' => Permission::can($user, 'staff.del') && $scope === 'dept',
            'delete' => Permission::can($user, 'staff.del') && $wide,
            'scope' => $scope,
        );
    }

    private static function need($user, $cap)
    {
        $c = self::caps($user);
        if (empty($c[$cap])) {
            throw HttpException::forbidden();
        }
    }

    private static function base()
    {
        return DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()
            ->select(array('p.id', 'p.staff_no', 'p.name', 'p.gender', 'p.dept_id', 'p.position', 'p.phone', 'p.hire_date', 'p.status', 'p.unassigned', 'p.version', 'p.id_number_mask', 'd.name as dept_name'));
    }

    /** Keyword fields of the staff list (the browser offers the same keys); ID numbers are encrypted and never searched. */
    const SEARCH = array('staff_no' => 'p.staff_no', 'name' => 'p.name', 'dept' => 'd.name', 'position' => 'p.position', 'phone' => 'p.phone');

    /**
     * List filters on a staff query (p = personnel_profiles, d = departments): period on the hire date,
     * keyword in one field, or in all of them plus the searchable custom fields.
     */
    public static function filter($q, array $f)
    {
        $q->wherePeriod('p.hire_date', isset($f['from']) ? $f['from'] : '', isset($f['to']) ? $f['to'] : '');
        $kw = isset($f['kw']) ? (string) $f['kw'] : '';
        $field = isset($f['field']) ? (string) $f['field'] : '';
        if ($kw === '') {
            return $q;
        }
        if ($field !== '' && isset(self::SEARCH[$field])) {
            return $q->whereKeyword(self::SEARCH, $kw, $field);
        }
        return $q->whereLikeAnyOrIn(array_values(self::SEARCH), $kw, 'p.id', \Omps\Engines\CustomFields::matchIds('personnel', $kw));
    }

    public static function search(Request $req, array $f, $page, $size)
    {
        $q = self::base();
        Permission::scopePersonnel($q, $req->user, 'p');
        if (!empty($f['dept_id'])) {
            $want = Permission::subtree((int) $f['dept_id']);
            $allowed = Permission::deptIds($req->user);
            if ($allowed !== null) {
                if (!in_array((int) $f['dept_id'], $allowed, true)) {
                    Audit::denied($req, 'department', array('dept_id' => (int) $f['dept_id']));
                }
                $want = array_values(array_intersect($want, $allowed));
            }
            $q->whereIn('p.dept_id', $want);
        }
        self::filter($q, $f);
        if (isset($f['status']) && in_array($f['status'], array('active', 'disabled', 'left', 'retired'), true)) {
            $q->where('p.status', $f['status']);
        }
        if (!empty($f['unassigned'])) {
            $q->where('p.unassigned', 1);
        }
        $res = $q->orderBy('p.staff_no')->paginate($page, $size);
        foreach ($res['rows'] as &$r) {
            $r = self::present($r);
        }
        unset($r);
        // custom fields shown as list columns
        $res['custom_cols'] = \Omps\Engines\CustomFields::listDefs('personnel');
        if ($res['custom_cols']) {
            $vals = \Omps\Engines\CustomFields::values('personnel', array_column($res['rows'], 'id'));
            foreach ($res['rows'] as &$r) {
                $r['custom'] = isset($vals[$r['id']]) ? $vals[$r['id']] : array();
            }
            unset($r);
        }
        return $res;
    }

    public static function present(array $r)
    {
        return array('id' => (int) $r['id'], 'staff_no' => (string) $r['staff_no'], 'name' => (string) $r['name'], 'gender' => (string) $r['gender'],
            'dept_id' => int_or_null($r['dept_id']), 'dept' => str_or_empty($r['dept_name']), 'position' => str_or_empty($r['position']),
            'phone' => str_or_empty($r['phone']), 'hire_date' => str_or_empty($r['hire_date']), 'status' => (string) $r['status'],
            'unassigned' => (int) $r['unassigned'] === 1, 'version' => (int) $r['version'],
            'id_mask' => isset($r['id_number_mask']) ? str_or_empty($r['id_number_mask']) : '');
    }

    /** One record inside the user's scope, else 404 (logged). */
    public static function scoped(Request $req, $id)
    {
        $p = self::base()->where('p.id', (int) $id)->first();
        if (!$p || !Permission::personInScope($req->user, $p)) {
            Audit::denied($req, 'person', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        return $p;
    }

    private static function checkDept(Request $req, $deptId, $required)
    {
        if ($deptId === null || $deptId === 0) {
            if ($required) {
                throw ValidationException::field('dept_id', 'validation.required', array('field' => __('staff.field.dept')));
            }
            return null;
        }
        $d = DB::table('departments d')->active()->where('d.id', (int) $deptId)->first();
        $allowed = Permission::deptIds($req->user);
        if (!$d || ($allowed !== null && !in_array((int) $deptId, $allowed, true))) {
            throw ValidationException::field('dept_id', 'staff.err.dept_out_of_scope');
        }
        return (int) $deptId;
    }

    private static function rules()
    {
        return array(
            array('staff_no' => 'nullable|code', 'name' => 'required|string|max:50', 'gender' => 'required|in:male,female', 'dept_id' => 'nullable|int',
                'position' => 'nullable|string|max:100', 'phone' => array('nullable', 'string', 'regex:/^[0-9+\- ]{5,30}$/'), 'hire_date' => 'nullable|date'),
            array('staff_no' => 'staff.field.staff_no', 'name' => 'staff.field.name', 'gender' => 'staff.field.gender', 'dept_id' => 'staff.field.dept',
                'position' => 'staff.field.position', 'phone' => 'staff.field.phone', 'hire_date' => 'staff.field.hire_date'),
        );
    }

    private static function nextNo()
    {
        $n = (int) DB::value('SELECT COUNT(*) FROM personnel_profiles') + 1;
        do {
            $no = 'E' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (DB::table('personnel_profiles')->where('staff_no', $no)->exists());
        return $no;
    }

    public static function create(Request $req, array $in)
    {
        self::need($req->user, 'add');
        list($rules, $labels) = self::rules();
        $v = Validator::check($in, $rules, $labels);
        $deptId = self::checkDept($req, isset($v['dept_id']) ? $v['dept_id'] : null, Permission::scope($req->user) === 'dept');
        $no = !empty($v['staff_no']) ? (string) $v['staff_no'] : self::nextNo();
        if (DB::table('personnel_profiles')->where('staff_no', $no)->exists()) {
            throw ValidationException::field('staff_no', 'staff.err.no_taken');
        }
        $date = !empty($v['hire_date']) ? $v['hire_date'] : date('Y-m-d');
        $cfValues = \Omps\Engines\CustomFields::validate('personnel', isset($in['custom']) ? $in['custom'] : array());
        $id = DB::transaction(function () use ($req, $v, $deptId, $no, $date, $cfValues) {
            $id = DB::table('personnel_profiles')->insert(array(
                'org_id' => (int) config('app.org_id', 1), 'staff_no' => $no, 'name' => $v['name'], 'gender' => $v['gender'], 'dept_id' => $deptId,
                'position' => isset($v['position']) ? $v['position'] : null, 'phone' => isset($v['phone']) ? $v['phone'] : null,
                'hire_date' => isset($v['hire_date']) ? $v['hire_date'] : null, 'status' => 'active', 'created_at' => now(), 'created_by' => $req->userId(),
            ));
            DB::table('personnel_assignments')->insert(array('org_id' => (int) config('app.org_id', 1), 'person_id' => $id, 'dept_id' => $deptId, 'position' => isset($v['position']) ? $v['position'] : null,
                'change_type' => 'join', 'start_date' => $date, 'created_at' => now(), 'created_by' => $req->userId()));
            \Omps\Engines\CustomFields::save('personnel', $id, $cfValues);
            return $id;
        });
        Audit::change($req, 'staff', 'person_created', 'person', $id, $v['name'] . ' (' . $no . ')', null, array('dept_id' => $deptId, 'position' => isset($v['position']) ? $v['position'] : null));
        if (Permission::scope($req->user) === 'dept') {
            // a department head added someone: the unit head and the super admin (who opens accounts) are told
            \Omps\Engines\Notify::send(array_merge(\Omps\Engines\Notify::unitHeads(), \Omps\Engines\Notify::supers()), 'staff_added',
                array('by' => $req->user['name'], 'name' => (string) $v['name'], 'dept' => (string) DB::value('SELECT name FROM departments WHERE id = ?', array((int) $deptId))),
                '/org/staff?focus=' . $id, 'person', $id, $req->userId());
        }
        return $id;
    }

    public static function update(Request $req, $id, array $in)
    {
        self::need($req->user, 'edit');
        $p = self::scoped($req, $id);
        if (isset($in['version']) && (int) $in['version'] !== (int) $p['version']) {
            throw HttpException::conflict('error.stale');
        }
        list($rules, $labels) = self::rules();
        unset($rules['dept_id'], $rules['staff_no']);
        $v = Validator::check($in, $rules, $labels);
        $upd = array('name' => $v['name'], 'gender' => $v['gender'], 'position' => isset($v['position']) ? $v['position'] : null,
            'phone' => isset($v['phone']) ? $v['phone'] : null, 'hire_date' => isset($v['hire_date']) ? $v['hire_date'] : null);
        $before = array_intersect_key($p, $upd);
        $n = DB::table('personnel_profiles')->where('id', (int) $id)->where('version', (int) $p['version'])
            ->increment('version', 1, array_merge($upd, array('updated_at' => now(), 'updated_by' => $req->userId())));
        if ($n === 0) {
            throw HttpException::conflict('error.stale');
        }
        DB::table('users')->where('person_id', (int) $id)->update(array('display_name' => $v['name']));
        list($b, $a) = Audit::diff($before, $upd);
        if ($a) {
            Audit::change($req, 'staff', 'person_updated', 'person', $id, $v['name'] . ' (' . $p['staff_no'] . ')', $b, $a);
        }
    }

    /** Whole-unit scope: transfer, promote, leave, retire. */
    public static function transfer(Request $req, $id, array $in)
    {
        self::need($req->user, 'transfer');
        $p = self::scoped($req, $id);
        $v = Validator::check($in, array('type' => 'required|in:transfer,promote,leave,retire', 'dept_id' => 'nullable|int', 'position' => 'nullable|string|max:100',
            'date' => 'required|date', 'note' => 'nullable|string|max:500'),
            array('type' => 'staff.field.change_type', 'dept_id' => 'staff.field.dept', 'position' => 'staff.field.position', 'date' => 'staff.field.date', 'note' => 'staff.field.note'));
        $leaving = in_array($v['type'], array('leave', 'retire'), true);
        $deptId = $leaving ? int_or_null($p['dept_id']) : self::checkDept($req, isset($v['dept_id']) ? $v['dept_id'] : null, false);
        $position = $leaving ? $p['position'] : (isset($v['position']) ? $v['position'] : $p['position']);
        self::applyChange($req, $p, $v['type'], $deptId, $position, $v['date'], isset($v['note']) ? $v['note'] : null);
    }

    /** Department scope: remove a subordinate from the department (the record is kept). */
    public static function remove(Request $req, $id, array $in)
    {
        self::need($req->user, 'remove');
        $p = self::scoped($req, $id);
        if ($req->user['person_id'] !== null && (int) $p['id'] === (int) $req->user['person_id']) {
            throw HttpException::bad('staff.err.remove_self');
        }
        if ((string) $p['status'] !== 'active') {
            throw HttpException::bad('staff.err.not_active');
        }
        $v = Validator::check($in, array('type' => 'required|in:leave,retire,move_out', 'date' => 'required|date', 'note' => 'nullable|string|max:500'),
            array('type' => 'staff.field.reason', 'date' => 'staff.field.date', 'note' => 'staff.field.note'));
        self::applyChange($req, $p, $v['type'], $v['type'] === 'move_out' ? null : int_or_null($p['dept_id']), $p['position'], $v['date'], isset($v['note']) ? $v['note'] : null);
    }

    private static function applyChange(Request $req, array $p, $type, $deptId, $position, $date, $note)
    {
        $id = (int) $p['id'];
        $upd = array('updated_at' => now(), 'updated_by' => $req->userId());
        if ($type === 'leave' || $type === 'retire') {
            $upd['status'] = $type === 'leave' ? 'left' : 'retired';
        } elseif ($type === 'move_out') {
            $upd['dept_id'] = null;
            $upd['unassigned'] = 1;
        } else {
            $upd['dept_id'] = $deptId;
            $upd['position'] = $position;
            $upd['unassigned'] = 0;
        }
        DB::transaction(function () use ($req, $id, $p, $type, $deptId, $position, $date, $note, $upd) {
            DB::table('personnel_profiles')->where('id', $id)->increment('version', 1, $upd);
            DB::statement('UPDATE personnel_assignments SET end_date = ? WHERE person_id = ? AND end_date IS NULL', array($date, $id));
            DB::table('personnel_assignments')->insert(array('org_id' => (int) config('app.org_id', 1), 'person_id' => $id, 'dept_id' => $type === 'move_out' ? null : $deptId,
                'from_dept_id' => int_or_null($p['dept_id']), 'position' => $position, 'change_type' => $type, 'start_date' => $date,
                'end_date' => in_array($type, array('leave', 'retire'), true) ? $date : null, 'note' => $note, 'created_at' => now(), 'created_by' => $req->userId()));
            if ($type === 'leave' || $type === 'retire') {
                foreach (DB::table('users')->active()->where('person_id', $id)->pluck('id') as $uid) {
                    DB::table('users')->where('id', (int) $uid)->update(array('status' => 'disabled', 'updated_at' => now(), 'updated_by' => $req->userId()));
                    Auth::revokeAll((int) $uid, 'person_left');
                }
            }
        });
        $before = array('dept_id' => int_or_null($p['dept_id']), 'status' => $p['status'], 'position' => $p['position']);
        $after = array('dept_id' => array_key_exists('dept_id', $upd) ? $upd['dept_id'] : $before['dept_id'], 'status' => isset($upd['status']) ? $upd['status'] : $p['status'],
            'position' => isset($upd['position']) ? $upd['position'] : $p['position'], 'note' => $note);
        Audit::change($req, 'staff', 'person_' . $type, 'person', $id, $p['name'] . ' (' . $p['staff_no'] . ')', $before, $after);
        if ($type === 'move_out') {
            // the unit head arranges a new department (to-do "为 XX 安排部门" until transferred)
            \Omps\Engines\Notify::send(\Omps\Engines\Notify::unitHeads(), 'staff_moved_out', array('by' => $req->user['name'], 'name' => (string) $p['name'],
                'dept' => $p['dept_id'] === null ? '' : (string) DB::value('SELECT name FROM departments WHERE id = ?', array((int) $p['dept_id']))),
                '/org/staff?unassigned=1', 'person', $id, $req->userId(), 'warn');
        }
    }

    /**
     * What else changes when a record is deleted, shown in the confirmation before deleting:
     * active login accounts (disabled together) and departments the person heads (head cleared).
     */
    public static function deleteImpact(Request $req, $id)
    {
        self::need($req->user, 'delete');
        $p = self::scoped($req, $id);
        $accounts = DB::table('users')->active()->where('person_id', (int) $id)->where('status', 'active')->orderBy('id')->pluck('username');
        $leads = DB::table('departments d')->active()->where('d.leader_person_id', (int) $id)->orderBy('d.sort')->pluck('name');
        return array(
            'name' => (string) $p['name'], 'accounts' => $accounts, 'leads' => $leads,
            'self' => $req->user['person_id'] !== null && (int) $req->user['person_id'] === (int) $id,
            'can_handle_accounts' => Permission::can($req->user, 'users.edit'),
        );
    }

    /** Shown before removing someone from the department (design §7.2): unfinished plans and devices still held. */
    public static function removeImpact(Request $req, $id)
    {
        self::need($req->user, 'remove');
        $p = self::scoped($req, $id);
        $plans = (int) DB::value("SELECT COUNT(DISTINCT p.id) FROM plan_assignments a JOIN plans p ON p.id = a.plan_id
            WHERE a.person_id = ? AND p.deleted_at IS NULL AND p.status = 'active' AND p.end_date >= ?", array((int) $p['id'], date('Y-m-d')));
        $devices = DB::select("SHOW TABLES LIKE 'devices'") ? (int) DB::value("SELECT COUNT(*) FROM devices WHERE deleted_at IS NULL AND holder_person_id = ? AND run_status <> 'scrapped'",
            array((int) $p['id'])) : 0;
        return array('plans' => $plans, 'devices' => $devices);
    }

    public static function delete(Request $req, $id)
    {
        $impact = self::deleteImpact($req, $id);
        $p = self::scoped($req, $id);
        if ($impact['self']) {
            throw HttpException::bad('staff.err.delete_self');
        }
        if ($impact['accounts'] && !$impact['can_handle_accounts']) {
            throw HttpException::conflict('staff.err.has_active_account', array('name' => $p['name']));
        }
        DB::transaction(function () use ($req, $id, $p, $impact) {
            DB::table('personnel_profiles')->where('id', (int) $id)->update(array('deleted_at' => now(), 'deleted_by' => $req->userId(),
                'staff_no' => mb_substr((string) $p['staff_no'], 0, 40) . '~' . (int) $id));
            foreach (DB::table('users')->active()->where('person_id', (int) $id)->where('status', 'active')->pluck('id') as $uid) {
                DB::table('users')->where('id', (int) $uid)->update(array('status' => 'disabled', 'updated_at' => now(), 'updated_by' => $req->userId()));
                Auth::revokeAll((int) $uid, 'person_deleted');
            }
            DB::table('users')->where('person_id', (int) $id)->update(array('person_id' => null));
            if ($impact['leads']) {
                DB::table('departments')->where('leader_person_id', (int) $id)->update(array('leader_person_id' => null, 'updated_at' => now(), 'updated_by' => $req->userId()));
            }
        });
        Audit::change($req, 'staff', 'person_deleted', 'person', $id, $p['name'] . ' (' . $p['staff_no'] . ')',
            array('staff_no' => $p['staff_no'], 'dept_id' => int_or_null($p['dept_id'])),
            array('accounts_disabled' => $impact['accounts'], 'head_cleared' => $impact['leads']));
    }

    public static function history(Request $req, $id)
    {
        self::scoped($req, $id);
        $rows = DB::table('personnel_assignments a')->leftJoin('departments d', 'd.id', '=', 'a.dept_id')->leftJoin('departments f', 'f.id', '=', 'a.from_dept_id')
            ->select(array('a.id', 'a.change_type', 'a.start_date', 'a.end_date', 'a.position', 'a.note', 'd.name as dept_name', 'f.name as from_name'))
            ->where('a.person_id', (int) $id)->orderBy('a.start_date', 'DESC')->orderBy('a.id', 'DESC')->get();
        return array_map(array(__CLASS__, 'presentAssignment'), $rows);
    }

    public static function presentAssignment(array $r)
    {
        return array('id' => (int) $r['id'], 'type' => (string) $r['change_type'], 'date' => (string) $r['start_date'], 'end' => str_or_empty($r['end_date']),
            'position' => str_or_empty($r['position']), 'note' => str_or_empty($r['note']), 'dept' => str_or_empty($r['dept_name']),
            'from' => isset($r['from_name']) ? str_or_empty($r['from_name']) : '', 'person' => isset($r['person_name']) ? (string) $r['person_name'] : '',
            'by' => isset($r['by_name']) ? str_or_empty($r['by_name']) : '');
    }

    /** Keyword fields of the transfer records. */
    const TRANSFER_SEARCH = array('name' => 'p.name', 'from_dept' => 'f.name', 'to_dept' => 'd.name', 'position' => 'a.position', 'by' => 'u.display_name', 'note' => 'a.note');

    /** Assignment records the user may see (department scope: involving their departments); $f also has the list filters (period on the effective date). */
    public static function transfers(Request $req, array $f, $page, $size)
    {
        $q = DB::table('personnel_assignments a')->join('personnel_profiles p', 'p.id', '=', 'a.person_id')
            ->leftJoin('departments d', 'd.id', '=', 'a.dept_id')->leftJoin('departments f', 'f.id', '=', 'a.from_dept_id')->leftJoin('users u', 'u.id', '=', 'a.created_by')
            ->select(array('a.id', 'a.change_type', 'a.start_date', 'a.end_date', 'a.position', 'a.note', 'd.name as dept_name', 'f.name as from_name', 'p.name as person_name', 'u.display_name as by_name'))
            ->whereNull('p.deleted_at');
        $scope = Permission::scope($req->user);
        if ($scope === 'dept') {
            $ids = Permission::deptIds($req->user);
            if (!$ids) {
                $q->whereRaw('1 = 0');
            } else {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $q->whereRaw('a.dept_id IN (' . $in . ') OR a.from_dept_id IN (' . $in . ')', array_merge($ids, $ids));
            }
        } elseif ($scope === 'self') {
            $q->where('a.person_id', $req->user['person_id'] ? (int) $req->user['person_id'] : 0);
        }
        if (isset($f['type']) && $f['type'] !== '') {
            $q->where('a.change_type', $f['type']);
        }
        $q->whereFilters($f, 'a.start_date', self::TRANSFER_SEARCH);
        $res = $q->orderBy('a.start_date', 'DESC')->orderBy('a.id', 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'presentAssignment'), $res['rows']);
        return $res;
    }
}
