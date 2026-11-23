<?php
namespace Omps\Modules\System\Services;

use Omps\Core\Settings;
use Omps\Database\DB;
use Omps\Engines\Permission;

/**
 * 工作台 by role (design §17).
 *  - unit head (and other whole-unit roles): pending reviews, average completion and lagging plans of
 *    the unit, results written today; each department; the unit's key plans.
 *  - department head (department scope): the same figures for the department; each member's day;
 *    the department's plans, lagging first.
 *  - super admin: as the unit head, plus the system status.
 *  - members (own scope): no workbench; they land on 我的任务.
 * "Lagging" is PlanService::lagging, the same rule as 数据统计.
 */
class WorkbenchService
{
    const PLAN = 'Omps\Modules\Plan\Services\PlanService';
    const PERF = 'Omps\Modules\Performance\Services\PerformanceService';

    public static function has($user)
    {
        return in_array(Permission::scope($user), array('all', 'unit', 'dept'), true);
    }

    public static function build($user)
    {
        $scope = Permission::scope($user);
        $wide = $scope === 'all' || $scope === 'unit';
        $deptIds = Permission::deptIds($user);
        $out = array('kind' => $wide ? 'unit' : 'dept', 'kpis' => array(), 'depts' => array(), 'members' => array(), 'plans' => array(),
            'todo' => array(), 'notices' => array(), 'devices' => null, 'system' => null);
        $today = date('Y-m-d');
        $todayStart = $today . ' 00:00:00';
        $now = time();

        // people in scope
        $q = DB::table('personnel_profiles p')->active()->where('p.status', 'active')->select(array('p.id', 'p.name', 'p.dept_id'));
        Permission::scopePersonnel($q, $user, 'p');
        $people = array();
        foreach ($q->orderBy('p.staff_no')->get() as $p) {
            $people[(int) $p['id']] = $p;
        }

        // plans and their completion
        $plans = array();
        $progress = array();
        if (class_exists(self::PLAN)) {
            $svc = self::PLAN;
            $all = $svc::loadAll();
            $vis = $svc::visibleIds($user, $all);
            foreach ($all as $id => $p) {
                if (isset($vis[$id])) {
                    $plans[$id] = $p;
                }
            }
            if (class_exists(self::PERF)) {
                $perf = self::PERF;
                $progress = $perf::progressMap($all, $perf::byPlans(array_keys($all)));
            }
        }
        $pr = function ($id) use ($progress) {
            return isset($progress[$id]) ? round((float) $progress[$id], 1) : 0.0;
        };
        $mine = function (array $p) use ($wide, $deptIds) {
            return $wide || ($p['dept_id'] !== null && $deptIds !== null && in_array((int) $p['dept_id'], $deptIds, true));
        };
        $svc = self::PLAN;
        $tracked = array();
        foreach ($plans as $id => $p) {
            if ((string) $p['status'] === 'active' && !in_array((string) $p['level'], array('week', 'day'), true) && $mine($p) && (string) $p['start_date'] <= $today) {
                $tracked[$id] = $p;
            }
        }
        $sum = 0.0;
        $lag = 0;
        $byDept = array();
        foreach ($tracked as $id => $p) {
            $v = $pr($id);
            $l = $svc::lagging($p['level'], $p['status'], $p['start_date'], $p['end_date'], $v, $now);
            $sum += $v;
            $lag += $l ? 1 : 0;
            $k = $p['dept_id'] === null ? 0 : (int) $p['dept_id'];
            if (!isset($byDept[$k])) {
                $byDept[$k] = array(0, 0.0, 0);
            }
            $byDept[$k][0]++;
            $byDept[$k][1] += $v;
            $byDept[$k][2] += $l ? 1 : 0;
        }

        // results written today and waiting for review
        $written = array();
        $pendingBy = array();
        if (class_exists(self::PERF) && $people) {
            foreach (DB::table('performance_reports')->whereIn('person_id', array_keys($people))->whereIn('status', array('submitted', 'approved'))
                ->where('submitted_at', '>=', $todayStart)->select(array('person_id'))->get() as $r) {
                $written[(int) $r['person_id']] = true;
            }
            if (Permission::can($user, 'perf.audit')) {
                foreach (DB::table('performance_reports r')->join('plans pl', 'pl.id', '=', 'r.plan_id')->whereNull('pl.deleted_at')->whereIn('r.person_id', array_keys($people))
                    ->where('r.status', 'submitted')->select(array('r.person_id'))->get() as $r) {
                    if ($user['person_id'] === null || (int) $r['person_id'] !== (int) $user['person_id']) {
                        $k = (int) $r['person_id'];
                        $pendingBy[$k] = isset($pendingBy[$k]) ? $pendingBy[$k] + 1 : 1;
                    }
                }
            }
        }
        $pendingTotal = class_exists(self::PERF) ? call_user_func(array(self::PERF, 'pendingCount'), $user) : 0;
        $out['kpis'] = array(
            array('k' => 'pending', 'v' => $pendingTotal, 'href' => '/performance/review', 'bad' => $pendingTotal > 0),
            array('k' => $wide ? 'avg_unit' : 'avg_dept', 'v' => $tracked ? round($sum / count($tracked)) . '%' : '—', 'href' => '/plans/tree'),
            array('k' => 'lagging', 'v' => $lag, 'href' => '/plans/tree', 'bad' => $lag > 0),
            array('k' => $wide ? 'written_unit' : 'written_dept', 'v' => count($written) . ' / ' . count($people), 'href' => $wide ? '/stats/depts' : '/plans/tree'),
        );

        // plan rows for the tables
        $planRow = function ($id, array $p) use ($pr, $svc, $now, $today) {
            $v = $pr($id);
            return array('id' => (int) $id, 'title' => (string) $p['title'], 'level' => (string) $p['level'], 'dept' => str_or_empty($p['dept_name']),
                'time' => \Omps\Modules\Plan\Services\PlanLabel::label(array('level' => $p['level'], 'start' => $p['start_date'], 'end' => $p['end_date'],
                    'tl_mode' => $p['time_label_mode'], 'tl_text' => $p['time_label_text'])), 'progress' => $v, 'time_pct' => $svc::timePct($p['start_date'], $p['end_date'], $now),
                'state' => $svc::state($p, $today), 'lagging' => $svc::lagging($p['level'], $p['status'], $p['start_date'], $p['end_date'], $v, $now));
        };

        if ($wide) {
            // each department
            $leaders = array();
            foreach (DB::select('SELECT d.id, p.name FROM departments d JOIN personnel_profiles p ON p.id = d.leader_person_id WHERE d.deleted_at IS NULL') as $l) {
                $leaders[(int) $l['id']] = (string) $l['name'];
            }
            $count = array();
            $wr = array();
            $pd = array();
            foreach ($people as $pid => $p) {
                $k = $p['dept_id'] === null ? 0 : (int) $p['dept_id'];
                $count[$k] = isset($count[$k]) ? $count[$k] + 1 : 1;
                $wr[$k] = (isset($wr[$k]) ? $wr[$k] : 0) + (isset($written[$pid]) ? 1 : 0);
                $pd[$k] = (isset($pd[$k]) ? $pd[$k] : 0) + (isset($pendingBy[$pid]) ? $pendingBy[$pid] : 0);
            }
            foreach (DB::table('departments')->active()->orderBy('path')->orderBy('sort')->get() as $d) {
                $id = (int) $d['id'];
                $pl = isset($byDept[$id]) ? $byDept[$id] : array(0, 0.0, 0);
                $out['depts'][] = array('id' => $id, 'name' => (string) $d['name'], 'depth' => max(0, substr_count((string) $d['path'], '/') - 1),
                    'leader' => isset($leaders[$id]) ? $leaders[$id] : '', 'people' => isset($count[$id]) ? $count[$id] : 0, 'plans' => $pl[0],
                    'progress' => $pl[0] ? round($pl[1] / $pl[0]) : null, 'lagging' => $pl[2], 'written' => isset($wr[$id]) ? $wr[$id] : 0, 'pending' => isset($pd[$id]) ? $pd[$id] : 0);
            }
            // key plans: long-term plans of the unit and the departments, the unit's annual plans
            foreach ($plans as $id => $p) {
                if ((string) $p['status'] !== 'cancelled' && ((string) $p['level'] === 'long' || ((string) $p['level'] === 'annual' && $p['dept_id'] === null))) {
                    $out['plans'][] = $planRow($id, $p);
                }
            }
        } else {
            // each member of the department
            foreach ($people as $pid => $p) {
                if ($user['person_id'] !== null && (int) $pid === (int) $user['person_id']) {
                    continue;
                }
                $todayPlans = array();
                $week = array();
                foreach ($plans as $id => $pl) {
                    if ((string) $pl['status'] !== 'active' || !in_array((int) $pid, $pl['assignees'], true) || (string) $pl['start_date'] > $today || (string) $pl['end_date'] < $today) {
                        continue;
                    }
                    if ((string) $pl['level'] === 'day') {
                        $todayPlans[] = (string) $pl['title'];
                    } elseif ((string) $pl['level'] === 'week') {
                        $week[] = $pr($id);
                    }
                }
                $draft = class_exists(self::PERF) && DB::table('performance_reports')->where('person_id', (int) $pid)->where('status', 'draft')->where('updated_at', '>=', $todayStart)->exists();
                $out['members'][] = array('id' => (int) $pid, 'name' => (string) $p['name'], 'today' => $todayPlans,
                    'result' => isset($written[$pid]) ? 'written' : ($draft ? 'draft' : 'none'), 'week' => $week ? round(array_sum($week) / count($week)) : null,
                    'pending' => isset($pendingBy[$pid]) ? $pendingBy[$pid] : 0);
            }
            // the department's long-term and annual plans, lagging first
            foreach ($plans as $id => $p) {
                if ((string) $p['status'] !== 'cancelled' && in_array((string) $p['level'], array('long', 'annual'), true) && $mine($p)) {
                    $out['plans'][] = $planRow($id, $p);
                }
            }
        }
        usort($out['plans'], function ($a, $b) use ($wide) {
            if (!$wide && $a['lagging'] !== $b['lagging']) {
                return $a['lagging'] ? -1 : 1;
            }
            $o = array('long' => 0, 'annual' => 1);
            $la = isset($o[$a['level']]) ? $o[$a['level']] : 2;
            $lb = isset($o[$b['level']]) ? $o[$b['level']] : 2;
            return $la !== $lb ? $la - $lb : ($a['dept'] === $b['dept'] ? $a['id'] - $b['id'] : strcmp($a['dept'], $b['dept']));
        });

        // right column: to-dos, latest notices, devices
        if (class_exists('Omps\Modules\Affairs\Services\TodoService')) {
            $todo = \Omps\Modules\Affairs\Services\TodoService::build($user);
            $out['todo'] = array_slice($todo['rows'], 0, 5);
            $out['todo_total'] = count($todo['rows']);
            foreach (DB::table('notifications')->where('user_id', (int) $user['id'])->orderBy('id', 'DESC')->limit(5)->get() as $n) {
                $out['notices'][] = \Omps\Modules\Affairs\Services\NotificationService::present($n);
            }
        }
        if (class_exists('Omps\Modules\Device\Services\DeviceService') && Permission::can($user, 'device.view')) {
            $q = DB::table('devices d')->active()->where('d.run_status', '<>', 'scrapped');
            \Omps\Modules\Device\Services\DeviceService::scopeQuery($q, $user, 'd');
            $dev = $q->count();
            $q = DB::table('device_faults f')->join('devices d', 'd.id', '=', 'f.device_id')->whereNull('d.deleted_at')->whereIn('f.status', array('open', 'repairing'));
            \Omps\Modules\Device\Services\DeviceService::scopeQuery($q, $user, 'd');
            $faults = $q->count();
            $q = DB::table('device_anomalies a')->join('devices d', 'd.id', '=', 'a.device_id')->whereNull('d.deleted_at')->where('a.status', 'pending');
            \Omps\Modules\Device\Services\DeviceService::scopeQuery($q, $user, 'd');
            $out['devices'] = array('total' => $dev, 'faults' => $faults, 'anomalies' => $q->count());
        }
        if (Permission::isSuper($user)) {
            $out['system'] = self::system();
        }
        return $out;
    }

    /** System status for the super admin: latest backup, people online, scheduled task, missing translations. */
    public static function system()
    {
        $backup = null;
        try {
            $b = DB::selectOne("SELECT file_name, status, started_at, message FROM backup_logs ORDER BY started_at DESC, id DESC LIMIT 1");
            $ok = DB::selectOne("SELECT started_at FROM backup_logs WHERE status = 'ok' ORDER BY started_at DESC LIMIT 1");
            if ($b) {
                $backup = array('at' => (string) $b['started_at'], 'status' => (string) $b['status'], 'message' => $b['message'] ? __((string) $b['message']) : '',
                    'last_ok' => $ok ? (string) $ok['started_at'] : '', 'stale' => !$ok || strtotime((string) $ok['started_at']) < time() - 2 * 86400);
            }
        } catch (\Exception $e) {
            $backup = null;
        }
        $online = (int) DB::value('SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > ? AND last_active > ?',
            array(now(), date('Y-m-d H:i:s', time() - 15 * 60)));
        $cli = (string) Settings::get('scheduler_last_cli', '');
        $any = (string) Settings::get('scheduler_last_run', '');
        $missing = array();
        $missingTotal = 0;
        try {
            $missingTotal = (int) DB::value('SELECT COUNT(*) FROM missing_translations');
            foreach (DB::table('missing_translations')->orderBy('last_seen', 'DESC')->limit(5)->get() as $m) {
                $missing[] = array('lang' => (string) $m['lang'], 'key' => (string) $m['tkey'], 'hits' => (int) $m['hits'], 'last' => (string) $m['last_seen']);
            }
        } catch (\Exception $e) {
            $missingTotal = 0;
        }
        return array('backup' => $backup, 'online' => $online,
            'task' => array('cli' => $cli, 'any' => $any, 'ok' => $cli !== '' && strtotime($cli) > time() - 15 * 60),
            'missing' => $missing, 'missing_total' => $missingTotal);
    }
}
