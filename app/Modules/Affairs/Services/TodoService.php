<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Database\DB;
use Omps\Engines\Permission;

/**
 * 待办事项 (design §13.1, §17): everything waiting for the user, read live from the business
 * tables, so an item leaves the list the moment it is handled; history() lists what the user handled. Text is built in the browser from
 * kind + params (language key todo.msg.<kind>).
 */
class TodoService
{
    const KINDS = array('issue_order', 'issue_read', 'note_read', 'perf_review', 'leave_review', 'change_review', 'perf_returned', 'perf_write', 'staff_assign',
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

        // leave applications waiting for my approval (same people as the results review)
        if (Permission::can($user, 'perf.audit') && DB::select("SHOW TABLES LIKE 'person_leaves'")) {
            $q = DB::table('person_leaves l')->join('personnel_profiles pp', 'pp.id', '=', 'l.person_id')->whereNull('pp.deleted_at')->where('l.status', 'pending')
                ->select(array('l.id', 'l.kind', 'l.start_date', 'l.end_date', 'l.created_at', 'pp.name'));
            Permission::scopePersonnel($q, $user, 'pp');
            if ($user['person_id'] !== null) {
                $q->where('l.person_id', '<>', (int) $user['person_id']);
            }
            foreach ($q->orderBy('l.created_at')->limit(self::LIMIT)->get() as $r) {
                $rows[] = array('kind' => 'leave_review', 'params' => array('name' => (string) $r['name'], 'kind' => array('t' => 'leave.kind.' . $r['kind']),
                    'start' => (string) $r['start_date'], 'end' => (string) $r['end_date']), 'link' => '/performance/review?view=leaves&id=' . (int) $r['id'], 'at' => (string) $r['created_at']);
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
                $rows[] = array('kind' => 'change_review', 'params' => array('name' => (string) $r['name']), 'link' => '/org/staff?tab=review&id=' . (int) $r['id'], 'at' => (string) $r['submitted_at']);
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

    /**
     * 已处理: to-do items I have handled, newest first, read from the same business tables
     * (who handled what is recorded there). Each row: kind, params, result, at, link.
     * Paged over several sources: each gives its newest page*size rows, merged and cut.
     * $f = list filters: the period is on the time I handled the item, the keyword on the
     * text the row shows (each source names its text columns).
     */
    public static function history(array $user, array $f, $page, $size)
    {
        $uid = (int) $user['id'];
        $me = $user['person_id'] === null ? 0 : (int) $user['person_id'];
        $need = $page * $size;
        $rows = array();
        $total = 0;
        $from = isset($f['from']) ? (string) $f['from'] : '';
        $to = isset($f['to']) ? (string) $f['to'] : '';
        $kw = isset($f['kw']) ? trim((string) $f['kw']) : '';
        $take = function ($sql, array $args, array $text, $fn) use (&$rows, &$total, $need, $from, $to, $kw) {
            $where = array();
            if ($from !== '') {
                $where[] = 'x.at >= ?';
                $args[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = 'x.at <= ?';
                $args[] = $to . ' 23:59:59';
            }
            if ($kw !== '') {
                $like = array();
                foreach ($text as $c) {
                    // compared as utf8mb4: ascii columns (asset numbers) would refuse a Chinese term
                    $like[] = 'CONVERT(x.' . $c . ' USING utf8mb4) LIKE ?';
                    $args[] = '%' . addcslashes($kw, '%_\\') . '%';
                }
                $where[] = '(' . implode(' OR ', $like) . ')';
            }
            $sql = 'SELECT * FROM (' . $sql . ') x' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
            $total += (int) DB::value('SELECT COUNT(*) FROM (' . $sql . ') y', $args);
            foreach (DB::select($sql . ' ORDER BY at DESC LIMIT ' . (int) $need, $args) as $r) {
                $rows[] = $fn($r);
            }
        };
        $name = function ($person, $account) {
            return $person !== null && $person !== '' ? (string) $person : (string) $account;
        };

        // received items: orders I finished, read-only items I opened
        $take("SELECT i.id, i.title, i.need_reply, IF(i.need_reply = 1, ir.done_at, ir.read_at) AS at, u.display_name AS sender, sp.name AS sender_person
            FROM issue_recipients ir JOIN issues i ON i.id = ir.issue_id JOIN users u ON u.id = i.sender_id LEFT JOIN personnel_profiles sp ON sp.id = u.person_id
            WHERE ir.user_id = ? AND ((i.need_reply = 1 AND ir.done_at IS NOT NULL) OR (i.need_reply = 0 AND ir.read_at IS NOT NULL))", array($uid),
            array('title', 'sender', 'sender_person'), function ($r) use ($name) {
            $order = (int) $r['need_reply'] === 1;
            return array('kind' => $order ? 'issue_order' : 'issue_read', 'params' => array('title' => (string) $r['title'], 'sender' => $name($r['sender_person'], $r['sender'])),
                'result' => $order ? 'done' : 'read', 'at' => (string) $r['at'], 'link' => '/affairs/issues?id=' . (int) $r['id']);
        });

        // notes submitted to me that I read
        $take("SELECT n.id, n.title, n.read_at AS at, u.display_name AS owner, op.name AS owner_person FROM notes n JOIN users u ON u.id = n.owner_id
            LEFT JOIN personnel_profiles op ON op.id = u.person_id WHERE n.submitted_to = ? AND n.status = 'read' AND n.read_at IS NOT NULL", array($uid),
            array('title', 'owner', 'owner_person'), function ($r) use ($name) {
            return array('kind' => 'note_read', 'params' => array('title' => (string) $r['title'], 'name' => $name($r['owner_person'], $r['owner'])),
                'result' => 'read', 'at' => (string) $r['at'], 'link' => '/affairs/notes?tab=inbox&id=' . (int) $r['id']);
        });

        // results I reviewed (a resubmitted result waits again and leaves the history), never my own
        $take("SELECT r.id, r.status, r.reviewed_at AS at, p.title, pp.name FROM performance_reports r JOIN plans p ON p.id = r.plan_id
            JOIN personnel_profiles pp ON pp.id = r.person_id WHERE r.reviewed_by = ? AND r.status IN ('approved', 'returned') AND r.person_id <> ? AND r.reviewed_at IS NOT NULL",
            array($uid, $me), array('title', 'name'), function ($r) {
            return array('kind' => 'perf_review', 'params' => array('name' => (string) $r['name'], 'title' => (string) $r['title']),
                'result' => (string) $r['status'], 'at' => (string) $r['at'], 'link' => '/performance/review?focus=' . (int) $r['id']);
        });

        // leave applications I decided (records a leader made directly are not applications)
        if (DB::select("SHOW TABLES LIKE 'person_leaves'")) {
            $take("SELECT l.id, l.kind, l.status, l.start_date, l.end_date, l.reviewed_at AS at, p.name FROM person_leaves l JOIN personnel_profiles p ON p.id = l.person_id
                WHERE l.reviewed_by = ? AND l.status IN ('approved', 'rejected') AND l.created_by <> l.reviewed_by AND l.person_id <> ? AND l.reviewed_at IS NOT NULL",
                array($uid, $me), array('name'), function ($r) {
                return array('kind' => 'leave_review', 'params' => array('name' => (string) $r['name'], 'kind' => array('t' => 'leave.kind.' . $r['kind']),
                    'start' => (string) $r['start_date'], 'end' => (string) $r['end_date']), 'result' => (string) $r['status'], 'at' => (string) $r['at'],
                    'link' => '/performance/review?view=leaves&id=' . (int) $r['id']);
            });
        }

        // profile changes I reviewed
        $take("SELECT c.id, c.status, c.reviewed_at AS at, p.name FROM profile_change_requests c JOIN personnel_profiles p ON p.id = c.person_id
            WHERE c.reviewed_by = ? AND c.status IN ('approved', 'returned') AND c.person_id <> ? AND c.reviewed_at IS NOT NULL", array($uid, $me), array('name'), function ($r) {
            return array('kind' => 'change_review', 'params' => array('name' => (string) $r['name']), 'result' => (string) $r['status'], 'at' => (string) $r['at'],
                'link' => '/org/staff?tab=review&id=' . (int) $r['id']);
        });

        if (class_exists('Omps\\Modules\\Device\\Services\\DeviceService') && DB::select("SHOW TABLES LIKE 'device_faults'")) {
            $fault = function ($kind, $result) {
                return function ($r) use ($kind, $result) {
                    return array('kind' => $kind, 'params' => array('asset' => (string) $r['asset_no'], 'device' => (string) $r['name']),
                        'result' => $result === null ? (string) $r['status'] : $result, 'at' => (string) $r['at'], 'link' => '/device/repair?id=' . (int) $r['id']);
                };
            };
            // repairs I assigned, requests I cancelled, repairs I finished
            $take("SELECT f.id, f.status, r.started_at AS at, d.asset_no, d.name FROM device_repairs r JOIN device_faults f ON f.id = r.fault_id JOIN devices d ON d.id = f.device_id
                WHERE r.created_by = ?", array($uid), array('asset_no', 'name'), $fault('device_fault', 'assigned'));
            $take("SELECT f.id, f.status, f.closed_at AS at, d.asset_no, d.name FROM device_faults f JOIN devices d ON d.id = f.device_id
                WHERE f.closed_by = ? AND f.status = 'cancelled'", array($uid), array('asset_no', 'name'), $fault('device_fault', null));
            $take("SELECT f.id, f.status, f.closed_at AS at, d.asset_no, d.name FROM device_faults f JOIN devices d ON d.id = f.device_id
                WHERE f.closed_by = ? AND f.status = 'fixed'", array($uid), array('asset_no', 'name'), $fault('device_repair', null));
            // hardware changes I confirmed or ignored
            $take("SELECT a.id, a.status, a.handled_at AS at, d.asset_no, d.name FROM device_anomalies a JOIN devices d ON d.id = a.device_id
                WHERE a.handled_by = ? AND a.status IN ('confirmed', 'ignored') AND a.handled_at IS NOT NULL", array($uid), array('asset_no', 'name'), function ($r) {
                return array('kind' => 'device_anomaly', 'params' => array('asset' => (string) $r['asset_no'], 'device' => (string) $r['name']),
                    'result' => (string) $r['status'], 'at' => (string) $r['at'], 'link' => '/device/monitor?tab=anomaly&id=' . (int) $r['id']);
            });
        }

        usort($rows, function ($a, $b) {
            return strcmp($b['at'], $a['at']);
        });
        return array('rows' => array_slice($rows, ($page - 1) * $size, $size), 'total' => $total, 'page' => $page, 'size' => $size);
    }
}
