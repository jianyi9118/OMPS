<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Database\DB;
use Omps\Engines\Permission;

/**
 * 待办事项 (design §13.1, §17): everything waiting for the user, read live from the business
 * tables, so an item leaves the list the moment it is handled. Text is built in the browser from
 * kind + params (language key todo.msg.<kind>).
 */
class TodoService
{
    const KINDS = array('issue_order', 'issue_read', 'note_read', 'perf_review', 'change_review', 'perf_returned', 'perf_write', 'staff_assign',
        'device_repair', 'device_fault', 'device_anomaly');
    const LIMIT = 50;

    /** @return array ['rows' => [...], 'counts' => [...]] */
    public static function build(array $user)
    {
        $rows = array();
        $uid = (int) $user['id'];
        $now = now();
        $soon = date('Y-m-d H:i:s', time() + 86400);

        // received items: orders not done, read-only items not opened
        $issues = DB::select("SELECT i.id, i.title, i.kind, i.need_reply, i.due_at, i.created_at, ir.read_at, u.display_name AS sender, sp.name AS sender_person
            FROM issue_recipients ir JOIN issues i ON i.id = ir.issue_id JOIN users u ON u.id = i.sender_id LEFT JOIN personnel_profiles sp ON sp.id = u.person_id
            WHERE ir.user_id = ? AND i.status = 'active' AND ir.done_at IS NULL AND (i.need_reply = 1 OR ir.read_at IS NULL)
            ORDER BY i.due_at IS NULL, i.due_at, i.id DESC LIMIT " . self::LIMIT, array($uid));
        $urgent = array();
        foreach ($issues as $i) {
            $sender = $i['sender_person'] !== null ? (string) $i['sender_person'] : (string) $i['sender'];
            $order = (int) $i['need_reply'] === 1;
            $due = $i['due_at'] === null ? '' : substr((string) $i['due_at'], 0, 16);
            $over = $order && $i['due_at'] !== null && (string) $i['due_at'] < $now;
            $rows[] = array('kind' => $order ? 'issue_order' : 'issue_read', 'params' => array('title' => (string) $i['title'], 'sender' => $sender, 'due' => $due),
                'link' => '/affairs/issues?id=' . (int) $i['id'], 'at' => (string) $i['created_at'], 'due' => $due, 'overdue' => $over, 'unread' => $i['read_at'] === null);
            if ($order && $i['due_at'] !== null && (string) $i['due_at'] <= $soon) {
                $urgent[] = array('id' => (int) $i['id'], 'title' => (string) $i['title'], 'due' => $due, 'overdue' => $over);
            }
        }
        $issueCount = count($issues);

        // notes submitted to me, not read yet
        $notes = DB::select("SELECT n.id, n.title, n.submitted_at, u.display_name AS owner, op.name AS owner_person FROM notes n JOIN users u ON u.id = n.owner_id
            LEFT JOIN personnel_profiles op ON op.id = u.person_id WHERE n.submitted_to = ? AND n.status = 'submitted' ORDER BY n.submitted_at LIMIT " . self::LIMIT, array($uid));
        foreach ($notes as $n) {
            $rows[] = array('kind' => 'note_read', 'params' => array('title' => (string) $n['title'], 'name' => $n['owner_person'] !== null ? (string) $n['owner_person'] : (string) $n['owner']),
                'link' => '/affairs/notes?tab=inbox&id=' . (int) $n['id'], 'at' => (string) $n['submitted_at']);
        }

        // results waiting for my review (fixed chain: scope covers the person, never myself)
        if (Permission::can($user, 'perf.audit')) {
            $q = DB::table('performance_reports r')->join('plans p', 'p.id', '=', 'r.plan_id')->join('personnel_profiles pp', 'pp.id', '=', 'r.person_id')
                ->whereNull('p.deleted_at')->whereNull('pp.deleted_at')->where('r.status', 'submitted')
                ->select(array('r.id', 'r.submitted_at', 'p.title', 'pp.name'));
            Permission::scopePersonnel($q, $user, 'pp');
            if ($user['person_id'] !== null) {
                $q->where('r.person_id', '<>', (int) $user['person_id']);
            }
            foreach ($q->orderBy('r.submitted_at')->limit(self::LIMIT)->get() as $r) {
                $rows[] = array('kind' => 'perf_review', 'params' => array('name' => (string) $r['name'], 'title' => (string) $r['title']),
                    'link' => '/performance/review?focus=' . (int) $r['id'], 'at' => (string) $r['submitted_at']);
            }
        }

        // profile changes waiting for my review
        if (Permission::can($user, 'staff.audit')) {
            $q = DB::table('profile_change_requests c')->join('personnel_profiles p', 'p.id', '=', 'c.person_id')->whereNull('p.deleted_at')->where('c.status', 'pending')
                ->select(array('c.id', 'c.submitted_at', 'p.name'));
            Permission::scopePersonnel($q, $user, 'p');
            if ($user['person_id'] !== null) {
                $q->where('c.person_id', '<>', (int) $user['person_id']);
            }
            foreach ($q->orderBy('c.submitted_at')->limit(self::LIMIT)->get() as $r) {
                $rows[] = array('kind' => 'change_review', 'params' => array('name' => (string) $r['name']), 'link' => '/org/staff?tab=review', 'at' => (string) $r['submitted_at']);
            }
        }

        // my own results: returned ones, and tasks that need a result now
        if ($user['person_id'] !== null && Permission::can($user, 'perf.edit')) {
            $me = (int) $user['person_id'];
            foreach (DB::select("SELECT r.plan_id, r.review_comment, r.reviewed_at, p.title FROM performance_reports r JOIN plans p ON p.id = r.plan_id
                WHERE r.person_id = ? AND r.status = 'returned' AND p.deleted_at IS NULL ORDER BY r.reviewed_at LIMIT " . self::LIMIT, array($me)) as $r) {
                $rows[] = array('kind' => 'perf_returned', 'params' => array('title' => (string) $r['title'], 'reason' => str_or_empty($r['review_comment'])),
                    'link' => '/plans/mine?plan=' . (int) $r['plan_id'], 'at' => str_or_empty($r['reviewed_at']));
            }
            $today = date('Y-m-d');
            $cal = \Omps\Engines\WorkCalendar::fromSettings();
            $nextWork = date('Y-m-d', $cal->addWorkdays(\Omps\Engines\WorkCalendar::day(time()), 1));
            foreach (DB::select("SELECT p.id, p.title, p.level, p.end_date FROM plan_assignments a JOIN plans p ON p.id = a.plan_id
                LEFT JOIN performance_reports r ON r.plan_id = p.id AND r.person_id = a.person_id
                WHERE a.person_id = ? AND p.deleted_at IS NULL AND p.status = 'active' AND (r.id IS NULL OR r.status IN ('draft','withdrawn'))
                  AND ((p.level = 'day' AND p.start_date <= ? AND p.end_date >= ?) OR (p.level <> 'day' AND p.level <> 'long' AND p.end_date >= ? AND p.end_date <= ?))
                ORDER BY p.end_date, p.id LIMIT " . self::LIMIT, array($me, $today, $today, $today, $nextWork)) as $p) {
                $rows[] = array('kind' => 'perf_write', 'params' => array('title' => (string) $p['title'], 'end' => (string) $p['end_date'], 'level' => array('t' => 'plan.level.' . $p['level'])),
                    'link' => '/plans/mine?plan=' . (int) $p['id'], 'at' => (string) $p['end_date'] . ' 00:00:00', 'due' => (string) $p['end_date']);
            }
        }

        // people moved out of a department, waiting for a new one (whole-unit scope with edit rights)
        $scope = Permission::scope($user);
        if (Permission::can($user, 'staff.edit') && ($scope === 'unit' || $scope === 'all')) {
            foreach (DB::select("SELECT id, name, updated_at FROM personnel_profiles WHERE deleted_at IS NULL AND unassigned = 1 AND status = 'active' ORDER BY updated_at LIMIT " . self::LIMIT) as $p) {
                $rows[] = array('kind' => 'staff_assign', 'params' => array('name' => (string) $p['name']), 'link' => '/org/staff?unassigned=1', 'at' => str_or_empty($p['updated_at']));
            }
        }

        // devices (phase 6): repairs assigned to me; open faults and hardware changes for device editors
        if (class_exists('Omps\\Modules\\Device\\Services\\DeviceService') && DB::select("SHOW TABLES LIKE 'device_faults'")) {
            foreach (DB::select("SELECT f.id, f.reported_at, d.asset_no, d.name FROM device_repairs r JOIN device_faults f ON f.id = r.fault_id JOIN devices d ON d.id = f.device_id
                WHERE r.repairer_user_id = ? AND f.status = 'repairing' AND d.deleted_at IS NULL ORDER BY f.id LIMIT " . self::LIMIT, array($uid)) as $f) {
                $rows[] = array('kind' => 'device_repair', 'params' => array('asset' => (string) $f['asset_no'], 'device' => (string) $f['name']),
                    'link' => '/device/repair?id=' . (int) $f['id'], 'at' => (string) $f['reported_at']);
            }
            if (Permission::can($user, 'device.edit')) {
                $q = DB::table('device_faults f')->join('devices d', 'd.id', '=', 'f.device_id')->whereNull('d.deleted_at')->where('f.status', 'open')
                    ->select(array('f.id', 'f.reported_at', 'd.asset_no', 'd.name'));
                \Omps\Modules\Device\Services\DeviceService::scopeQuery($q, $user, 'd');
                foreach ($q->orderBy('f.id')->limit(self::LIMIT)->get() as $f) {
                    $rows[] = array('kind' => 'device_fault', 'params' => array('asset' => (string) $f['asset_no'], 'device' => (string) $f['name']),
                        'link' => '/device/repair?id=' . (int) $f['id'], 'at' => (string) $f['reported_at']);
                }
                $q = DB::table('device_anomalies a')->join('devices d', 'd.id', '=', 'a.device_id')->whereNull('d.deleted_at')->where('a.status', 'pending')
                    ->select(array('a.id', 'a.created_at', 'd.asset_no', 'd.name'));
                \Omps\Modules\Device\Services\DeviceService::scopeQuery($q, $user, 'd');
                foreach ($q->orderBy('a.id')->limit(self::LIMIT)->get() as $a) {
                    $rows[] = array('kind' => 'device_anomaly', 'params' => array('asset' => (string) $a['asset_no'], 'device' => (string) $a['name']),
                        'link' => '/device/monitor?tab=anomaly&id=' . (int) $a['id'], 'at' => (string) $a['created_at']);
                }
            }
        }

        $order = array_flip(self::KINDS);
        usort($rows, function ($a, $b) use ($order) {
            $oa = !empty($a['overdue']) ? -1 : $order[$a['kind']];
            $ob = !empty($b['overdue']) ? -1 : $order[$b['kind']];
            return $oa === $ob ? strcmp($a['at'], $b['at']) : $oa - $ob;
        });
        $notesCount = count($notes);
        return array('rows' => $rows, 'urgent' => $urgent, 'counts' => array('todo' => count($rows), 'issues' => $issueCount, 'notes' => $notesCount));
    }
}
