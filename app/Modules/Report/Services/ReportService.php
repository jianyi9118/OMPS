<?php
namespace Omps\Modules\Report\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Core\Xlsx;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Documents;
use Omps\Engines\Files;
use Omps\Engines\Permission;
use Omps\Modules\Performance\Services\PerformanceService;
use Omps\Modules\Plan\Services\PlanLabel;
use Omps\Modules\Plan\Services\PlanService;

/**
 * Comprehensive reports (design §10, phase 4).
 *
 * Generation: any period × tasks (all / some) × people (all / some), limited by role: whole unit,
 * own department, or oneself. Each chosen task is one section: its own results, then each sub-task
 * in the period with assignees, completion and results; a sub-task without its own results lists
 * the results found below it (per branch, the nearest level that has some), in date order.
 * Only approved results are used; the report keeps a snapshot, so later changes do not alter it.
 *
 * Every report belongs to its creator alone. Each save is a new version. Manual reports have a
 * body and / or uploaded documents.
 */
class ReportService
{
    // ---------- choices for the conditions ----------

    /** People the user may report on: [id => row]. */
    private static function allowedPeople($user)
    {
        $scope = Permission::scope($user);
        $q = DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()
            ->select(array('p.id', 'p.name', 'p.dept_id', 'p.status', 'd.name as dept_name', 'd.sort as dept_sort'));
        if ($scope === 'self') {
            $q->where('p.id', $user['person_id'] === null ? 0 : (int) $user['person_id']);
        } else {
            $q->where('p.status', 'active');
            Permission::scopePersonnel($q, $user, 'p');
        }
        $out = array();
        foreach ($q->orderBy('d.sort')->orderBy('p.name')->get() as $r) {
            $out[(int) $r['id']] = array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'dept_id' => int_or_null($r['dept_id']), 'dept' => str_or_empty($r['dept_name']));
        }
        return $out;
    }

    private static function visiblePlans($user)
    {
        $plans = PlanService::loadAll();
        $vis = PlanService::visibleIds($user, $plans);
        $out = array();
        foreach ($vis as $id => $_) {
            $out[$id] = $plans[$id];
        }
        return array($out, $plans);
    }

    private static function sortIds(array &$ids, array $plans)
    {
        usort($ids, function ($a, $b) use ($plans) {
            if ($plans[$a]['start_date'] !== $plans[$b]['start_date']) {
                return strcmp($plans[$a]['start_date'], $plans[$b]['start_date']);
            }
            $la = array_search($plans[$a]['level'], PlanService::LEVELS, true);
            $lb = array_search($plans[$b]['level'], PlanService::LEVELS, true);
            return $la === $lb ? $a - $b : $la - $lb;
        });
    }

    private static function kidsOf(array $vis)
    {
        $kids = array();
        foreach ($vis as $id => $p) {
            $par = $p['parent_id'] === null ? 0 : (int) $p['parent_id'];
            $kids[isset($vis[$par]) ? $par : 0][] = $id;
        }
        foreach ($kids as $k => $_) {
            self::sortIds($kids[$k], $vis);
        }
        return $kids;
    }

    public static function ranges($today = null)
    {
        $r = PlanService::ranges($today);
        $t = $today === null ? time() : strtotime($today);
        $q = (int) ceil((int) date('n', $t) / 3);
        $r['quarter'] = array(date('Y', $t) . sprintf('-%02d-01', $q * 3 - 2), date('Y-m-t', mktime(0, 0, 0, $q * 3, 1, (int) date('Y', $t))));
        return $r;
    }

    public static function meta(Request $req)
    {
        $user = $req->user;
        list($vis) = self::visiblePlans($user);
        $kids = self::kidsOf($vis);
        $tasks = array();
        $walk = function ($pid, $depth) use (&$walk, &$tasks, $kids, $vis) {
            foreach (isset($kids[$pid]) ? $kids[$pid] : array() as $id) {
                $p = $vis[$id];
                if (in_array($p['level'], array('week', 'day'), true)) {
                    continue;
                }
                $tasks[] = array('id' => $id, 'depth' => $depth, 'level' => (string) $p['level'], 'title' => (string) $p['title'], 'dept_id' => int_or_null($p['dept_id']),
                    'dept' => str_or_empty($p['dept_name']), 'start' => (string) $p['start_date'], 'end' => (string) $p['end_date'], 'tl_mode' => (string) $p['time_label_mode'],
                    'tl_text' => str_or_empty($p['time_label_text']), 'parent_id' => int_or_null($p['parent_id']));
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);
        return array('tasks' => $tasks, 'people' => array_values(self::allowedPeople($user)), 'fixed_person' => Permission::scope($user) === 'self',
            'ranges' => self::ranges(), 'today' => date('Y-m-d'), 'can_export' => Permission::can($user, 'report.export'));
    }

    // ---------- building the content ----------

    private static function planBrief(array $p)
    {
        return array('id' => (int) $p['id'], 'title' => (string) $p['title'], 'level' => (string) $p['level'], 'dept_id' => int_or_null($p['dept_id']), 'dept' => str_or_empty($p['dept_name']),
            'start' => (string) $p['start_date'], 'end' => (string) $p['end_date'], 'tl_mode' => (string) $p['time_label_mode'], 'tl_text' => str_or_empty($p['time_label_text']));
    }

    /**
     * @param array|null $planIds  chosen tasks (null = all)
     * @param array|null $personIds chosen people (null = all the user may report on)
     */
    public static function build($user, $start, $end, $planIds, $personIds)
    {
        list($vis, $all) = self::visiblePlans($user);
        $allowed = self::allowedPeople($user);
        $people = array();
        foreach ($personIds === null ? array_keys($allowed) : $personIds as $pid) {
            if (isset($allowed[(int) $pid])) {
                $people[(int) $pid] = $allowed[(int) $pid]['name'];
            }
        }
        $reports = PerformanceService::byPlans(array_keys($all));
        $prog = PerformanceService::progressMap($all, $reports);
        $kids = self::kidsOf($vis);
        $inPeriod = function ($p) use ($start, $end) {
            return $p['start_date'] <= $end && $p['end_date'] >= $start;
        };
        $memo = array();
        $involves = function ($id) use (&$involves, &$memo, $vis, $kids, $people) {
            if (!isset($memo[$id])) {
                $memo[$id] = false;
                foreach ($vis[$id]['assignees'] as $a) {
                    if (isset($people[$a])) {
                        $memo[$id] = true;
                    }
                }
                foreach (isset($kids[$id]) ? $kids[$id] : array() as $k) {
                    if ($involves($k)) {
                        $memo[$id] = true;
                    }
                }
            }
            return $memo[$id];
        };
        $own = function ($id) use ($vis, $reports, $people) {
            $items = array();
            foreach ($vis[$id]['assignees'] as $a) {
                $r = isset($reports[$id][$a]) ? $reports[$id][$a] : null;
                if ($r !== null && isset($people[$a]) && $r['appr_at'] !== null) {
                    $items[] = array('plan' => self::planBrief($vis[$id]), 'person' => $people[$a], 'progress' => $r['appr_progress'] === null ? null : round((float) $r['appr_progress'], 1),
                        'done' => str_or_empty($r['appr_content']), 'problems' => str_or_empty($r['appr_problems']), 'at' => (string) $r['appr_at']);
                }
            }
            return $items;
        };
        $below = function ($id) use (&$below, $vis, $kids, $inPeriod, $involves, $own) {
            $out = array();
            foreach (isset($kids[$id]) ? $kids[$id] : array() as $k) {
                if (!$inPeriod($vis[$k]) || !$involves($k)) {
                    continue;
                }
                $o = $own($k);
                $out = array_merge($out, $o ? $o : $below($k));
            }
            usort($out, function ($a, $b) {
                if ($a['plan']['start'] !== $b['plan']['start']) {
                    return strcmp($a['plan']['start'], $b['plan']['start']);
                }
                return array_search($a['plan']['level'], PlanService::LEVELS, true) - array_search($b['plan']['level'], PlanService::LEVELS, true);
            });
            return $out;
        };
        // sections: chosen tasks without a chosen ancestor, or the top visible plans
        $roots = array();
        if ($planIds === null) {
            foreach (isset($kids[0]) ? $kids[0] : array() as $id) {
                if ($inPeriod($vis[$id]) && $involves($id)) {
                    $roots[] = $id;
                }
            }
        } else {
            $chosen = array();
            foreach ($planIds as $id) {
                if (isset($vis[(int) $id])) {
                    $chosen[(int) $id] = true;
                }
            }
            foreach ($chosen as $id => $_) {
                $q = $vis[$id];
                $covered = false;
                while ($q['parent_id'] !== null && isset($vis[(int) $q['parent_id']])) {
                    if (isset($chosen[(int) $q['parent_id']])) {
                        $covered = true;
                        break;
                    }
                    $q = $vis[(int) $q['parent_id']];
                }
                if (!$covered && $inPeriod($vis[$id]) && $involves($id)) {
                    $roots[] = $id;
                }
            }
            self::sortIds($roots, $vis);
        }
        $sections = array();
        $count = 0;
        foreach ($roots as $id) {
            $sec = array('plan' => self::planBrief($vis[$id]), 'progress' => isset($prog[$id]) ? $prog[$id] : 0.0, 'assignees' => $vis[$id]['assignee_names'], 'own' => $own($id), 'children' => array());
            $count += count($sec['own']);
            foreach (isset($kids[$id]) ? $kids[$id] : array() as $k) {
                if (!$inPeriod($vis[$k]) || !$involves($k)) {
                    continue;
                }
                $o = $own($k);
                $b = $o ? array() : $below($k);
                $count += count($o) + count($b);
                $sec['children'][] = array('plan' => self::planBrief($vis[$k]), 'progress' => isset($prog[$k]) ? $prog[$k] : 0.0, 'assignees' => $vis[$k]['assignee_names'], 'own' => $o, 'below' => $b);
            }
            $sections[] = $sec;
        }
        $taskTitles = null;
        if ($planIds !== null) {
            $taskTitles = array();
            foreach ($planIds as $id) {
                if (isset($vis[(int) $id])) {
                    $taskTitles[] = (string) $vis[(int) $id]['title'];
                }
            }
        }
        return array('start' => $start, 'end' => $end, 'tasks' => $taskTitles, 'people' => $personIds === null ? null : array_values($people),
            'sections' => $sections, 'stats' => array('tasks' => count($sections), 'items' => $count));
    }

    private static function checkConditions($user, array $in)
    {
        $v = Validator::check($in, array('start' => 'required|date', 'end' => 'required|date', 'plan_ids' => 'nullable|array', 'person_ids' => 'nullable|array'),
            array('start' => 'report.field.start', 'end' => 'report.field.end', 'plan_ids' => 'report.field.tasks', 'person_ids' => 'report.field.people'));
        if ($v['end'] < $v['start']) {
            throw ValidationException::field('end', 'plan.err.end_before_start');
        }
        $plans = null;
        if (isset($in['plan_ids']) && is_array($in['plan_ids'])) {
            list($vis) = self::visiblePlans($user);
            $plans = array();
            foreach ($v['plan_ids'] as $id) {
                if (!isset($vis[(int) $id])) {
                    throw ValidationException::field('plan_ids', 'report.err.task_scope');
                }
                $plans[(int) $id] = (int) $id;
            }
            if (!$plans) {
                throw ValidationException::field('plan_ids', 'report.err.task_required');
            }
            $plans = array_values($plans);
        }
        $people = null;
        if (Permission::scope($user) === 'self') {
            if ($user['person_id'] === null) {
                throw HttpException::forbidden();
            }
            $people = array((int) $user['person_id']);
        } elseif (isset($in['person_ids']) && is_array($in['person_ids'])) {
            $allowed = self::allowedPeople($user);
            $people = array();
            foreach ($v['person_ids'] as $id) {
                if (!isset($allowed[(int) $id])) {
                    throw ValidationException::field('person_ids', 'report.err.person_scope');
                }
                $people[(int) $id] = (int) $id;
            }
            if (!$people) {
                throw ValidationException::field('person_ids', 'report.err.person_required');
            }
            $people = array_values($people);
        }
        return array($v['start'], $v['end'], $plans, $people);
    }

    public static function generate(Request $req, array $in)
    {
        list($start, $end, $plans, $people) = self::checkConditions($req->user, $in);
        $content = self::build($req->user, $start, $end, $plans, $people);
        $uid = $req->userId();
        $id = DB::transaction(function () use ($start, $end, $plans, $people, $content, $uid) {
            $id = DB::table('reports')->insert(array('org_id' => (int) config('app.org_id', 1), 'kind' => 'gen', 'title' => null, 'period_start' => $start, 'period_end' => $end,
                'plan_ids' => $plans === null ? null : json_encode($plans), 'person_ids' => $people === null ? null : json_encode($people), 'data_as_of' => now(),
                'current_version' => 1, 'created_by' => $uid, 'created_at' => now()));
            DB::table('report_versions')->insert(array('report_id' => $id, 'version_no' => 1, 'title' => null, 'period_start' => $start, 'period_end' => $end,
                'content' => json_encode($content, JSON_UNESCAPED_UNICODE), 'created_by' => $uid, 'created_at' => now()));
            return $id;
        });
        Audit::op($req->user, 'report', 'report_generated', 'report', $id, self::defaultTitle($start, $end), array('tasks' => $content['stats']['tasks'], 'items' => $content['stats']['items']), $req->ip());
        return array('id' => $id);
    }

    // ---------- list ----------

    public static function defaultTitle($start, $end)
    {
        return __('report.default_title', array('start' => $start, 'end' => $end));
    }

    private static function displayTitle(array $r)
    {
        if ($r['title'] !== null && (string) $r['title'] !== '') {
            return (string) $r['title'];
        }
        return self::defaultTitle((string) $r['period_start'], (string) $r['period_end']);
    }

    private static function docCount($id)
    {
        return (int) DB::value('SELECT COUNT(*) FROM file_relations fr JOIN files f ON f.id = fr.file_id WHERE fr.biz_type = ? AND fr.biz_id = ? AND f.status = ?', array('report', (int) $id, 'normal'));
    }

    /**
     * Own reports. per = all | week | month | year | custom (start / end): a report shows when its
     * period lies completely inside the range; a report without a period counts by its creation date.
     */
    /**
     * My reports, paged. Filters: a period (from / to, or the older per / start / end) keeps the reports
     * whose period lies inside it; kw is looked for in the title. total = after filtering, all = before.
     */
    public static function index(Request $req, array $f, $page = 1, $size = 30)
    {
        $rows = DB::table('reports')->where('created_by', $req->userId())->whereNull('deleted_at')->orderBy('id', 'DESC')->get();
        $range = null;
        $per = isset($f['per']) ? (string) $f['per'] : 'all';
        $ranges = self::ranges();
        $from = isset($f['from']) ? (string) $f['from'] : '';
        $to = isset($f['to']) ? (string) $f['to'] : '';
        if ($from !== '' || $to !== '') {
            $range = array($from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31');
        } elseif (isset($ranges[$per]) && $per !== 'quarter') {
            $range = $ranges[$per];
        } elseif ($per === 'custom') {
            $s = isset($f['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['start']) ? (string) $f['start'] : '0000-01-01';
            $e = isset($f['end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['end']) ? (string) $f['end'] : '9999-12-31';
            $range = array($s, $e);
        }
        $kw = isset($f['kw']) ? mb_strtolower(trim((string) $f['kw'])) : '';
        $out = array();
        foreach ($rows as $r) {
            $ps = $r['period_start'] !== null ? (string) $r['period_start'] : substr((string) $r['created_at'], 0, 10);
            $pe = $r['period_end'] !== null ? (string) $r['period_end'] : substr((string) $r['created_at'], 0, 10);
            if ($range !== null && ($ps < $range[0] || $pe > $range[1])) {
                continue;
            }
            $title = self::displayTitle($r);
            if ($kw !== '' && mb_strpos(mb_strtolower($title), $kw) === false) {
                continue;
            }
            $out[] = array('id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'title' => $title, 'start' => str_or_empty($r['period_start']), 'end' => str_or_empty($r['period_end']),
                'docs' => self::docCount((int) $r['id']), 'version' => (int) $r['current_version'], 'updated_at' => (string) ($r['updated_at'] !== null ? $r['updated_at'] : $r['created_at']));
        }
        usort($out, function ($a, $b) {
            return $a['updated_at'] === $b['updated_at'] ? $b['id'] - $a['id'] : strcmp($b['updated_at'], $a['updated_at']);
        });
        $page = max(1, (int) $page);
        $size = min(200, max(1, (int) $size));
        return array('rows' => array_slice($out, ($page - 1) * $size, $size), 'total' => count($out), 'page' => $page, 'size' => $size, 'all' => count($rows),
            'ranges' => $ranges);
    }

    // ---------- one report ----------

    private static function own(Request $req, $id)
    {
        $r = DB::table('reports')->where('id', (int) $id)->whereNull('deleted_at')->first();
        if (!$r || (int) $r['created_by'] !== $req->userId()) {
            if ($r) {
                Audit::denied($req, 'report', array('id' => (int) $id));
            }
            throw HttpException::notFound();
        }
        return $r;
    }

    private static function version(array $r, $no)
    {
        $no = $no ? (int) $no : (int) $r['current_version'];
        $v = DB::table('report_versions')->where('report_id', (int) $r['id'])->where('version_no', $no)->first();
        if (!$v) {
            throw HttpException::notFound();
        }
        return $v;
    }

    public static function show(Request $req, $id, $no = null)
    {
        $r = self::own($req, $id);
        $v = self::version($r, $no);
        $versions = array();
        foreach (DB::table('report_versions v')->leftJoin('users u', 'u.id', '=', 'v.created_by')->where('v.report_id', (int) $r['id'])
            ->select(array('v.version_no', 'v.created_at', 'u.display_name as by_name'))->orderBy('v.version_no', 'DESC')->get() as $x) {
            $versions[] = array('no' => (int) $x['version_no'], 'at' => (string) $x['created_at'], 'by' => str_or_empty($x['by_name']));
        }
        $title = $v['title'] !== null && (string) $v['title'] !== '' ? (string) $v['title'] : ((string) $r['kind'] === 'gen' ? self::defaultTitle((string) $v['period_start'], (string) $v['period_end']) : '');
        return array(
            'id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'title' => $title, 'title_custom' => str_or_empty($v['title']),
            'start' => str_or_empty($v['period_start']), 'end' => str_or_empty($v['period_end']), 'as_of' => str_or_empty($r['data_as_of']),
            'version' => (int) $v['version_no'], 'current' => (int) $r['current_version'], 'versions' => $versions,
            'intro' => str_or_empty($v['intro']), 'outro' => str_or_empty($v['outro']), 'body' => str_or_empty($v['body']),
            'content' => $v['content'] === null ? null : json_decode((string) $v['content'], true), 'docs' => Files::forRecord('report', (int) $r['id']),
        );
    }

    /** Generated: title and the two texts. Manual: name, period and body. Each save adds a version. */
    public static function update(Request $req, $id, array $in)
    {
        $r = self::own($req, $id);
        $cur = self::version($r, null);
        if (isset($in['version']) && (int) $in['version'] !== (int) $r['current_version']) {
            throw HttpException::conflict('error.stale');
        }
        if ((string) $r['kind'] === 'gen') {
            $v = Validator::check($in, array('title' => 'nullable|string|max:200', 'intro' => 'nullable|string|max:5000', 'outro' => 'nullable|string|max:5000'),
                array('title' => 'report.field.title', 'intro' => 'report.field.intro', 'outro' => 'report.field.outro'));
            $row = array('title' => self::opt($v, 'title'), 'period_start' => $cur['period_start'], 'period_end' => $cur['period_end'],
                'intro' => self::opt($v, 'intro'), 'outro' => self::opt($v, 'outro'), 'content' => $cur['content'], 'body' => null);
        } else {
            $v = self::manualFields($in);
            if ($v['body'] === null && self::docCount((int) $r['id']) === 0) {
                throw ValidationException::field('body', 'report.err.body_or_doc');
            }
            $row = array('title' => $v['title'], 'period_start' => $v['start'], 'period_end' => $v['end'], 'intro' => null, 'outro' => null, 'content' => null, 'body' => $v['body']);
        }
        $no = (int) $r['current_version'] + 1;
        $uid = $req->userId();
        DB::transaction(function () use ($r, $row, $no, $uid) {
            DB::table('report_versions')->insert(array_merge($row, array('report_id' => (int) $r['id'], 'version_no' => $no, 'created_by' => $uid, 'created_at' => now())));
            $n = DB::table('reports')->where('id', (int) $r['id'])->where('current_version', (int) $r['current_version'])->update(array('title' => $row['title'],
                'period_start' => $row['period_start'], 'period_end' => $row['period_end'], 'current_version' => $no, 'updated_at' => now()));
            if ($n === 0) {
                throw HttpException::conflict('error.stale');
            }
        });
        $label = $row['title'] !== null ? $row['title'] : self::defaultTitle((string) $row['period_start'], (string) $row['period_end']);
        Audit::op($req->user, 'report', 'report_updated', 'report', (int) $r['id'], $label, array('version' => $no), $req->ip());
        return array('version' => $no);
    }

    private static function opt(array $v, $k)
    {
        return isset($v[$k]) && $v[$k] !== null && trim((string) $v[$k]) !== '' ? (string) $v[$k] : null;
    }

    private static function manualFields(array $in)
    {
        $v = Validator::check($in, array('title' => 'required|string|max:200', 'start' => 'required|date', 'end' => 'required|date', 'body' => 'nullable|string|max:50000'),
            array('title' => 'report.field.name', 'start' => 'report.field.start', 'end' => 'report.field.end', 'body' => 'report.field.body'));
        if ($v['end'] < $v['start']) {
            throw ValidationException::field('end', 'plan.err.end_before_start');
        }
        $v['body'] = self::opt($v, 'body');
        return $v;
    }

    /** New manual report (multipart: fields plus optional files). Body or documents are required. */
    public static function createManual(Request $req, array $in)
    {
        $v = self::manualFields($in);
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if ($v['body'] === null && !$list) {
            throw ValidationException::field('body', 'report.err.body_or_doc');
        }
        foreach ($list as $up) {
            Files::check($up);
        }
        $uid = $req->userId();
        $id = DB::transaction(function () use ($v, $uid) {
            $id = DB::table('reports')->insert(array('org_id' => (int) config('app.org_id', 1), 'kind' => 'manual', 'title' => $v['title'], 'period_start' => $v['start'],
                'period_end' => $v['end'], 'current_version' => 1, 'created_by' => $uid, 'created_at' => now()));
            DB::table('report_versions')->insert(array('report_id' => $id, 'version_no' => 1, 'title' => $v['title'], 'period_start' => $v['start'], 'period_end' => $v['end'],
                'body' => $v['body'], 'created_by' => $uid, 'created_at' => now()));
            return $id;
        });
        Audit::op($req->user, 'report', 'report_created', 'report', $id, $v['title'], null, $req->ip());
        if ($list) {
            self::storeDocs($req, DB::table('reports')->find($id), $list);
        }
        return array('id' => $id);
    }

    private static function storeDocs(Request $req, array $r, array $list)
    {
        $folders = array(Files::folder('file.folder.reports'), (string) $req->user['name'], self::displayTitle($r));
        $out = array();
        foreach ($list as $up) {
            $out[] = Files::store($req, $up, 'report', (int) $r['id'], 'doc', $folders, $req->user['person_id']);
        }
        return $out;
    }

    public static function upload(Request $req, $id)
    {
        $r = self::own($req, $id);
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        self::storeDocs($req, $r, $list);
        DB::table('reports')->where('id', (int) $r['id'])->update(array('updated_at' => now()));
        return array('docs' => Files::forRecord('report', (int) $r['id']));
    }

    public static function fileAccess(Request $req, $reportId, $write)
    {
        self::own($req, $reportId);
    }

    /** Delete with all versions; its documents go to the recycle bin. */
    public static function delete(Request $req, $id)
    {
        $r = self::own($req, $id);
        $uid = $req->userId();
        DB::transaction(function () use ($r, $uid) {
            Files::removeRelations(Files::relationsOf('report', array((int) $r['id'])), $uid);
            DB::table('reports')->where('id', (int) $r['id'])->update(array('deleted_at' => now(), 'deleted_by' => $uid));
        });
        Audit::op($req->user, 'report', 'report_deleted', 'report', (int) $r['id'], self::displayTitle($r), null, $req->ip());
        return array('ok' => true);
    }

    // ---------- print and download ----------

    private static function logExport(Request $req, array $r, $no, $format)
    {
        DB::table('report_exports')->insert(array('report_id' => (int) $r['id'], 'version_no' => (int) $no, 'format' => $format, 'exported_by' => $req->userId(), 'created_at' => now()));
        Audit::op($req->user, 'report', $format === 'print' ? 'report_printed' : 'report_downloaded', 'report', (int) $r['id'], self::displayTitle($r), array('format' => $format, 'version' => (int) $no), $req->ip());
    }

    public static function printed(Request $req, $id, $no)
    {
        $r = self::own($req, $id);
        $v = self::version($r, $no);
        self::logExport($req, $r, (int) $v['version_no'], 'print');
        return array('ok' => true);
    }

    private static function pct($v)
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }

    /** Conditions line of a generated report. */
    private static function conditions(array $c)
    {
        $sep = __('common.list_sep');
        return ($c['tasks'] === null ? __('report.meta_all_tasks') : __('report.meta_tasks', array('list' => implode($sep, $c['tasks']))))
            . '  ·  ' . ($c['people'] === null ? __('report.meta_all_people') : __('report.meta_people', array('list' => implode($sep, $c['people']))));
    }

    private static function intro(array $d)
    {
        if ($d['kind'] !== 'gen') {
            return '';
        }
        $c = $d['content'];
        return $d['intro'] !== '' ? $d['intro'] : __('report.default_intro', array('start' => $c['start'], 'end' => $c['end'], 'tasks' => $c['stats']['tasks'], 'items' => $c['stats']['items']));
    }

    /** The report as text blocks in the current language (Word download without zip). */
    public static function blocks(array $d)
    {
        $b = array(array('type' => 'title', 'text' => $d['title']));
        if ($d['kind'] === 'manual') {
            if ($d['start'] !== '') {
                $b[] = array('type' => 'meta', 'text' => __('report.period', array('start' => $d['start'], 'end' => $d['end'])));
            }
            return array_merge($b, self::bodyBlocks($d));
        }
        $b[] = array('type' => 'meta', 'text' => self::conditions($d['content']));
        $b[] = array('type' => 'p', 'text' => self::intro($d));
        $b = array_merge($b, self::bodyBlocks($d));
        if ($d['outro'] !== '') {
            $b[] = array('type' => 'p', 'text' => $d['outro']);
        }
        $b[] = array('type' => 'meta', 'text' => __('report.as_of', array('time' => $d['as_of'])));
        return $b;
    }

    /** The body: sections with results (generated) or the paragraphs of the text (manual). */
    public static function bodyBlocks(array $d)
    {
        $b = array();
        if ($d['kind'] === 'manual') {
            foreach (preg_split('/\n{2,}/', $d['body']) as $para) {
                if (trim($para) !== '') {
                    $b[] = array('type' => 'p', 'text' => $para);
                }
            }
            return $b;
        }
        $sep = __('common.list_sep');
        $c = $d['content'];
        $item = function ($it) {
            $txt = $it['done'] . ($it['problems'] !== '' ? "\n" . __('report.problems', array('text' => $it['problems'])) : '');
            return array('type' => 'item', 'tag' => PlanLabel::label($it['plan']) . '  ' . $it['person'] . __('common.colon'), 'text' => $txt);
        };
        foreach ($c['sections'] as $i => $s) {
            $b[] = array('type' => 'h1', 'text' => __('report.sec_head', array('n' => $i + 1, 'title' => $s['plan']['title'])),
                'tag' => __('report.sec_meta', array('scope' => $s['plan']['dept'] !== '' ? $s['plan']['dept'] : __('plan.unit'), 'pct' => self::pct($s['progress']))));
            if ($s['own']) {
                $b[] = array('type' => 'note', 'text' => __('report.own_results'));
                foreach ($s['own'] as $it) {
                    $b[] = $item($it);
                }
            }
            foreach ($s['children'] as $j => $ch) {
                $b[] = array('type' => 'h2', 'text' => __('report.child_head', array('n' => $j + 1, 'title' => $ch['plan']['title'])) . '  (' . PlanLabel::label($ch['plan']) . ')');
                $meta = __('report.child_meta', array('people' => implode($sep, $ch['assignees']), 'pct' => self::pct($ch['progress'])));
                if ($ch['own']) {
                    $b[] = array('type' => 'note', 'text' => $meta);
                    foreach ($ch['own'] as $it) {
                        $b[] = $item($it);
                    }
                } elseif ($ch['below']) {
                    $b[] = array('type' => 'note', 'text' => $meta . '  ·  ' . __('report.no_own_below'));
                    foreach ($ch['below'] as $it) {
                        $b[] = $item($it);
                    }
                } else {
                    $b[] = array('type' => 'note', 'text' => $meta . '  ·  ' . __('report.no_results'));
                }
            }
            if (!$s['own'] && !$s['children']) {
                $b[] = array('type' => 'note', 'text' => __('report.no_results'));
            }
        }
        return $b;
    }

    /** Model for a Word template (Documents engine). */
    public static function docModel(Request $req, array $d)
    {
        $f = Documents::base();
        $f['title'] = $d['title'];
        $f['period'] = $d['start'] !== '' ? __('report.period', array('start' => $d['start'], 'end' => $d['end'])) : '';
        $f['conditions'] = $d['kind'] === 'gen' ? self::conditions($d['content']) : '';
        $f['intro'] = self::intro($d);
        $f['outro'] = $d['outro'];
        $f['as_of'] = $d['kind'] === 'gen' && $d['as_of'] !== '' ? __('report.as_of', array('time' => $d['as_of'])) : '';
        $f['author'] = (string) $req->user['name'];
        $results = array();
        if ($d['kind'] === 'gen') {
            $keys = array('section', 'task', 'level', 'time', 'assignees', 'progress', 'kind', 'result_of', 'person', 'result_progress', 'done', 'problems');
            foreach (self::rows($d) as $row) {
                $r = array_combine($keys, $row);
                unset($r['kind'], $r['result_of']);
                $results[] = $r;
            }
        }
        return array('fields' => $f, 'blocks' => array('body' => self::bodyBlocks($d)), 'lists' => array('results' => $results), 'images' => array(), 'custom' => array(),
            'fallback' => self::blocks($d));
    }

    /** Detail rows (Excel download). */
    private static function rows(array $d)
    {
        $rows = array();
        $sep = __('common.list_sep');
        foreach ($d['content']['sections'] as $s) {
            $add = function ($task, $kind, $it) use (&$rows, $s, $sep) {
                $rows[] = array($s['plan']['title'], $task['plan']['title'], __('plan.level.' . $task['plan']['level']), PlanLabel::label($task['plan']), implode($sep, $task['assignees']),
                    self::pct($task['progress']) . '%', $kind, $it ? PlanLabel::label($it['plan']) . ' · ' . $it['plan']['title'] : '', $it ? $it['person'] : '',
                    $it && $it['progress'] !== null ? self::pct($it['progress']) . '%' : '', $it ? $it['done'] : '', $it ? $it['problems'] : '');
            };
            if (!$s['own']) {
                $add($s, '', null);
            }
            foreach ($s['own'] as $it) {
                $add($s, __('report.kind_own'), $it);
            }
            foreach ($s['children'] as $ch) {
                $list = $ch['own'] ? $ch['own'] : $ch['below'];
                if (!$list) {
                    $add($ch, '', null);
                }
                foreach ($list as $it) {
                    $add($ch, __($ch['own'] ? 'report.kind_own' : 'report.kind_below'), $it);
                }
            }
        }
        return $rows;
    }

    public static function download(Request $req, $id, $no, $format, $tplId = null)
    {
        if (!Permission::can($req->user, 'report.export')) {
            throw HttpException::forbidden();
        }
        $r = self::own($req, $id);
        $d = self::show($req, $id, $no);
        $name = Files::safeName($d['title'], 80);
        if ($format === 'xlsx') {
            if ($d['kind'] !== 'gen') {
                throw HttpException::bad('report.err.no_details');
            }
            $header = array(__('report.x.section'), __('report.x.task'), __('report.x.level'), __('report.x.time'), __('report.x.assignees'), __('report.x.progress'),
                __('report.x.kind'), __('report.x.result_of'), __('report.x.person'), __('report.x.result_progress'), __('report.x.done'), __('report.x.problems'));
            $bytes = Xlsx::write($header, self::rows($d), __('menu.reports'));
            self::logExport($req, $r, $d['version'], 'xlsx');
            return new Response($bytes, 200, array('Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="export.xlsx"; filename*=UTF-8\'\'' . rawurlencode($name . '.xlsx')));
        }
        $tpl = $tplId === null || $tplId === '' ? null : (int) $tplId;
        $format = Documents::available() ? 'docx' : 'doc';
        $resp = Documents::download($req, 'report', self::docModel($req, $d), $tpl, $name, 'report', (int) $r['id']);
        self::logExport($req, $r, $d['version'], $format);
        return $resp;
    }
}
