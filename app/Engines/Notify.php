<?php
namespace Omps\Engines;

use Omps\Core\Auth;
use Omps\Database\DB;

/**
 * Notifications (design §13.1). Every newly received message goes to the recipient's bell.
 *
 *  - Text is never stored: type (= language key notify.msg.<type>) + params are rendered in the
 *    viewer's language, so old messages follow a language switch.
 *  - Each message carries a link to the right page, sub-page and record (design §17 跳转定位).
 *  - Scheduled reminders pass a dedupe key; the same trigger point never produces a second message.
 *  - Nobody is notified about their own action.
 */
class Notify
{
    /** Every message type (language key notify.msg.<type>); i18n:check verifies each has a text. */
    const TYPES = array(
        'perf_submitted', 'perf_approved', 'perf_edit_approved', 'perf_returned', 'change_submitted', 'change_approved', 'change_returned',
        'staff_added', 'staff_moved_out', 'note_submitted', 'note_read', 'note_replied',
        'issue_sent_tip', 'issue_sent_doc', 'issue_sent_order', 'issue_nudge', 'issue_withdrawn', 'issue_done', 'issue_done_late', 'issue_overdue_list',
        'remind_issue_unread', 'remind_issue_due', 'remind_issue_overdue', 'remind_issue_interval', 'remind_perf_daily', 'remind_perf_period', 'remind_review', 'remind_change',
        'device_fault_reported', 'device_fault_assigned', 'device_fault_fixed', 'device_fault_cancelled', 'device_anomaly', 'remind_license',
        'leave_applied', 'leave_approved', 'leave_rejected',
        'missing_translation',
    );
    const LEVELS = array('info', 'warn', 'urgent');

    /** @var array|null active users, normalized like the signed-in user */
    private static $users = null;

    public static function clearCache()
    {
        self::$users = null;
    }

    /**
     * @param int[]  $userIds recipients
     * @param string $type    notify.msg.<type>
     * @param array  $params  placeholders; a value {"t": key} is itself translated
     * @param string|null $dedupe base key for scheduled reminders (the recipient is added)
     * @return int number of messages written
     */
    public static function send(array $userIds, $type, array $params, $link, $bizType = null, $bizId = null, $actorId = null, $level = 'info', $dedupe = null)
    {
        $n = 0;
        $done = array();
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0 || isset($done[$uid]) || ($actorId !== null && $uid === (int) $actorId)) {
                continue;
            }
            $done[$uid] = true;
            $key = $dedupe === null ? null : sha1($dedupe . ':user#' . $uid);
            if ($key !== null && DB::table('notifications')->where('dedupe_key', $key)->exists()) {
                continue;
            }
            try {
                DB::table('notifications')->insert(array(
                    'org_id' => (int) config('app.org_id', 1), 'user_id' => $uid, 'type' => $type, 'level' => $level,
                    'params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null, 'link' => $link === null ? null : substr((string) $link, 0, 191),
                    'biz_type' => $bizType, 'biz_id' => $bizId === null ? null : (int) $bizId, 'actor_id' => $actorId === null ? null : (int) $actorId,
                    'dedupe_key' => $key, 'created_at' => now(),
                ));
                $n++;
            } catch (\PDOException $e) {
                if ($key === null || (string) $e->getCode() !== '23000') {
                    throw $e;
                }
                // written by a parallel run in the meantime
            }
        }
        return $n;
    }

    /** Remove messages that no longer apply and have not been read (e.g. a withdrawn submission). */
    public static function retract($bizType, $bizId, array $types, $userIds = null)
    {
        $q = DB::table('notifications')->where('biz_type', $bizType)->where('biz_id', (int) $bizId)->whereIn('type', $types)->whereNull('read_at');
        if ($userIds !== null) {
            if (!$userIds) {
                return 0;
            }
            $q->whereIn('user_id', array_map('intval', $userIds));
        }
        return $q->delete();
    }

    /** Opening a record marks the user's messages about it as read. */
    public static function markRead($userId, $bizType, $bizId)
    {
        return DB::table('notifications')->where('user_id', (int) $userId)->where('biz_type', $bizType)->where('biz_id', (int) $bizId)->whereNull('read_at')
            ->update(array('read_at' => now()));
    }

    // ---------- who receives ----------

    /** Active accounts (status active, not deleted), normalized like the signed-in user. */
    public static function users()
    {
        if (self::$users === null) {
            self::$users = array();
            foreach (DB::select(Auth::userQuery() . " WHERE u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.id") as $r) {
                $u = Auth::normalize($r);
                self::$users[$u['id']] = $u;
            }
        }
        return self::$users;
    }

    public static function user($id)
    {
        $all = self::users();
        return isset($all[(int) $id]) ? $all[(int) $id] : null;
    }

    /** Active account of a person (the first one when there are several), or null. */
    public static function userOfPerson($personId)
    {
        if ($personId === null) {
            return null;
        }
        foreach (self::users() as $u) {
            if ($u['person_id'] !== null && $u['person_id'] === (int) $personId) {
                return $u['id'];
            }
        }
        return null;
    }

    /**
     * Who reviews a person's submissions (fixed chain, design §06): accounts with $perm whose
     * scope covers the person, never the person, never the technical super admin. The closest
     * level is told: department heads when there are any, otherwise the whole-unit roles.
     */
    public static function reviewersOf($perm, array $person)
    {
        $dept = array();
        $unit = array();
        foreach (self::users() as $u) {
            if (Permission::isSuper($u) || ($u['person_id'] !== null && $u['person_id'] === (int) $person['id']) || !Permission::can($u, $perm)) {
                continue;
            }
            if (!Permission::personInScope($u, array('id' => (int) $person['id'], 'dept_id' => int_or_null($person['dept_id'])))) {
                continue;
            }
            if (Permission::scope($u) === 'dept') {
                $dept[] = $u['id'];
            } elseif (Permission::scope($u) === 'unit') {
                $unit[] = $u['id'];
            }
        }
        return $dept ? $dept : $unit;
    }

    /** Accounts ticked "兼任设备管理员"; when there are none, the unit heads look after devices. */
    public static function deviceAdmins()
    {
        $out = array();
        foreach (self::users() as $u) {
            if ($u['is_device_admin']) {
                $out[] = $u['id'];
            }
        }
        return $out ? $out : self::unitHeads();
    }

    /** Whole-unit leaders (role scope "unit"). */
    public static function unitHeads()
    {
        $out = array();
        foreach (self::users() as $u) {
            if (!Permission::isSuper($u) && Permission::scope($u) === 'unit') {
                $out[] = $u['id'];
            }
        }
        return $out;
    }

    public static function supers()
    {
        $out = array();
        foreach (self::users() as $u) {
            if (Permission::isSuper($u)) {
                $out[] = $u['id'];
            }
        }
        return $out;
    }

    /**
     * The superior a user reports to (notebook, design §10.7), decided by the system:
     * member → head of their department (nearest department up the tree with a head who has an
     * account); department head → unit head; whole-unit roles have nobody above them.
     * When several unit heads exist, the first account is used.
     */
    public static function superiorOf(array $user)
    {
        $scope = Permission::scope($user);
        if ($scope === 'all' || $scope === 'unit') {
            return null;
        }
        if ($scope === 'self' && $user['dept_id'] !== null) {
            $deptId = (int) $user['dept_id'];
            $guard = 0;
            while ($deptId && $guard++ < 20) {
                $d = DB::selectOne('SELECT id, parent_id, leader_person_id FROM departments WHERE id = ? AND deleted_at IS NULL', array($deptId));
                if (!$d) {
                    break;
                }
                if ($d['leader_person_id'] !== null && (int) $d['leader_person_id'] !== (int) $user['person_id']) {
                    $uid = self::userOfPerson((int) $d['leader_person_id']);
                    if ($uid !== null && $uid !== $user['id']) {
                        return self::user($uid);
                    }
                }
                $deptId = $d['parent_id'] === null ? 0 : (int) $d['parent_id'];
            }
            // no head recorded: an account with department scope covering the member
            foreach (self::users() as $u) {
                if ($u['id'] !== $user['id'] && !Permission::isSuper($u) && Permission::scope($u) === 'dept'
                    && $user['person_id'] !== null && Permission::personInScope($u, array('id' => (int) $user['person_id'], 'dept_id' => (int) $user['dept_id']))) {
                    return $u;
                }
            }
        }
        $heads = self::unitHeads();
        foreach ($heads as $uid) {
            if ($uid !== $user['id']) {
                return self::user($uid);
            }
        }
        return null;
    }
}
