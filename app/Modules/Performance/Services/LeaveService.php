<?php
namespace Omps\Modules\Performance\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Notify;
use Omps\Engines\Permission;
use Omps\Engines\WorkCalendar;

/**
 * Leave (whole days).
 *  - A person applies for their own leave (我的事务 → 我的请假, leave.apply). The leader who reviews
 *    the person's results approves or rejects it (审核与统计 → 请假, perf.audit, same scope, never
 *    oneself). People with nobody above them (whole-unit scope) are approved at once.
 *  - A leader may also record leave for their people directly; it is approved at once.
 *  - Only approved leave counts: no daily report is expected and no daily reminder is sent on a day of
 *    leave; a weekly to annual report is not expected when every workday of the period is leave.
 * Status: pending → approved | rejected; pending → withdrawn by the applicant.
 */
class LeaveService
{
    const KINDS = array('annual', 'sick', 'personal', 'comp', 'marriage', 'maternity', 'bereavement', 'other');
    const STATUSES = array('pending', 'approved', 'rejected', 'withdrawn');
    const MAX_DAYS = 366;

    /** [person_id => ['Y-m-d' => true]] for days of approved leave in [from, to]. */
    public static function days(array $personIds, $from, $to)
    {
        $out = array();
        if (!$personIds) {
            return $out;
        }
        try {
            foreach (array_chunk(array_values($personIds), 400) as $chunk) {
                $rows = DB::select("SELECT person_id, start_date, end_date FROM person_leaves WHERE status = 'approved' AND person_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                    AND start_date <= ? AND end_date >= ?', array_merge($chunk, array($to, $from)));
                foreach ($rows as $r) {
                    $s = max((string) $r['start_date'], $from);
                    $e = min((string) $r['end_date'], $to);
                    $ts = strtotime($s . ' 12:00:00');
                    for ($i = 0; $i <= self::MAX_DAYS && date('Y-m-d', $ts) <= $e; $i++) {
                        $out[(int) $r['person_id']][date('Y-m-d', $ts)] = true;
                        $ts = WorkCalendar::addDays($ts, 1);
                    }
                }
            }
        } catch (\Exception $e) {
            return array(); // before the migrations have run
        }
        return $out;
    }

    /** People whose leave the user handles: the review scope, not the user. */
    private static function peopleQuery($user)
    {
        $q = DB::table('personnel_profiles p')->active()->leftJoin('departments d', 'd.id', '=', 'p.dept_id');
        Permission::scopePersonnel($q, $user, 'p');
        if ($user['person_id'] !== null) {
            $q->where('p.id', '<>', (int) $user['person_id']);
        }
        return $q;
    }

    private static function workdays(WorkCalendar $cal, $start, $end)
    {
        $n = 0;
        $ts = strtotime($start . ' 12:00:00');
        for ($i = 0; $i <= self::MAX_DAYS && date('Y-m-d', $ts) <= $end; $i++) {
            if ($cal->isWorkday($ts)) {
                $n++;
            }
            $ts = WorkCalendar::addDays($ts, 1);
        }
        return $n;
    }

    private static function present(array $r, WorkCalendar $cal)
    {
        $own = (int) $r['created_by'] === (int) Notify::userOfPerson((int) $r['person_id']);
        return array('id' => (int) $r['id'], 'person_id' => (int) $r['person_id'], 'person' => (string) $r['person_name'], 'dept' => str_or_empty($r['dept_name']),
            'kind' => (string) $r['kind'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'], 'note' => str_or_empty($r['note']),
            'status' => (string) $r['status'], 'applied' => $own, 'comment' => str_or_empty($r['review_comment']),
            'reviewer' => str_or_empty($r['reviewer_name']), 'reviewed_at' => substr(str_or_empty($r['reviewed_at']), 0, 16),
            'days' => (int) round((strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400) + 1, 'workdays' => self::workdays($cal, (string) $r['start_date'], (string) $r['end_date']),
            'by' => str_or_empty($r['by_name']), 'at' => substr((string) $r['created_at'], 0, 16));
    }

    private static function listQuery()
    {
        return DB::table('person_leaves l')->join('personnel_profiles p', 'p.id', '=', 'l.person_id')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')
            ->leftJoin('users u', 'u.id', '=', 'l.created_by')->leftJoin('users ru', 'ru.id', '=', 'l.reviewed_by')->whereNull('p.deleted_at')
            ->select(array('l.*', 'p.name as person_name', 'd.name as dept_name', 'u.display_name as by_name', 'ru.display_name as reviewer_name'));
    }

    private static function filters($q, array $f)
    {
        if (!empty($f['to'])) {
            $q->where('l.start_date', '<=', $f['to']);
        }
        if (!empty($f['from'])) {
            $q->where('l.end_date', '>=', $f['from']);
        }
        if (!empty($f['kind']) && in_array($f['kind'], self::KINDS, true)) {
            $q->where('l.kind', $f['kind']);
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $q->where('l.status', $f['status']);
        }
        if (!empty($f['id'])) {
            $q->where('l.id', (int) $f['id']); // a jump to one record that is not on the first page
        }
        return $q;
    }

    /** Pending applications the user may decide. */
    public static function pendingCount($user)
    {
        if (!Permission::can($user, 'perf.audit')) {
            return 0;
        }
        try {
            $q = self::peopleQuery($user)->join('person_leaves l', 'l.person_id', '=', 'p.id')->where('l.status', 'pending');
            return $q->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ---------- leaders: the leave of my people ----------

    /** $f: from, to, kw, dept_id, person_id, kind, status ('' = all). */
    public static function index(Request $req, array $f, $page, $size)
    {
        $q = self::filters(self::listQuery(), $f);
        Permission::scopePersonnel($q, $req->user, 'p');
        if ($req->user['person_id'] !== null) {
            $q->where('p.id', '<>', (int) $req->user['person_id']);
        }
        if (!empty($f['dept_id'])) {
            $q->whereIn('p.dept_id', Permission::subtree((int) $f['dept_id']) ?: array(0));
        }
        if (!empty($f['person_id'])) {
            $q->where('p.id', (int) $f['person_id']);
        }
        $q->whereLikeAny(array('p.name', 'l.note'), isset($f['kw']) ? $f['kw'] : '');
        if (!empty($f['status']) && $f['status'] === 'pending') {
            $q->orderBy('l.created_at')->orderBy('l.id');
        } else {
            $q->orderBy('l.start_date', 'DESC')->orderBy('l.id', 'DESC');
        }
        $res = $q->paginate($page, $size);
        $cal = WorkCalendar::fromSettings();
        $rows = array();
        foreach ($res['rows'] as $r) {
            $rows[] = self::present($r, $cal);
        }
        $res['rows'] = $rows;
        $people = array();
        foreach (self::peopleQuery($req->user)->where('p.status', 'active')->select(array('p.id', 'p.name', 'p.dept_id'))->orderBy('p.name')->get() as $p) {
            $people[] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'dept_id' => int_or_null($p['dept_id']));
        }
        $res['people'] = $people;
        $res['depts'] = \Omps\Modules\Organization\Services\DeptService::options($req->user);
        $res['kinds'] = self::KINDS;
        $res['pending'] = self::pendingCount($req->user);
        return $res;
    }

    private static function validate(array $in, $withPerson)
    {
        if (isset($in['person_id']) && is_int($in['person_id'])) {
            $in['person_id'] = (string) $in['person_id'];
        }
        $rules = array('kind' => 'required|string', 'start' => 'required|date', 'end' => 'required|date', 'note' => 'nullable|string|max:200');
        if ($withPerson) {
            $rules['person_id'] = 'required|string';
        }
        $v = Validator::check($in, $rules, array('person_id' => 'leave.field.person', 'kind' => 'leave.field.kind', 'start' => 'leave.field.start', 'end' => 'leave.field.end', 'note' => 'leave.field.note'));
        if (!in_array($v['kind'], self::KINDS, true)) {
            throw ValidationException::field('kind', 'leave.err.kind');
        }
        if ($v['end'] < $v['start']) {
            throw ValidationException::field('end', 'leave.err.order');
        }
        if ((strtotime($v['end']) - strtotime($v['start'])) / 86400 + 1 > self::MAX_DAYS) {
            throw ValidationException::field('end', 'leave.err.too_long', array('n' => self::MAX_DAYS));
        }
        return $v;
    }

    /** The person of a leader's record: inside the scope, not the leader. */
    private static function scopedPerson($user, $id)
    {
        $p = ctype_digit((string) $id) ? self::peopleQuery($user)->where('p.id', (int) $id)->select(array('p.id', 'p.name', 'p.dept_id'))->first() : null;
        if (!$p) {
            throw ValidationException::field('person_id', 'leave.err.person');
        }
        return $p;
    }

    /** Pending or approved leave of the same person must not overlap. */
    private static function overlap($personId, $start, $end, $exceptId)
    {
        $sql = "SELECT start_date, end_date FROM person_leaves WHERE person_id = ? AND status IN ('pending','approved') AND start_date <= ? AND end_date >= ?" . ($exceptId ? ' AND id <> ?' : '');
        $p = array((int) $personId, $end, $start);
        if ($exceptId) {
            $p[] = (int) $exceptId;
        }
        $hit = DB::selectOne($sql, $p);
        if ($hit) {
            throw ValidationException::field('start', 'leave.err.overlap', array('start' => (string) $hit['start_date'], 'end' => (string) $hit['end_date']));
        }
    }

    /** A leave record of one of my people, else 404 (logged). */
    private static function target(Request $req, $id)
    {
        $r = self::peopleQuery($req->user)->join('person_leaves l', 'l.person_id', '=', 'p.id')->where('l.id', (int) $id)->select(array('l.*', 'p.name as person_name', 'p.dept_id'))->first();
        if (!$r) {
            Audit::denied($req, 'person_leave', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        return $r;
    }

    private static function data(array $v)
    {
        return array('kind' => $v['kind'], 'start_date' => $v['start'], 'end_date' => $v['end'], 'note' => isset($v['note']) && $v['note'] !== '' ? $v['note'] : null);
    }

    /** A leader records leave: approved at once. */
    public static function store(Request $req, array $in)
    {
        $v = self::validate($in, true);
        $p = self::scopedPerson($req->user, $v['person_id']);
        self::overlap((int) $p['id'], $v['start'], $v['end'], null);
        $id = DB::table('person_leaves')->insert(array_merge(self::data($v), array('org_id' => (int) config('app.org_id', 1), 'person_id' => (int) $p['id'], 'status' => 'approved',
            'reviewed_by' => $req->userId(), 'reviewed_at' => now(), 'created_at' => now(), 'created_by' => $req->userId())));
        Audit::op($req->user, 'perf', 'leave_added', 'person_leave', $id, (string) $p['name'], array('kind' => $v['kind'], 'start' => $v['start'], 'end' => $v['end']), $req->ip());
        return array('id' => $id);
    }

    public static function update(Request $req, $id, array $in)
    {
        $r = self::target($req, $id);
        $v = self::validate($in, true);
        $p = self::scopedPerson($req->user, $v['person_id']);
        if (in_array((string) $r['status'], array('pending', 'approved'), true)) {
            self::overlap((int) $p['id'], $v['start'], $v['end'], (int) $id);
        }
        DB::table('person_leaves')->where('id', (int) $id)->update(array_merge(self::data($v), array('person_id' => (int) $p['id'], 'updated_at' => now(), 'updated_by' => $req->userId())));
        list($b, $a) = Audit::diff(array('person' => (string) $r['person_name'], 'kind' => (string) $r['kind'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'], 'note' => str_or_empty($r['note'])),
            array('person' => (string) $p['name'], 'kind' => $v['kind'], 'start' => $v['start'], 'end' => $v['end'], 'note' => (string) (isset($v['note']) ? $v['note'] : '')));
        if ($a) {
            Audit::change($req, 'perf', 'leave_changed', 'person_leave', (int) $id, (string) $p['name'], $b, $a);
        }
        return array('ok' => true);
    }

    public static function destroy(Request $req, $id)
    {
        $r = self::target($req, $id);
        DB::table('person_leaves')->where('id', (int) $id)->delete();
        Notify::retract('person_leave', (int) $id, array('leave_applied'));
        Audit::op($req->user, 'perf', 'leave_deleted', 'person_leave', (int) $id, (string) $r['person_name'],
            array('kind' => (string) $r['kind'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'], 'status' => (string) $r['status']), $req->ip());
        return array('ok' => true);
    }

    /** Approve or reject a pending application; a reason is needed to reject. */
    public static function decide(Request $req, $id, $approve, array $in)
    {
        $r = self::target($req, $id);
        if ((string) $r['status'] !== 'pending') {
            throw HttpException::conflict('leave.err.not_pending');
        }
        $reason = null;
        if (!$approve) {
            $v = Validator::check($in, array('reason' => 'required|string|max:200'), array('reason' => 'leave.field.reason'));
            $reason = $v['reason'];
        } else {
            self::overlap((int) $r['person_id'], (string) $r['start_date'], (string) $r['end_date'], (int) $id);
        }
        $status = $approve ? 'approved' : 'rejected';
        $n = DB::table('person_leaves')->where('id', (int) $id)->where('status', 'pending')->update(array('status' => $status, 'reviewed_by' => $req->userId(), 'reviewed_at' => now(),
            'review_comment' => $reason, 'updated_at' => now(), 'updated_by' => $req->userId()));
        if ($n === 0) {
            throw HttpException::conflict('leave.err.not_pending');
        }
        Audit::change($req, 'perf', $approve ? 'leave_approved' : 'leave_rejected', 'person_leave', (int) $id, (string) $r['person_name'], array('status' => 'pending'),
            array_filter(array('status' => $status, 'reason' => $reason)));
        Notify::markRead($req->userId(), 'person_leave', (int) $id);
        Notify::retract('person_leave', (int) $id, array('leave_applied'));
        $uid = Notify::userOfPerson((int) $r['person_id']);
        if ($uid !== null) {
            Notify::send(array($uid), $approve ? 'leave_approved' : 'leave_rejected', array('by' => $req->user['name'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'],
                'kind' => array('t' => 'leave.kind.' . $r['kind']), 'reason' => (string) $reason), '/affairs/leave?id=' . (int) $id, 'person_leave', (int) $id, $req->userId(), $approve ? 'info' : 'warn');
        }
        return array('ok' => true, 'status' => $status);
    }

    // ---------- everyone: my own leave ----------

    private static function me(Request $req)
    {
        if ($req->user['person_id'] === null) {
            throw new HttpException(403, 'forbidden', 'leave.err.no_person');
        }
        return (int) $req->user['person_id'];
    }

    public static function mine(Request $req, array $f, $page, $size)
    {
        if ($req->user['person_id'] === null) {
            // an account without a personnel record (e.g. the system administrator) has no leave of its own
            return array('no_person' => true, 'rows' => array(), 'total' => 0, 'page' => 1, 'size' => $size, 'kinds' => self::KINDS, 'self_approves' => false);
        }
        $me = self::me($req);
        $q = self::filters(self::listQuery(), $f)->where('l.person_id', $me)->orderBy('l.start_date', 'DESC')->orderBy('l.id', 'DESC');
        $res = $q->paginate($page, $size);
        $cal = WorkCalendar::fromSettings();
        $rows = array();
        foreach ($res['rows'] as $r) {
            $rows[] = self::present($r, $cal);
        }
        $res['rows'] = $rows;
        $res['kinds'] = self::KINDS;
        $res['self_approves'] = PerformanceService::selfApproves($req->user);
        return $res;
    }

    private static function mineTarget(Request $req, $id)
    {
        $r = DB::table('person_leaves')->find((int) $id);
        if (!$r || (int) $r['person_id'] !== self::me($req)) {
            throw HttpException::notFound();
        }
        return $r;
    }

    /** Apply (or, while still pending, change) my leave; whole-unit roles are approved at once. */
    public static function apply(Request $req, array $in, $id = null)
    {
        $me = self::me($req);
        $v = self::validate($in, false);
        $r = $id ? self::mineTarget($req, $id) : null;
        if ($r && (string) $r['status'] !== 'pending') {
            throw HttpException::conflict('leave.err.not_pending');
        }
        self::overlap($me, $v['start'], $v['end'], $r ? (int) $r['id'] : null);
        $auto = PerformanceService::selfApproves($req->user);
        $data = self::data($v);
        if ($auto) {
            $data = array_merge($data, array('status' => 'approved', 'reviewed_by' => $req->userId(), 'reviewed_at' => now()));
        }
        $person = DB::table('personnel_profiles')->find($me);
        if ($r) {
            DB::table('person_leaves')->where('id', (int) $r['id'])->update(array_merge($data, array('updated_at' => now(), 'updated_by' => $req->userId())));
            $lid = (int) $r['id'];
            Notify::retract('person_leave', $lid, array('leave_applied'));
        } else {
            $lid = DB::table('person_leaves')->insert(array_merge(array('status' => 'pending'), $data, array('org_id' => (int) config('app.org_id', 1), 'person_id' => $me,
                'created_at' => now(), 'created_by' => $req->userId())));
        }
        Audit::op($req->user, 'perf', $r ? 'leave_changed' : 'leave_applied', 'person_leave', $lid, (string) $person['name'],
            array('kind' => $v['kind'], 'start' => $v['start'], 'end' => $v['end']), $req->ip());
        if (!$auto) {
            Notify::send(Notify::reviewersOf('perf.audit', $person), 'leave_applied', array('name' => (string) $person['name'], 'kind' => array('t' => 'leave.kind.' . $v['kind']),
                'start' => $v['start'], 'end' => $v['end']), '/performance/review?view=leaves&id=' . $lid, 'person_leave', $lid, $req->userId());
        }
        return array('id' => $lid, 'status' => $auto ? 'approved' : 'pending');
    }

    /** Withdraw my pending application. */
    public static function withdraw(Request $req, $id)
    {
        $r = self::mineTarget($req, $id);
        if ((string) $r['status'] !== 'pending' || DB::table('person_leaves')->where('id', (int) $r['id'])->where('status', 'pending')
                ->update(array('status' => 'withdrawn', 'updated_at' => now(), 'updated_by' => $req->userId())) === 0) {
            throw HttpException::conflict('leave.err.not_pending');
        }
        Notify::retract('person_leave', (int) $r['id'], array('leave_applied'));
        Audit::op($req->user, 'perf', 'leave_withdrawn', 'person_leave', (int) $r['id'], (string) $req->user['name'], null, $req->ip());
        return array('ok' => true);
    }
}
