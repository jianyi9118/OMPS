<?php
namespace Omps\Modules\Performance\Services;

use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\Xlsx;
use Omps\Database\DB;
use Omps\Engines\Permission;
use Omps\Engines\WorkCalendar;

/**
 * Execution statistics for the people under me (业绩审核 → 统计). Same people as the review list:
 * active people with a login inside the reviewer's data scope, never the reviewer.
 *
 * Assigned plans: plans someone else gave to the person (sub-plans people make for themselves are
 * not counted here) whose dates overlap the period, cancelled plans left out.
 *   done   = plan marked done, or the person's approved completion is 100 %
 *   doing  = the person submitted a result on the plan or on any own sub-plan under it
 *   none   = started but no result submitted yet
 *   future = not started and nothing submitted (not counted in the rate)
 *   rate   = (done + doing) / (done + doing + none); overdue = ended and not done
 *
 * Daily to annual reports: a report is a result on a plan of that length (day, ISO week, month,
 * quarter, year — the plan level). Every workday needs a daily report, every week a weekly report,
 * and so on. A report is due at the end of the last workday of its period; it is counted when its
 * due day lies in the period and it is due already (one submitted early counts as submitted).
 * Nothing is expected before the person's record was created or before the hire date. Holidays and
 * make-up workdays come from the work calendar; no report is expected for a period in which the
 * person is on leave on every workday (a daily report: on a day of leave).
 * Submitted = submitted or approved; returned, draft, withdrawn and unwritten are missing.
 */
class PerfStatsService
{
    const CYCLES = array('day', 'week', 'month', 'quarter', 'annual');
    const KINDS = array('plans', 'day', 'week', 'month', 'quarter', 'annual');
    const PLAN_STATES = array('done', 'doing', 'none', 'future');
    const RANK = array('approved' => 5, 'submitted' => 4, 'returned' => 3, 'withdrawn' => 2, 'draft' => 1);
    /** Longest period looked at (days); older dates are cut off. */
    const MAX_SPAN = 3700;

    // ---------- filters and people ----------

    /** $in: from, to, dept_id, person_id, kw, gaps. */
    private static function args(array $in)
    {
        $d = function ($k) use ($in) {
            $v = isset($in[$k]) ? (string) $in[$k] : '';
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : '';
        };
        $f = array('from' => $d('from'), 'to' => $d('to'), 'dept_id' => isset($in['dept_id']) ? (int) $in['dept_id'] : 0,
            'person_id' => isset($in['person_id']) ? (int) $in['person_id'] : 0, 'kw' => isset($in['kw']) ? trim((string) $in['kw']) : '',
            'gaps' => !empty($in['gaps']));
        if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) {
            list($f['from'], $f['to']) = array($f['to'], $f['from']);
        }
        if ($f['to'] !== '' && $f['from'] !== '' && (strtotime($f['to']) - strtotime($f['from'])) / 86400 > self::MAX_SPAN) {
            $f['from'] = date('Y-m-d', strtotime($f['to']) - self::MAX_SPAN * 86400);
        }
        return $f;
    }

    /** People in the user's scope (not the user), with a login; [id => row]. */
    private static function people($user, array $f)
    {
        $q = DB::table('personnel_profiles p')->active()->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->where('p.status', 'active')
            ->select(array('p.id', 'p.name', 'p.dept_id', 'p.hire_date', 'p.created_at', 'd.name as dept_name', 'd.sort as dept_sort'));
        Permission::scopePersonnel($q, $user, 'p');
        if ($user['person_id'] !== null) {
            $q->where('p.id', '<>', (int) $user['person_id']);
        }
        if ($f['dept_id']) {
            $q->whereIn('p.dept_id', Permission::subtree($f['dept_id']) ?: array(0));
        }
        if ($f['person_id']) {
            $q->where('p.id', $f['person_id']);
        }
        $q->whereLikeAny(array('p.name'), $f['kw']);
        $rows = $q->get();
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
        }
        $users = self::usersOf($ids);
        $out = array();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (empty($users[$id])) {
                continue; // without a login nobody can write results
            }
            $floor = substr((string) $r['created_at'], 0, 10);
            if ($r['hire_date'] !== null && (string) $r['hire_date'] > $floor) {
                $floor = (string) $r['hire_date'];
            }
            $out[$id] = array('id' => $id, 'name' => (string) $r['name'], 'dept_id' => int_or_null($r['dept_id']), 'dept' => str_or_empty($r['dept_name']),
                'dept_sort' => (int) $r['dept_sort'], 'floor' => $floor, 'users' => $users[$id]);
        }
        uasort($out, function ($a, $b) {
            if ($a['dept'] !== $b['dept']) {
                if ($a['dept'] === '' || $b['dept'] === '') {
                    return $a['dept'] === '' ? 1 : -1;
                }
                return $a['dept_sort'] !== $b['dept_sort'] ? $a['dept_sort'] - $b['dept_sort'] : strcmp($a['dept'], $b['dept']);
            }
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    /** person id => [user ids] of active logins. */
    private static function usersOf(array $ids)
    {
        $out = array();
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('users')->whereIn('person_id', $chunk)->where('status', 'active')->whereNull('deleted_at')->select(array('id', 'person_id'))->get() as $u) {
                $out[(int) $u['person_id']][] = (int) $u['id'];
            }
        }
        return $out;
    }

    private static function marks(array $ids)
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    private static function brief(array $p)
    {
        return array('id' => (int) $p['plan_id'], 'title' => (string) $p['title'], 'level' => (string) $p['level'], 'start' => (string) $p['start_date'], 'end' => (string) $p['end_date'],
            'tl_mode' => (string) $p['time_label_mode'], 'tl_text' => str_or_empty($p['time_label_text']));
    }

    // ---------- assigned plans ----------

    /** One row per (plan, person) assignment given by someone else, with its state. */
    private static function planItems(array $people, array $f, $today)
    {
        $items = array();
        $ids = array_keys($people);
        if (!$ids) {
            return $items;
        }
        $rows = array();
        foreach (array_chunk($ids, 400) as $chunk) {
            $sql = "SELECT a.plan_id, a.person_id, a.assigned_by, p.title, p.level, p.start_date, p.end_date, p.status AS plan_status, p.time_label_mode, p.time_label_text,
                    u.display_name AS assigned_by_name
                FROM plan_assignments a JOIN plans p ON p.id = a.plan_id LEFT JOIN users u ON u.id = a.assigned_by
                WHERE p.deleted_at IS NULL AND p.status <> 'cancelled' AND a.person_id IN (" . self::marks($chunk) . ')';
            $params = $chunk;
            if ($f['to'] !== '') {
                $sql .= ' AND p.start_date <= ?';
                $params[] = $f['to'];
            }
            if ($f['from'] !== '') {
                $sql .= ' AND p.end_date >= ?';
                $params[] = $f['from'];
            }
            foreach (DB::select($sql, $params) as $r) {
                $pid = (int) $r['person_id'];
                if ($r['assigned_by'] !== null && in_array((int) $r['assigned_by'], $people[$pid]['users'], true)) {
                    continue; // made by the person themself
                }
                $rows[] = $r;
            }
        }
        if (!$rows) {
            return $items;
        }
        $planIds = array();
        foreach ($rows as $r) {
            $planIds[(int) $r['plan_id']] = (int) $r['plan_id'];
        }
        $planIds = array_values($planIds);
        $reports = array();
        $subs = array();
        foreach (array_chunk($planIds, 400) as $chunk) {
            foreach (DB::select('SELECT id, plan_id, person_id, status, progress, appr_progress, submitted_at FROM performance_reports WHERE plan_id IN (' . self::marks($chunk) . ')', $chunk) as $x) {
                $reports[(int) $x['plan_id']][(int) $x['person_id']] = $x;
            }
            // results the person submitted on own sub-plans under the plan count as working on it
            foreach (DB::select("SELECT pa.ancestor_id, a.person_id, MAX(r.submitted_at) AS last_at FROM plan_paths pa
                    JOIN plans p ON p.id = pa.descendant_id AND p.deleted_at IS NULL
                    JOIN plan_assignments a ON a.plan_id = pa.descendant_id
                    JOIN performance_reports r ON r.plan_id = a.plan_id AND r.person_id = a.person_id AND r.status IN ('submitted','approved')
                    WHERE pa.depth > 0 AND pa.ancestor_id IN (" . self::marks($chunk) . ') GROUP BY pa.ancestor_id, a.person_id', $chunk) as $x) {
                $subs[(int) $x['ancestor_id']][(int) $x['person_id']] = (string) $x['last_at'];
            }
        }
        foreach ($rows as $r) {
            $plan = (int) $r['plan_id'];
            $pid = (int) $r['person_id'];
            $rep = isset($reports[$plan][$pid]) ? $reports[$plan][$pid] : null;
            $status = $rep ? (string) $rep['status'] : 'none';
            $direct = in_array($status, array('submitted', 'approved'), true);
            $sub = isset($subs[$plan][$pid]) ? $subs[$plan][$pid] : null;
            $completed = (string) $r['plan_status'] === 'done' || ($rep && $rep['appr_progress'] !== null && (float) $rep['appr_progress'] >= 100);
            if ($completed) {
                $state = 'done';
            } elseif ($direct || $sub !== null) {
                $state = 'doing';
            } else {
                $state = (string) $r['start_date'] > $today ? 'future' : 'none';
            }
            $progress = null;
            if ($rep) {
                $progress = $direct && $rep['progress'] !== null ? (float) $rep['progress'] : ($rep['appr_progress'] !== null ? (float) $rep['appr_progress'] : null);
            }
            $last = $direct ? (string) $rep['submitted_at'] : '';
            if ($sub !== null && $sub > $last) {
                $last = $sub;
            }
            $items[] = array('person_id' => $pid, 'plan' => self::brief($r), 'state' => $state, 'overdue' => $state !== 'done' && (string) $r['end_date'] < $today,
                'status' => $status, 'progress' => $progress === null ? null : round($progress, 2), 'via_sub' => !$direct && $sub !== null,
                'last_at' => substr($last, 0, 16), 'report_id' => $rep ? (int) $rep['id'] : null, 'assigned_by' => str_or_empty($r['assigned_by_name']));
        }
        return $items;
    }

    // ---------- daily to annual reports ----------

    /** [key, start, end] of the period of $cycle holding the day $ymd. */
    public static function periodOf($cycle, $ymd)
    {
        $ts = strtotime($ymd . ' 12:00:00');
        $y = (int) date('Y', $ts);
        $m = (int) date('n', $ts);
        switch ($cycle) {
            case 'day':
                return array($ymd, $ymd, $ymd);
            case 'week':
                $mon = strtotime('-' . ((int) date('N', $ts) - 1) . ' days', $ts);
                return array(date('o-\WW', $mon), date('Y-m-d', $mon), date('Y-m-d', strtotime('+6 days', $mon)));
            case 'month':
                return array(date('Y-m', $ts), date('Y-m-01', $ts), date('Y-m-t', $ts));
            case 'quarter':
                $q = (int) ceil($m / 3);
                $s = mktime(12, 0, 0, $q * 3 - 2, 1, $y);
                return array($y . '-Q' . $q, date('Y-m-d', $s), date('Y-m-t', mktime(12, 0, 0, $q * 3, 1, $y)));
            default:
                return array((string) $y, $y . '-01-01', $y . '-12-31');
        }
    }

    /** Last workday in [start, end], null when there is none. */
    private static function dueDay(WorkCalendar $cal, $start, $end)
    {
        $ts = strtotime($end . ' 12:00:00');
        $stop = strtotime($start . ' 12:00:00');
        for ($i = 0; $i < 10 && $ts >= $stop; $i++) {
            if ($cal->isWorkday($ts)) {
                return date('Y-m-d', $ts);
            }
            $ts = WorkCalendar::addDays($ts, -1);
        }
        return null;
    }

    /**
     * Report instances per person: [{person_id, cycle, key, start, end, due, state, status, plan, submitted_at, report_id, early}].
     * state: submitted | missing. Not yet due and not submitted: left out.
     */
    private static function reportItems(array $people, array $f, $today, $cycles)
    {
        $items = array();
        if (!$people) {
            return $items;
        }
        $cal = WorkCalendar::fromSettings();
        $nowMin = (int) date('G') * 60 + (int) date('i');
        $to = $f['to'] !== '' ? $f['to'] : $today;
        $minFloor = null;
        foreach ($people as $p) {
            $minFloor = $minFloor === null || $p['floor'] < $minFloor ? $p['floor'] : $minFloor;
        }
        $from = $f['from'] !== '' && $f['from'] > $minFloor ? $f['from'] : $minFloor;
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_SPAN) {
            $from = date('Y-m-d', strtotime($to) - self::MAX_SPAN * 86400);
        }
        if ($from > $to) {
            return $items;
        }
        // periods whose due day falls in [from, to]
        $periods = array();
        foreach ($cycles as $c) {
            $periods[$c] = array();
            $cur = self::periodOf($c, $from);
            for ($guard = 0; $guard < 5000 && $cur[1] <= $to; $guard++) {
                $due = $c === 'day' ? ($cal->isWorkday(strtotime($cur[1] . ' 12:00:00')) ? $cur[1] : null) : self::dueDay($cal, $cur[1], $cur[2]);
                if ($due !== null && $due >= $from && $due <= $to) {
                    $periods[$c][] = array('key' => $cur[0], 'start' => $cur[1], 'end' => $cur[2], 'due' => $due,
                        'past' => $due < $today || ($due === $today && $nowMin >= $cal->end), 'wds' => self::workdaysIn($cal, $cur[1], $cur[2]));
                }
                $cur = self::periodOf($c, date('Y-m-d', strtotime($cur[2] . ' 12:00:00 +1 day')));
            }
        }
        // results on plans of each length: best one per person, cycle and period
        $lo = $to;
        $hi = $from;
        foreach ($periods as $list) {
            foreach ($list as $x) {
                $lo = min($lo, $x['start']);
                $hi = max($hi, $x['end']);
            }
        }
        $best = array();
        $ranks = self::RANK;
        foreach (array_chunk(array_keys($people), 400) as $chunk) {
            $sql = "SELECT a.person_id, a.plan_id, p.title, p.level, p.start_date, p.end_date, p.time_label_mode, p.time_label_text, r.id AS report_id, r.status, r.submitted_at
                FROM plan_assignments a JOIN plans p ON p.id = a.plan_id AND p.deleted_at IS NULL AND p.status <> 'cancelled'
                LEFT JOIN performance_reports r ON r.plan_id = a.plan_id AND r.person_id = a.person_id
                WHERE a.person_id IN (" . self::marks($chunk) . ') AND p.level IN (' . self::marks($cycles) . ') AND p.end_date >= ? AND p.start_date <= ?';
            foreach (DB::select($sql, array_merge($chunk, $cycles, array($lo, $hi))) as $r) {
                $k = self::periodOf((string) $r['level'], (string) $r['start_date'])[0];
                $pid = (int) $r['person_id'];
                $rank = $r['status'] !== null && array_key_exists((string) $r['status'], $ranks) ? $ranks[(string) $r['status']] : 0;
                if (!isset($best[$pid][$r['level']][$k]) || $rank > $best[$pid][$r['level']][$k]['rank']) {
                    $best[$pid][$r['level']][$k] = array('rank' => $rank, 'row' => $r);
                }
            }
        }
        $leave = LeaveService::days(array_keys($people), $lo, $hi);
        foreach ($people as $pid => $p) {
            $off = isset($leave[$pid]) ? $leave[$pid] : array();
            foreach ($periods as $c => $list) {
                foreach ($list as $x) {
                    if (($c === 'day' ? $x['start'] : $x['end']) < $p['floor']) {
                        continue;
                    }
                    $b = isset($best[$pid][$c][$x['key']]) ? $best[$pid][$c][$x['key']]['row'] : null;
                    $status = $b === null ? 'no_plan' : ($b['status'] === null ? 'none' : (string) $b['status']);
                    $done = in_array($status, array('submitted', 'approved'), true);
                    if (!$done && !$x['past']) {
                        continue; // not due yet
                    }
                    if (!$done && $off && self::allOff($x['wds'], $off)) {
                        continue; // on leave on every workday of the period
                    }
                    $items[] = array('person_id' => $pid, 'cycle' => $c, 'key' => $x['key'], 'start' => $x['start'], 'end' => $x['end'], 'due' => $x['due'],
                        'state' => $done ? 'submitted' : 'missing', 'status' => $status, 'early' => $done && !$x['past'],
                        'plan' => $b === null ? null : self::brief($b), 'submitted_at' => $b !== null && $done ? substr((string) $b['submitted_at'], 0, 16) : '',
                        'report_id' => $b !== null && $b['report_id'] !== null ? (int) $b['report_id'] : null);
                }
            }
        }
        return $items;
    }

    /** Workdays (Y-m-d) in [start, end]. */
    private static function workdaysIn(WorkCalendar $cal, $start, $end)
    {
        $out = array();
        $ts = strtotime($start . ' 12:00:00');
        for ($i = 0; $i < 400 && date('Y-m-d', $ts) <= $end; $i++) {
            if ($cal->isWorkday($ts)) {
                $out[] = date('Y-m-d', $ts);
            }
            $ts = WorkCalendar::addDays($ts, 1);
        }
        return $out;
    }

    private static function allOff(array $workdays, array $off)
    {
        foreach ($workdays as $d) {
            if (!isset($off[$d])) {
                return false;
            }
        }
        return true;
    }

    // ---------- figures ----------

    private static function rate($ok, $base)
    {
        return $base > 0 ? round($ok * 100 / $base, 1) : null;
    }

    private static function emptyFig($kind)
    {
        return $kind === 'plans' ? array('done' => 0, 'doing' => 0, 'none' => 0, 'future' => 0, 'overdue' => 0, 'due' => 0, 'executed' => 0, 'rate' => null)
            : array('expected' => 0, 'submitted' => 0, 'approved' => 0, 'missing' => 0, 'early' => 0, 'rate' => null);
    }

    private static function add(array &$fig, $kind, array $it)
    {
        if ($kind === 'plans') {
            $fig[$it['state']]++;
            if ($it['overdue']) {
                $fig['overdue']++;
            }
        } else {
            $fig[$it['state']]++;
            if ($it['status'] === 'approved') {
                $fig['approved']++;
            }
            if ($it['early']) {
                $fig['early']++;
            }
        }
    }

    private static function finish(array $fig, $kind)
    {
        if ($kind === 'plans') {
            $fig['executed'] = $fig['done'] + $fig['doing'];
            $fig['due'] = $fig['executed'] + $fig['none'];
            $fig['rate'] = self::rate($fig['executed'], $fig['due']);
        } else {
            $fig['expected'] = $fig['submitted'] + $fig['missing'];
            $fig['rate'] = self::rate($fig['submitted'], $fig['expected']);
        }
        return $fig;
    }

    /** Everything for the period: people, plan items, report items. */
    private static function compute(Request $req, array $in)
    {
        $f = self::args($in);
        $today = date('Y-m-d');
        $people = self::people($req->user, $f);
        return array($f, $today, $people, self::planItems($people, $f, $today), self::reportItems($people, $f, $today, self::CYCLES));
    }

    /** Figures per person and in total. */
    public static function summary(Request $req, array $in)
    {
        list($f, $today, $people, $plans, $reports) = self::compute($req, $in);
        $per = array();
        foreach ($people as $pid => $_) {
            foreach (self::KINDS as $k) {
                $per[$pid][$k] = self::emptyFig($k);
            }
        }
        foreach ($plans as $it) {
            self::add($per[$it['person_id']]['plans'], 'plans', $it);
        }
        foreach ($reports as $it) {
            self::add($per[$it['person_id']][$it['cycle']], $it['cycle'], $it);
        }
        $rows = array();
        $total = array();
        foreach (self::KINDS as $k) {
            $total[$k] = self::emptyFig($k);
        }
        foreach ($people as $pid => $p) {
            $row = array('person_id' => $pid, 'name' => $p['name'], 'dept' => $p['dept'], 'gaps' => 0);
            foreach (self::KINDS as $k) {
                $row[$k] = self::finish($per[$pid][$k], $k);
                $row['gaps'] += $k === 'plans' ? $row[$k]['none'] : $row[$k]['missing'];
            }
            if ($f['gaps'] && !$row['gaps']) {
                continue;
            }
            foreach (self::KINDS as $k) {
                foreach ($per[$pid][$k] as $key => $v) {
                    if (is_int($v) && isset($total[$k][$key])) {
                        $total[$k][$key] += $v;
                    }
                }
            }
            $rows[] = $row;
        }
        foreach (self::KINDS as $k) {
            $total[$k] = self::finish($total[$k], $k);
        }
        return array('range' => array('from' => $f['from'], 'to' => $f['to'], 'today' => $today), 'rows' => $rows, 'total' => $total, 'people' => count($rows),
            'filters' => self::filterLists($req->user));
    }

    /** Departments and people the filters offer (whole scope, not narrowed by the current filters). */
    private static function filterLists($user)
    {
        $all = self::people($user, self::args(array()));
        $people = array();
        foreach ($all as $p) {
            $people[] = array('id' => $p['id'], 'name' => $p['name'], 'dept_id' => $p['dept_id']);
        }
        return array('depts' => \Omps\Modules\Organization\Services\DeptService::options($user), 'people' => $people);
    }

    /**
     * The list behind one figure. $kind: plans | day | week | month | quarter | annual;
     * $state: '' (all) | plans: done, doing, none, future, executed, overdue | reports: submitted, missing.
     */
    public static function items(Request $req, array $in, $kind, $state, $page, $size)
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'plans';
        $f = self::args($in);
        $today = date('Y-m-d');
        $people = self::people($req->user, $f);
        $list = $kind === 'plans' ? self::planItems($people, $f, $today) : self::reportItems($people, $f, $today, array($kind));
        if ($f['gaps']) {
            // same people as the summary table: only those with something missing
            $gap = array();
            foreach (self::planItems($people, $f, $today) as $it) {
                if ($it['state'] === 'none') {
                    $gap[$it['person_id']] = true;
                }
            }
            foreach (self::reportItems($people, $f, $today, self::CYCLES) as $it) {
                if ($it['state'] === 'missing') {
                    $gap[$it['person_id']] = true;
                }
            }
            $list = array_values(array_filter($list, function ($it) use ($gap) {
                return isset($gap[$it['person_id']]);
            }));
        }
        $counts = array('all' => count($list));
        foreach ($list as $it) {
            $s = $it['state'];
            $counts[$s] = isset($counts[$s]) ? $counts[$s] + 1 : 1;
            if ($kind === 'plans') {
                if ($it['overdue']) {
                    $counts['overdue'] = isset($counts['overdue']) ? $counts['overdue'] + 1 : 1;
                }
                if ($it['state'] === 'done' || $it['state'] === 'doing') {
                    $counts['executed'] = isset($counts['executed']) ? $counts['executed'] + 1 : 1;
                }
            }
        }
        if ($state !== '') {
            $list = array_values(array_filter($list, function ($it) use ($state, $kind) {
                if ($kind === 'plans' && $state === 'overdue') {
                    return $it['overdue'];
                }
                if ($kind === 'plans' && $state === 'executed') {
                    return $it['state'] === 'done' || $it['state'] === 'doing';
                }
                return $it['state'] === $state;
            }));
        }
        // newest first; missing before submitted for reports, not executed first for plans
        $order = $kind === 'plans' ? array('none' => 0, 'doing' => 1, 'future' => 2, 'done' => 3) : array('missing' => 0, 'submitted' => 1);
        $names = array();
        foreach ($people as $pid => $p) {
            $names[$pid] = $p;
        }
        usort($list, function ($a, $b) use ($kind, $order) {
            $da = $kind === 'plans' ? $a['plan']['end'] : $a['due'];
            $db = $kind === 'plans' ? $b['plan']['end'] : $b['due'];
            if ($da !== $db) {
                return strcmp($db, $da);
            }
            if ($order[$a['state']] !== $order[$b['state']]) {
                return $order[$a['state']] - $order[$b['state']];
            }
            return $a['person_id'] - $b['person_id'];
        });
        $total = count($list);
        $page = max(1, (int) $page);
        $size = max(1, min(200, (int) $size));
        $rows = array_slice($list, ($page - 1) * $size, $size);
        foreach ($rows as $i => $it) {
            $rows[$i]['person'] = $names[$it['person_id']]['name'];
            $rows[$i]['dept'] = $names[$it['person_id']]['dept'];
        }
        return array('kind' => $kind, 'state' => $state, 'counts' => $counts, 'rows' => $rows, 'total' => $total, 'page' => $page, 'size' => $size,
            'range' => array('from' => $f['from'], 'to' => $f['to'], 'today' => $today));
    }

    /** The per-person table as an .xlsx file. */
    public static function export(Request $req, array $in)
    {
        $d = self::summary($req, $in);
        $f = self::args($in);
        $header = array(__('perf.st.col.person'), __('perf.st.col.dept'),
            __('perf.st.kind.plans') . ' · ' . __('perf.st.due'), __('perf.st.kind.plans') . ' · ' . __('perf.st.executed'), __('perf.st.kind.plans') . ' · ' . __('perf.st.not_executed'),
            __('perf.st.kind.plans') . ' · ' . __('perf.st.overdue'), __('perf.st.kind.plans') . ' · ' . __('perf.st.rate'));
        foreach (self::CYCLES as $c) {
            foreach (array('expected', 'submitted', 'missing', 'rate') as $k) {
                $header[] = __('perf.st.kind.' . $c) . ' · ' . __('perf.st.' . $k);
            }
        }
        $pct = function ($v) {
            return $v === null ? '' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . '%';
        };
        $line = function (array $r, $name, $dept) use ($pct) {
            $x = array($name, $dept, $r['plans']['due'], $r['plans']['executed'], $r['plans']['none'], $r['plans']['overdue'], $pct($r['plans']['rate']));
            foreach (self::CYCLES as $c) {
                $x[] = $r[$c]['expected'];
                $x[] = $r[$c]['submitted'];
                $x[] = $r[$c]['missing'];
                $x[] = $pct($r[$c]['rate']);
            }
            return $x;
        };
        $rows = array();
        foreach ($d['rows'] as $r) {
            $rows[] = $line($r, $r['name'], $r['dept']);
        }
        $rows[] = $line($d['total'], __('perf.st.total'), '');
        $span = ($f['from'] !== '' || $f['to'] !== '') ? $f['from'] . '~' . $f['to'] : __('period.all');
        $title = __('perf.st.title');
        return new Response(Xlsx::write($header, $rows, mb_substr($title, 0, 30)), 200, array('Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="export.xlsx"; filename*=UTF-8\'\'' . rawurlencode($title . '-' . str_replace('~', '_', $span) . '.xlsx')));
    }
}
