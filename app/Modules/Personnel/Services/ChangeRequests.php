<?php
namespace Omps\Modules\Personnel\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Notify;
use Omps\Engines\Permission;

/**
 * Changes people submit to their own records, waiting for review (design §06: fixed chain).
 * A reviewer is anyone with staff.audit whose data scope covers the person, except the person
 * themself: a member's change goes to their department head (and the unit head); a department
 * head's change goes to the unit head. Nothing changes in the record until it is approved.
 */
class ChangeRequests
{
    public static function create($personId, $entity, $recordId, $action, $before, array $after, $userId)
    {
        $id = DB::table('profile_change_requests')->insert(array(
            'org_id' => (int) config('app.org_id', 1), 'person_id' => (int) $personId, 'entity' => $entity, 'record_id' => $recordId,
            'action' => $action, 'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'submitted_by' => (int) $userId, 'submitted_at' => now(),
        ));
        $p = DB::table('personnel_profiles')->find((int) $personId);
        if ($p) {
            Notify::send(Notify::reviewersOf('staff.audit', $p), 'change_submitted', array('name' => (string) $p['name']), '/org/staff?tab=review',
                'change_request', $id, (int) $userId);
        }
        return $id;
    }

    /** Tell the person the result of their request. */
    private static function tellPerson(Request $req, array $r, array $p, $type, array $extra = array())
    {
        Notify::markRead($req->userId(), 'change_request', (int) $r['id']);
        Notify::retract('change_request', (int) $r['id'], array('change_submitted', 'remind_change'));
        $uid = Notify::userOfPerson((int) $p['id']);
        if ($uid !== null) {
            Notify::send(array($uid), $type, array_merge(array('by' => $req->user['name']), $extra), '/me/profile', 'change_request', (int) $r['id'], $req->userId(),
                $type === 'change_returned' ? 'warn' : 'info');
        }
    }

    private static function present(array $r)
    {
        $after = json_decode((string) $r['after_data'], true);
        return array(
            'id' => (int) $r['id'], 'person_id' => (int) $r['person_id'], 'person' => isset($r['person_name']) ? (string) $r['person_name'] : '',
            'dept' => isset($r['dept_name']) ? str_or_empty($r['dept_name']) : '', 'entity' => (string) $r['entity'], 'action' => (string) $r['action'],
            'before' => $r['before_data'] === null ? null : json_decode((string) $r['before_data'], true),
            'after' => is_array($after) && isset($after['display']) ? $after['display'] : null,
            'status' => (string) $r['status'], 'submitted_at' => (string) $r['submitted_at'], 'reviewed_at' => str_or_empty($r['reviewed_at']),
            'reviewer' => isset($r['reviewer_name']) ? str_or_empty($r['reviewer_name']) : '', 'comment' => str_or_empty($r['review_comment']),
        );
    }

    private static function base()
    {
        return DB::table('profile_change_requests c')->join('personnel_profiles p', 'p.id', '=', 'c.person_id')
            ->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->leftJoin('users ru', 'ru.id', '=', 'c.reviewed_by')
            ->select(array('c.*', 'p.name as person_name', 'd.name as dept_name', 'ru.display_name as reviewer_name'))
            ->whereNull('p.deleted_at');
    }

    public static function forPerson($personId)
    {
        $rows = self::base()->where('c.person_id', (int) $personId)->where('c.status', 'pending')->orderBy('c.id')->get();
        return array_map(array(__CLASS__, 'present'), $rows);
    }

    /** Requests the user may review (status filter: pending by default). */
    public static function reviewable(Request $req, $status, $page, $size)
    {
        if (!Permission::can($req->user, 'staff.audit')) {
            throw HttpException::forbidden();
        }
        $q = self::base();
        Permission::scopePersonnel($q, $req->user, 'p');
        if ($req->user['person_id'] !== null) {
            $q->where('c.person_id', '<>', (int) $req->user['person_id']);
        }
        $q->where('c.status', in_array($status, array('pending', 'approved', 'returned', 'withdrawn'), true) ? $status : 'pending');
        $res = $q->orderBy('c.id', $status === 'pending' ? 'ASC' : 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'present'), $res['rows']);
        return $res;
    }

    public static function mine(Request $req, $page, $size)
    {
        $pid = $req->user['person_id'];
        $q = self::base()->where('c.person_id', $pid === null ? 0 : (int) $pid);
        $res = $q->orderBy('c.id', 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'present'), $res['rows']);
        return $res;
    }

    private static function loadForReview(Request $req, $id)
    {
        $r = DB::table('profile_change_requests')->find($id);
        if (!$r || !Permission::can($req->user, 'staff.audit')) {
            throw HttpException::notFound();
        }
        $p = DB::table('personnel_profiles p')->active()->where('p.id', (int) $r['person_id'])->first();
        $own = $req->user['person_id'] !== null && (int) $req->user['person_id'] === (int) $r['person_id'];
        if (!$p || $own || !Permission::personInScope($req->user, $p)) {
            Audit::denied($req, 'change_request', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        if ((string) $r['status'] !== 'pending') {
            throw HttpException::conflict('review.err.not_pending');
        }
        return array($r, $p);
    }

    /** Approve one or more requests; each is applied to the record in its own transaction. */
    public static function approve(Request $req, array $ids)
    {
        $done = 0;
        $failed = array();
        foreach ($ids as $id) {
            try {
                list($r, $p) = self::loadForReview($req, (int) $id);
                $after = json_decode((string) $r['after_data'], true);
                $cols = is_array($after) && isset($after['columns']) && is_array($after['columns']) ? $after['columns'] : array();
                $uid = $req->userId();
                DB::transaction(function () use ($r, $p, $cols, $uid) {
                    if ((string) $r['entity'] === 'profile') {
                        if (isset($cols['id_number_hash']) && $cols['id_number_hash'] !== null
                            && DB::table('personnel_profiles q')->active()->where('q.id_number_hash', $cols['id_number_hash'])->where('q.id', '<>', (int) $p['id'])->exists()) {
                            throw HttpException::conflict('staff.err.id_taken');
                        }
                        DB::table('personnel_profiles')->where('id', (int) $p['id'])->increment('version', 1, array_merge($cols, array('updated_at' => now(), 'updated_by' => $uid)));
                    } else {
                        PersonnelService::applyEntity((string) $r['entity'], (int) $p['id'], (string) $r['action'], int_or_null($r['record_id']), $cols, $uid);
                    }
                    DB::table('profile_change_requests')->where('id', (int) $r['id'])->update(array('status' => 'approved', 'reviewed_by' => $uid, 'reviewed_at' => now()));
                });
                Audit::change($req, 'staff', 'change_approved', $r['entity'] === 'profile' ? 'person' : 'person_' . $r['entity'], $p['id'],
                    $p['name'] . ' (' . $p['staff_no'] . ')', json_decode((string) $r['before_data'], true), is_array($after) && isset($after['display']) ? $after['display'] : null);
                self::tellPerson($req, $r, $p, 'change_approved');
                $done++;
            } catch (HttpException $e) {
                $failed[] = array('id' => (int) $id, 'message' => __($e->getMessage(), $e->params));
            }
        }
        return array('approved' => $done, 'failed' => $failed);
    }

    public static function returnRequest(Request $req, $id, $comment)
    {
        $comment = trim((string) $comment);
        if ($comment === '') {
            throw \Omps\Core\ValidationException::field('comment', 'review.err.reason_required');
        }
        list($r, $p) = self::loadForReview($req, $id);
        DB::table('profile_change_requests')->where('id', (int) $id)->update(array('status' => 'returned', 'reviewed_by' => $req->userId(), 'reviewed_at' => now(),
            'review_comment' => mb_substr($comment, 0, 500)));
        Audit::change($req, 'staff', 'change_returned', 'person', $p['id'], $p['name'] . ' (' . $p['staff_no'] . ')', null, array('reason' => $comment));
        self::tellPerson($req, $r, $p, 'change_returned', array('reason' => mb_substr($comment, 0, 500)));
    }

    /** The submitter can withdraw a request that has not been reviewed yet. */
    public static function withdraw(Request $req, $id)
    {
        $r = DB::table('profile_change_requests')->find($id);
        if (!$r || $req->user['person_id'] === null || (int) $r['person_id'] !== (int) $req->user['person_id']) {
            throw HttpException::notFound();
        }
        if ((string) $r['status'] !== 'pending') {
            throw HttpException::conflict('review.err.not_pending');
        }
        DB::table('profile_change_requests')->where('id', (int) $id)->update(array('status' => 'withdrawn', 'reviewed_at' => now()));
        Notify::retract('change_request', (int) $id, array('change_submitted', 'remind_change'));
        Audit::op($req->user, 'staff', 'change_withdrawn', 'person', (int) $r['person_id'], null, null, $req->ip());
    }

    public static function pendingCount(Request $req)
    {
        if (!Permission::can($req->user, 'staff.audit')) {
            return 0;
        }
        $q = DB::table('profile_change_requests c')->join('personnel_profiles p', 'p.id', '=', 'c.person_id')->whereNull('p.deleted_at')->where('c.status', 'pending');
        Permission::scopePersonnel($q, $req->user, 'p');
        if ($req->user['person_id'] !== null) {
            $q->where('c.person_id', '<>', (int) $req->user['person_id']);
        }
        return $q->count();
    }
}
