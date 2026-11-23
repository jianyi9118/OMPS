<?php
namespace Omps\Modules\Plan\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;

/**
 * Plans from long-term to daily (design §08–§09, phase 3).
 *
 * Who sees what (data scope):
 *  - whole unit: every plan
 *  - department: plans of the own department and its groups, plus unit plans
 *  - self: plans assigned to me and plans I created, plus their parents as grey context
 * Who may do what:
 *  - manage (plan.assign + scope): create main plans (long-term / annual), add sub-plans,
 *    edit and delete plans of the departments in scope, choose assignees (several allowed)
 *  - assignee (plan.add): add own sub-plans (quarter and below) under tasks given to them;
 *    edit and delete the sub-plans they created
 * The tree is kept in parent_id plus the closure table plan_paths (no recursive SQL).
 */
class PlanService
{
    /**
     * The level is not chosen: it follows from the dates (levelOf), as the smallest calendar period that
     * holds them. A plan may hold sub-plans of its own level or lower, so a long-term plan can be split into
     * several multi-year goals, each into yearly goals, those into goals of any length (Jan–May), and so on.
     * Leaders may add any of those; members only plans that fit in one quarter (MEMBER_LEVELS).
     */
    const LEVELS = array('long', 'annual', 'quarter', 'month', 'week', 'day');
    const MEMBER_LEVELS = array('quarter', 'month', 'week', 'day');
    const TL_MODES = array('auto', 'span', 'year', 'half', 'q', 'm', 'w', 'text');

    /** Level of a date range: one day, one ISO week, one month, one quarter, one year, or longer. */
    public static function levelOf($start, $end)
    {
        if ($start === $end) {
            return 'day';
        }
        $ts = strtotime($start);
        $te = strtotime($end);
        if (date('o-W', $ts) === date('o-W', $te)) {
            return 'week';
        }
        if (substr($start, 0, 7) === substr($end, 0, 7)) {
            return 'month';
        }
        if (substr($start, 0, 4) === substr($end, 0, 4)) {
            return (int) ceil((int) substr($start, 5, 2) / 3) === (int) ceil((int) substr($end, 5, 2) / 3) ? 'quarter' : 'annual';
        }
        return 'long';
    }

    // ---------- context of the current user ----------

    private static function ctx($user)
    {
        static $cache = array();
        $key = $user['id'] . '|' . $user['role_id'];
        if (!isset($cache[$key])) {
            $scope = Permission::scope($user);
            $cache[$key] = array(
                'scope' => $scope,
                'wide' => $scope === 'all' || $scope === 'unit',
                'depts' => Permission::deptIds($user),
                'assign' => Permission::can($user, 'plan.assign') && $scope !== 'self',
                'add' => Permission::can($user, 'plan.add'),
                'edit' => Permission::can($user, 'plan.edit'),
                'del' => Permission::can($user, 'plan.del'),
                'person' => $user['person_id'],
            );
        }
        return $cache[$key];
    }

    /** Does the user manage this plan (department scope covers it, or whole-unit scope)? */
    private static function manages(array $c, array $p)
    {
        if (!$c['assign']) {
            return false;
        }
        if ($c['wide']) {
            return true;
        }
        return $p['dept_id'] !== null && $c['depts'] !== null && in_array((int) $p['dept_id'], $c['depts'], true);
    }

    private static function isAssignee(array $c, array $assignees)
    {
        return $c['person'] !== null && in_array((int) $c['person'], $assignees, true);
    }

    private static function isOwner(array $c, array $p)
    {
        return $c['person'] !== null && (int) $p['owner_person_id'] === (int) $c['person'];
    }

    /** Levels the user may add under a plan: its own level and lower (none under a day plan). Empty = none. */
    public static function childLevels(array $c, array $p, array $assignees)
    {
        if (!$c['add'] || (string) $p['level'] === 'day') {
            return array();
        }
        $below = array_slice(self::LEVELS, (int) array_search((string) $p['level'], self::LEVELS, true));
        if (self::leads($c, $p)) {
            return $below;
        }
        if (self::isAssignee($c, $assignees) || self::isOwner($c, $p)) {
            return array_values(array_intersect($below, self::MEMBER_LEVELS));
        }
        return array();
    }

    /** May the user add sub-plans here as a leader (any length, choosing the assignees)? */
    private static function leads(array $c, array $p)
    {
        return self::manages($c, $p) || ($c['assign'] && $p['dept_id'] === null);
    }

    /** The error for a level that may not be added under $parent (null = allowed). */
    private static function levelError(array $c, $parent, $level)
    {
        if ($parent === null) {
            return null;
        }
        $allowed = self::childLevels($c, $parent, $parent['assignees']);
        if (in_array($level, $allowed, true)) {
            return null;
        }
        if (array_search($level, self::LEVELS, true) < array_search((string) $parent['level'], self::LEVELS, true)) {
            return array('plan.err.longer_than_parent', array('level' => __('plan.level.' . $parent['level'])));
        }
        return array('plan.err.member_span', array());
    }

    public static function caps($user, array $p, array $assignees)
    {
        $c = self::ctx($user);
        $own = self::isOwner($c, $p);
        $mgr = self::manages($c, $p);
        return array(
            'add' => self::childLevels($c, $p, $assignees),
            'edit' => $c['edit'] && ($mgr || $own),
            'del' => $c['del'] && ($mgr || $own),
            'assign' => $mgr,
        );
    }

    // ---------- loading ----------

    /** All active plans with assignees, keyed by id. */
    public static function loadAll(?array $ids = null)
    {
        $q = DB::table('plans p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->leftJoin('personnel_profiles o', 'o.id', '=', 'p.owner_person_id')
            ->active()->select(array('p.*', 'd.name as dept_name', 'o.name as owner_name'));
        if ($ids !== null) {
            $q->whereIn('p.id', $ids);
        }
        $plans = array();
        foreach ($q->get() as $r) {
            $r['assignees'] = array();
            $r['assignee_names'] = array();
            $plans[(int) $r['id']] = $r;
        }
        if ($plans) {
            $rows = DB::table('plan_assignments a')->join('personnel_profiles pp', 'pp.id', '=', 'a.person_id')
                ->select(array('a.plan_id', 'a.person_id', 'pp.name'))->whereIn('a.plan_id', array_keys($plans))->orderBy('a.id')->get();
            foreach ($rows as $a) {
                $pid = (int) $a['plan_id'];
                $plans[$pid]['assignees'][] = (int) $a['person_id'];
                $plans[$pid]['assignee_names'][] = (string) $a['name'];
            }
        }
        return $plans;
    }

    /** Plans the user may see: [id => true]. */
    public static function visibleIds($user, array $plans)
    {
        $c = self::ctx($user);
        $out = array();
        foreach ($plans as $id => $p) {
            if ($c['wide']) {
                $out[$id] = true;
            } elseif ($c['scope'] === 'dept' && ($p['dept_id'] === null || in_array((int) $p['dept_id'], $c['depts'], true))) {
                $out[$id] = true;
            } elseif (self::isAssignee($c, $p['assignees']) || self::isOwner($c, $p)) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    /** One plan the user may see, else 404 (logged). */
    public static function find(Request $req, $id)
    {
        $plans = self::loadAll();
        $id = (int) $id;
        if (!isset($plans[$id])) {
            throw HttpException::notFound();
        }
        $vis = self::visibleIds($req->user, $plans);
        if (!isset($vis[$id])) {
            Audit::denied($req, 'plan', array('id' => $id));
            throw HttpException::notFound();
        }
        return array($plans[$id], $plans);
    }

    // ---------- time progress and lag (design §17: one rule everywhere) ----------

    /** Points of completion a plan may be behind its time progress before it counts as lagging. */
    const LAG_POINTS = 10;

    /** Share of the plan's time already used, 0-100. */
    public static function timePct($start, $end, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $s = strtotime((string) $start . ' 00:00:00');
        $e = strtotime((string) $end . ' 23:59:59');
        if ($s === false || $e === false || $e <= $s || $now <= $s) {
            return 0.0;
        }
        return round(min(1, ($now - $s) / ($e - $s)) * 100, 1);
    }

    /**
     * Lagging: completion more than LAG_POINTS behind the time progress. Week and day plans are not
     * judged (they are followed through the daily results), nor plans that are finished or cancelled.
     */
    public static function lagging($level, $status, $start, $end, $progress, $now = null)
    {
        if (in_array((string) $level, array('week', 'day'), true) || (string) $status !== 'active') {
            return false;
        }
        return (float) $progress < self::timePct($start, $end, $now) - self::LAG_POINTS;
    }

    /** not_started | in_progress | ended | done | cancelled */
    public static function state(array $p, $today = null)
    {
        $today = $today === null ? date('Y-m-d') : $today;
        if ((string) $p['status'] !== 'active') {
            return (string) $p['status'] === 'done' ? 'done' : 'cancelled';
        }
        if ($today < (string) $p['start_date']) {
            return 'not_started';
        }
        return $today > (string) $p['end_date'] ? 'ended' : 'in_progress';
    }

    // ---------- periods ----------

    public static function ranges($today = null)
    {
        $t = $today === null ? time() : strtotime($today);
        $dow = (int) date('N', $t);
        return array(
            'week' => array(date('Y-m-d', $t - ($dow - 1) * 86400), date('Y-m-d', $t + (7 - $dow) * 86400)),
            'month' => array(date('Y-m-01', $t), date('Y-m-t', $t)),
            'year' => array(date('Y-01-01', $t), date('Y-12-31', $t)),
        );
    }

    public static function periodKey($level, $start, $end)
    {
        $ts = strtotime($start);
        switch ($level) {
            case 'long':
                return substr($start, 0, 4) . '-' . substr($end, 0, 4);
            case 'annual':
                return substr($start, 0, 4);
            case 'quarter':
                return substr($start, 0, 4) . '-Q' . (int) ceil((int) substr($start, 5, 2) / 3);
            case 'month':
                return substr($start, 0, 7);
            case 'week':
                return date('o', $ts) . '-W' . date('W', $ts);
        }
        return $start;
    }

    // ---------- lists ----------

    private static function present(array $p, $user, $depth, $context)
    {
        return array(
            'id' => (int) $p['id'], 'parent_id' => int_or_null($p['parent_id']), 'depth' => $depth, 'level' => (string) $p['level'], 'title' => (string) $p['title'],
            'dept_id' => int_or_null($p['dept_id']), 'dept' => str_or_empty($p['dept_name']), 'start' => (string) $p['start_date'], 'end' => (string) $p['end_date'],
            'tl_mode' => (string) $p['time_label_mode'], 'tl_text' => str_or_empty($p['time_label_text']), 'weight' => (float) $p['local_weight'],
            'priority' => str_or_empty($p['priority']), 'status' => (string) $p['status'], 'owner' => str_or_empty($p['owner_name']),
            'owner_person_id' => int_or_null($p['owner_person_id']), 'assignees' => $p['assignees'], 'assignee_names' => $p['assignee_names'],
            'context' => $context, 'caps' => $context ? array('add' => array(), 'edit' => false, 'del' => false, 'assign' => false) : self::caps($user, $p, $p['assignees']),
        );
    }

    private static function sortKids(array &$kids, array $plans)
    {
        usort($kids, function ($a, $b) use ($plans) {
            $pa = $plans[$a];
            $pb = $plans[$b];
            if ($pa['start_date'] !== $pb['start_date']) {
                return strcmp($pa['start_date'], $pb['start_date']);
            }
            $la = array_search($pa['level'], self::LEVELS, true);
            $lb = array_search($pb['level'], self::LEVELS, true);
            return $la === $lb ? $a - $b : $la - $lb;
        });
    }

    /**
     * Flat tree for a page.
     *  mode 'mine': tasks assigned to me and the sub-plans under them assigned to me.
     *  mode 'tree': everything visible; a plan shows when it matches the filters or one of its
     *               sub-plans does (non-matching parents are shown grey as context).
     */
    public static function tree(Request $req, $mode, array $f)
    {
        $user = $req->user;
        $c = self::ctx($user);
        $plans = self::loadAll();
        $vis = self::visibleIds($user, $plans);
        if ($mode === 'mine') {
            $set = array();
            foreach ($plans as $id => $p) {
                if (self::isAssignee($c, $p['assignees'])) {
                    $set[$id] = true;
                }
            }
        } else {
            $set = $vis;
        }
        $range = null;
        $ranges = self::ranges();
        if (isset($f['per']) && isset($ranges[$f['per']])) {
            $range = $ranges[$f['per']];
        }
        $deptIds = !empty($f['dept_id']) ? Permission::subtree((int) $f['dept_id']) : null;
        $kw = isset($f['kw']) ? mb_strtolower(trim((string) $f['kw'])) : '';
        $perfText = $kw !== '' && self::perf() ? \Omps\Modules\Performance\Services\PerformanceService::searchText($user, $plans) : array();
        $cfText = $kw !== '' ? \Omps\Engines\CustomFields::searchText('plan', array_keys($plans)) : array();
        $hit = function ($p) use ($range, $deptIds, $f, $kw, $perfText, $cfText) {
            if ($range !== null && ($p['start_date'] > $range[1] || $p['end_date'] < $range[0])) {
                return false;
            }
            if ($deptIds !== null && !in_array((int) $p['dept_id'], $deptIds, true)) {
                return false;
            }
            if (!empty($f['person_id']) && !in_array((int) $f['person_id'], $p['assignees'], true)) {
                return false;
            }
            $extra = (isset($perfText[(int) $p['id']]) ? ' ' . $perfText[(int) $p['id']] : '') . (isset($cfText[(int) $p['id']]) ? ' ' . $cfText[(int) $p['id']] : '');
            if ($kw !== '' && mb_strpos(mb_strtolower($p['title'] . ' ' . $p['goal'] . ' ' . $p['content'] . ' ' . implode(' ', $p['assignee_names']) . $extra), $kw) === false) {
                return false;
            }
            return true;
        };
        $kids = array();
        foreach ($set as $id => $_) {
            $pid = $plans[$id]['parent_id'] === null ? 0 : (int) $plans[$id]['parent_id'];
            if ($pid !== 0 && !isset($set[$pid]) && $mode === 'mine') {
                $pid = 0;
            }
            if ($pid !== 0 && !isset($set[$pid]) && $mode !== 'mine') {
                // parent not visible: for members the parent chain is shown as context
                $pid = isset($plans[$pid]) ? $pid : 0;
            }
            $kids[$pid][] = $id;
        }
        // context parents for members in tree mode (parents they may not otherwise see)
        if ($mode !== 'mine') {
            foreach ($set as $id => $_) {
                $q = $plans[$id];
                while ($q['parent_id'] !== null && isset($plans[(int) $q['parent_id']]) && !isset($set[(int) $q['parent_id']])) {
                    $par = (int) $q['parent_id'];
                    $set[$par] = 'context';
                    $gp = $plans[$par]['parent_id'] === null ? 0 : (int) $plans[$par]['parent_id'];
                    $kids[isset($plans[$gp]) ? $gp : 0][] = $par;
                    $q = $plans[$par];
                }
            }
            foreach ($kids as $k => $v) {
                $kids[$k] = array_values(array_unique($v));
            }
        }
        foreach ($kids as $k => $v) {
            self::sortKids($kids[$k], $plans);
        }
        $memo = array();
        $show = function ($id) use (&$show, &$memo, $kids, $plans, $hit, $set) {
            if (!isset($memo[$id])) {
                $memo[$id] = ($set[$id] === true && $hit($plans[$id]));
                foreach (isset($kids[$id]) ? $kids[$id] : array() as $k) {
                    if ($show($k)) {
                        $memo[$id] = $memo[$id] === true ? true : 'context';
                    }
                }
            }
            return $memo[$id];
        };
        $out = array();
        $walk = function ($pid, $depth) use (&$walk, &$out, $kids, $plans, $show, $user) {
            foreach (isset($kids[$pid]) ? $kids[$pid] : array() as $id) {
                $s = $show($id);
                if (!$s) {
                    continue;
                }
                $row = self::present($plans[$id], $user, $depth, $s !== true);
                $row['has_children'] = !empty($kids[$id]);
                $out[] = $row;
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);
        if (self::perf()) {
            $out = \Omps\Modules\Performance\Services\PerformanceService::decorate($user, $out, $plans);
        }
        return array('rows' => $out, 'ranges' => $ranges, 'today' => date('Y-m-d'));
    }

    // ---------- details ----------

    public static function detail(Request $req, $id)
    {
        list($p, $plans) = self::find($req, $id);
        $vis = self::visibleIds($req->user, $plans);
        $path = array();
        $q = $p;
        while ($q['parent_id'] !== null && isset($plans[(int) $q['parent_id']])) {
            $q = $plans[(int) $q['parent_id']];
            array_unshift($path, array('id' => (int) $q['id'], 'title' => (string) $q['title'], 'level' => (string) $q['level'], 'visible' => isset($vis[(int) $q['id']])));
        }
        $children = array();
        $sum = 0.0;
        foreach ($plans as $k) {
            if ((int) $k['parent_id'] === (int) $p['id'] && $k['parent_id'] !== null) {
                $sum += (float) $k['local_weight'];
                if (isset($vis[(int) $k['id']])) {
                    $children[] = self::present($k, $req->user, 1, false);
                }
            }
        }
        $parent = $p['parent_id'] !== null && isset($plans[(int) $p['parent_id']]) ? $plans[(int) $p['parent_id']] : null;
        $out = self::present($p, $req->user, 0, false);
        $out['goal'] = str_or_empty($p['goal']);
        $out['content'] = str_or_empty($p['content']);
        $out['code'] = (string) $p['code'];
        $out['period_key'] = (string) $p['period_key'];
        $out['path'] = $path;
        $out['children'] = $children;
        $out['children_weight'] = round($sum, 2);
        $out['parent'] = $parent ? array('id' => (int) $parent['id'], 'title' => (string) $parent['title'], 'start' => (string) $parent['start_date'], 'end' => (string) $parent['end_date']) : null;
        $out['version'] = (int) $p['version'];
        $out['custom'] = \Omps\Engines\CustomFields::block('plan', (int) $p['id']);
        if (self::perf()) {
            $both = \Omps\Modules\Performance\Services\PerformanceService::decorate($req->user, array_merge(array($out), $children), $plans);
            $out = array_shift($both);
            $out['children'] = $both;
        }
        return $out;
    }

    /** Results module installed (phase 4)? */
    private static function perf()
    {
        return class_exists('Omps\Modules\Performance\Services\PerformanceService');
    }

    // ---------- people who can be assigned ----------

    public static function candidates($user)
    {
        $c = self::ctx($user);
        if (!$c['assign']) {
            return array();
        }
        $q = DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()->where('p.status', 'active')
            ->select(array('p.id', 'p.name', 'p.dept_id', 'd.name as dept_name'));
        if (!$c['wide']) {
            $q->whereIn('p.dept_id', $c['depts']);
        }
        $out = array();
        foreach ($q->orderBy('d.sort')->orderBy('p.name')->get() as $r) {
            $out[] = array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'dept_id' => int_or_null($r['dept_id']), 'dept' => str_or_empty($r['dept_name']));
        }
        return $out;
    }

    public static function meta(Request $req)
    {
        $c = self::ctx($req->user);
        $depts = array();
        if ($c['assign'] && $c['wide']) {
            foreach (DB::table('departments')->active()->whereNull('parent_id')->orderBy('sort')->get() as $d) {
                $depts[] = array('id' => (int) $d['id'], 'name' => (string) $d['name']);
            }
        }
        $all = array();
        foreach (\Omps\Modules\Organization\Services\DeptService::options($req->user) as $d) {
            $all[] = $d;
        }
        return array(
            'canAddTop' => $c['assign'] && $c['add'], 'topLevels' => self::LEVELS, 'wide' => $c['wide'], 'manager' => $c['assign'],
            'mainDepts' => $depts, 'filterDepts' => $all, 'candidates' => self::candidates($req->user), 'me' => $c['person'],
            'ranges' => self::ranges(), 'today' => date('Y-m-d'), 'custom' => \Omps\Engines\CustomFields::defs('plan'),
        );
    }

    // ---------- create / update / delete ----------

    private static function validate(array $in, $creating)
    {
        $rules = array(
            'title' => 'required|string|max:150', 'start' => 'required|date', 'end' => 'required|date',
            'tl_mode' => 'nullable|in:' . implode(',', self::TL_MODES), 'tl_text' => 'nullable|string|max:20',
            'weight' => array('nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'), 'priority' => 'nullable|in:high,medium,low',
            'goal' => 'nullable|string|max:5000', 'content' => 'nullable|string|max:5000', 'assignees' => 'nullable|array',
        );
        if ($creating) {
            $rules['parent_id'] = 'nullable|int';
            $rules['dept_id'] = 'nullable|int';
        }
        if (isset($in['weight']) && (is_int($in['weight']) || is_float($in['weight']))) {
            $in['weight'] = (string) $in['weight'];
        }
        $v = Validator::check($in, $rules, array('title' => 'plan.field.title', 'start' => 'plan.field.start', 'end' => 'plan.field.end', 'tl_text' => 'plan.field.tl_text',
            'weight' => 'plan.field.weight', 'priority' => 'plan.field.priority', 'goal' => 'plan.field.goal', 'content' => 'plan.field.content',
            'assignees' => 'plan.field.assignees'));
        if ($v['end'] < $v['start']) {
            throw ValidationException::field('end', 'plan.err.end_before_start');
        }
        $v['level'] = self::levelOf($v['start'], $v['end']);
        if (isset($v['weight']) && $v['weight'] !== null && (float) $v['weight'] > 100) {
            throw ValidationException::field('weight', 'validation.max_num', array('field' => __('plan.field.weight'), 'max' => 100));
        }
        $mode = isset($v['tl_mode']) && $v['tl_mode'] !== null ? $v['tl_mode'] : 'auto';
        $text = isset($v['tl_text']) && $v['tl_text'] !== null ? trim((string) $v['tl_text']) : '';
        if ($mode === 'text' && $text === '') {
            $mode = 'auto';
        }
        $v['tl_mode'] = $mode;
        $v['tl_text'] = $mode === 'text' ? $text : null;
        return $v;
    }

    /** Assignees chosen by a manager must be active people in scope. */
    private static function cleanAssignees($user, $list)
    {
        $allowed = array();
        foreach (self::candidates($user) as $p) {
            $allowed[$p['id']] = true;
        }
        $out = array();
        foreach (is_array($list) ? $list : array() as $id) {
            $id = (int) $id;
            if (!isset($allowed[$id])) {
                throw ValidationException::field('assignees', 'plan.err.assignee_scope');
            }
            $out[$id] = $id;
        }
        if (!$out) {
            throw ValidationException::field('assignees', 'plan.err.assignee_required');
        }
        return array_values($out);
    }

    private static function newCode()
    {
        do {
            $code = 'JH' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
        } while (DB::table('plans')->where('code', $code)->exists());
        return $code;
    }

    /** Notes shown after saving: dates outside the parent, sibling weights not adding up to 100%. */
    private static function warnings($planId)
    {
        $w = array();
        $p = DB::table('plans')->find($planId);
        if ($p['parent_id'] !== null) {
            $par = DB::table('plans')->find((int) $p['parent_id']);
            if ($par && ($p['start_date'] < $par['start_date'] || $p['end_date'] > $par['end_date'])) {
                $w[] = __('plan.warn.outside_parent', array('title' => $par['title'], 'start' => $par['start_date'], 'end' => $par['end_date']));
            }
            $sum = (float) DB::value('SELECT COALESCE(SUM(local_weight), 0) FROM plans WHERE parent_id = ? AND deleted_at IS NULL', array((int) $p['parent_id']));
            if (abs($sum - 100) > 0.01) {
                $w[] = __('plan.warn.weights', array('sum' => rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.')));
            }
        }
        return $w;
    }

    public static function create(Request $req, array $in)
    {
        $user = $req->user;
        $c = self::ctx($user);
        $v = self::validate($in, true);
        $cfValues = \Omps\Engines\CustomFields::validate('plan', isset($in['custom']) ? $in['custom'] : array());
        $parent = null;
        $plans = null;
        if (!empty($v['parent_id'])) {
            list($parent, $plans) = self::find($req, $v['parent_id']);
            if (!self::childLevels($c, $parent, $parent['assignees'])) {
                throw HttpException::forbidden();
            }
            $err = self::levelError($c, $parent, $v['level']);
            if ($err) {
                throw ValidationException::field('end', $err[0], $err[1]);
            }
        } elseif (!$c['assign'] || !$c['add']) {
            throw HttpException::forbidden();
        }
        $leaderMode = $c['assign'] && (!$parent || self::manages($c, $parent) || $parent['dept_id'] === null);
        // department of the new plan
        if (!$parent) {
            if ($c['wide']) {
                $dept = !empty($v['dept_id']) ? (int) $v['dept_id'] : null;
                if ($dept !== null && !DB::table('departments d')->active()->where('d.id', $dept)->exists()) {
                    throw ValidationException::field('dept_id', 'staff.err.dept_out_of_scope');
                }
            } else {
                $dept = $user['dept_id'];
            }
        } elseif ($leaderMode) {
            $dept = $parent['dept_id'] === null && !$c['wide'] ? $user['dept_id'] : int_or_null($parent['dept_id']);
        } else {
            $dept = $user['dept_id'] !== null ? $user['dept_id'] : int_or_null($parent['dept_id']);
        }
        if ($leaderMode) {
            $assignees = self::cleanAssignees($user, isset($v['assignees']) ? $v['assignees'] : array());
        } else {
            if ($c['person'] === null) {
                throw HttpException::forbidden();
            }
            $assignees = array((int) $c['person']);
        }
        if ($parent) {
            if (isset($v['weight']) && $v['weight'] !== null && $v['weight'] !== '') {
                $weight = (float) $v['weight'];
            } else {
                $used = (float) DB::value('SELECT COALESCE(SUM(local_weight), 0) FROM plans WHERE parent_id = ? AND deleted_at IS NULL', array((int) $parent['id']));
                $weight = max(0, 100 - $used);
            }
        } else {
            $weight = 100;
        }
        $uid = $req->userId();
        $id = DB::transaction(function () use ($v, $parent, $dept, $assignees, $weight, $c, $uid, $cfValues) {
            $id = DB::table('plans')->insert(array(
                'org_id' => (int) config('app.org_id', 1), 'parent_id' => $parent ? (int) $parent['id'] : null, 'dept_id' => $dept, 'level' => $v['level'],
                'period_key' => self::periodKey($v['level'], $v['start'], $v['end']), 'code' => self::newCode(), 'title' => $v['title'],
                'goal' => isset($v['goal']) ? $v['goal'] : null, 'content' => isset($v['content']) ? $v['content'] : null,
                'start_date' => $v['start'], 'end_date' => $v['end'], 'time_label_mode' => $v['tl_mode'], 'time_label_text' => $v['tl_text'],
                'local_weight' => $weight, 'priority' => isset($v['priority']) ? $v['priority'] : null, 'status' => 'active',
                'owner_person_id' => $c['person'], 'created_at' => now(), 'created_by' => $uid,
            ));
            DB::table('plan_paths')->insert(array('ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0));
            if ($parent) {
                DB::statement('INSERT INTO plan_paths (ancestor_id, descendant_id, depth) SELECT ancestor_id, ?, depth + 1 FROM plan_paths WHERE descendant_id = ?', array($id, (int) $parent['id']));
            }
            foreach ($assignees as $pid) {
                DB::table('plan_assignments')->insert(array('plan_id' => $id, 'person_id' => $pid, 'assigned_by' => $uid, 'created_at' => now()));
            }
            \Omps\Engines\CustomFields::save('plan', $id, $cfValues);
            return $id;
        });
        Audit::change($req, 'plan', 'plan_created', 'plan', $id, $v['title'], null, array('level' => $v['level'], 'parent' => $parent ? $parent['title'] : null,
            'start' => $v['start'], 'end' => $v['end'], 'weight' => $weight, 'assignees' => self::names($assignees)));
        return array('id' => $id, 'warnings' => self::warnings($id));
    }

    private static function names(array $personIds)
    {
        return $personIds ? DB::table('personnel_profiles')->whereIn('id', $personIds)->orderBy('id')->pluck('name') : array();
    }

    public static function update(Request $req, $id, array $in)
    {
        list($p) = self::find($req, $id);
        $caps = self::caps($req->user, $p, $p['assignees']);
        if (!$caps['edit']) {
            throw HttpException::forbidden();
        }
        if (isset($in['version']) && (int) $in['version'] !== (int) $p['version']) {
            throw HttpException::conflict('error.stale');
        }
        // the level follows the new dates; schedules slip, so dates past the parent only warn (warnings())
        $v = self::validate($in, false);
        $cfValues = isset($in['custom']) && is_array($in['custom']) ? \Omps\Engines\CustomFields::validate('plan', $in['custom'], true) : array();
        $cfBefore = $cfValues ? \Omps\Engines\CustomFields::valuesOf('plan', (int) $p['id']) : array();
        $upd = array('title' => $v['title'], 'start_date' => $v['start'], 'end_date' => $v['end'], 'time_label_mode' => $v['tl_mode'], 'time_label_text' => $v['tl_text'],
            'level' => $v['level'], 'period_key' => self::periodKey($v['level'], $v['start'], $v['end']), 'priority' => isset($v['priority']) ? $v['priority'] : null,
            'goal' => isset($v['goal']) ? $v['goal'] : null, 'content' => isset($v['content']) ? $v['content'] : null, 'updated_at' => now(), 'updated_by' => $req->userId());
        if ($p['parent_id'] !== null && isset($v['weight']) && $v['weight'] !== null && $v['weight'] !== '') {
            $upd['local_weight'] = (float) $v['weight'];
        }
        $newAssignees = null;
        if ($caps['assign'] && isset($in['assignees']) && is_array($in['assignees'])) {
            $newAssignees = self::cleanAssignees($req->user, $v['assignees']);
        }
        $uid = $req->userId();
        DB::transaction(function () use ($p, $upd, $newAssignees, $uid, $cfValues) {
            if (DB::table('plans')->where('id', (int) $p['id'])->where('version', (int) $p['version'])->increment('version', 1, $upd) === 0) {
                throw HttpException::conflict('error.stale');
            }
            \Omps\Engines\CustomFields::save('plan', (int) $p['id'], $cfValues);
            if ($newAssignees !== null) {
                $old = $p['assignees'];
                $gone = array_values(array_diff($old, $newAssignees));
                if ($gone) {
                    DB::table('plan_assignments')->where('plan_id', (int) $p['id'])->whereIn('person_id', $gone)->delete();
                }
                foreach (array_diff($newAssignees, $old) as $pid) {
                    DB::table('plan_assignments')->insert(array('plan_id' => (int) $p['id'], 'person_id' => (int) $pid, 'assigned_by' => $uid, 'created_at' => now()));
                }
            }
        });
        if ((string) $upd['title'] !== (string) $p['title']) {
            // attachments live in folders named after the plan chain: follow the new name (design §11.2)
            list(, $plans) = self::find($req, $id);
            $segs = array();
            for ($q = $p['parent_id'] !== null && isset($plans[(int) $p['parent_id']]) ? $plans[(int) $p['parent_id']] : null; $q !== null;
                $q = $q['parent_id'] !== null && isset($plans[(int) $q['parent_id']]) ? $plans[(int) $q['parent_id']] : null) {
                array_unshift($segs, \Omps\Engines\Files::safeName((string) $q['title']));
            }
            array_unshift($segs, \Omps\Engines\Files::safeName(\Omps\Engines\Files::folder('file.folder.plans')));
            $base = implode('/', $segs);
            if (\Omps\Engines\Files::moveFolder($base . '/' . \Omps\Engines\Files::safeName((string) $p['title']), $base . '/' . \Omps\Engines\Files::safeName((string) $upd['title']))) {
                Audit::op($req->user, 'files', 'folder_renamed', 'plan', (int) $p['id'], (string) $upd['title'], array('from' => (string) $p['title']), $req->ip());
            }
        }
        $before = array('title' => $p['title'], 'start' => $p['start_date'], 'end' => $p['end_date'], 'tl_mode' => $p['time_label_mode'], 'tl_text' => $p['time_label_text'],
            'weight' => (string) (float) $p['local_weight'], 'goal' => $p['goal'], 'content' => $p['content'], 'priority' => $p['priority'], 'assignees' => implode(',', self::names($p['assignees'])));
        $after = array('title' => $upd['title'], 'start' => $upd['start_date'], 'end' => $upd['end_date'], 'tl_mode' => $upd['time_label_mode'], 'tl_text' => $upd['time_label_text'],
            'weight' => isset($upd['local_weight']) ? (string) (float) $upd['local_weight'] : $before['weight'], 'goal' => $upd['goal'], 'content' => $upd['content'],
            'priority' => $upd['priority'], 'assignees' => $newAssignees !== null ? implode(',', self::names($newAssignees)) : $before['assignees']);
        list($b, $a) = Audit::diff($before, $after);
        list($cb, $ca) = \Omps\Engines\CustomFields::diff('plan', $cfBefore, $cfValues);
        if ($a || $ca) {
            Audit::change($req, 'plan', 'plan_updated', 'plan', $p['id'], $upd['title'], array_merge($b, $cb), array_merge($a, $ca));
        }
        return array('ok' => true, 'warnings' => self::warnings((int) $p['id']));
    }

    /** The plan and all plans under it. */
    public static function branch($id)
    {
        $out = array();
        foreach (DB::select('SELECT pp.descendant_id AS id FROM plan_paths pp JOIN plans p ON p.id = pp.descendant_id WHERE pp.ancestor_id = ? AND p.deleted_at IS NULL ORDER BY pp.depth, pp.descendant_id', array((int) $id)) as $r) {
            $out[] = (int) $r['id'];
        }
        return $out;
    }

    public static function deleteImpact(Request $req, $id)
    {
        list($p) = self::find($req, $id);
        $caps = self::caps($req->user, $p, $p['assignees']);
        if (!$caps['del']) {
            throw HttpException::forbidden();
        }
        $ids = self::branch($p['id']);
        $results = self::perf() ? \Omps\Modules\Performance\Services\PerformanceService::branchResults($ids) : 0;
        return array('title' => (string) $p['title'], 'children' => count($ids) - 1, 'results' => $results, 'blocked' => $results > 0 && !$caps['assign']);
    }

    public static function delete(Request $req, $id)
    {
        list($p) = self::find($req, $id);
        $caps = self::caps($req->user, $p, $p['assignees']);
        if (!$caps['del']) {
            throw HttpException::forbidden();
        }
        $ids = self::branch($p['id']);
        // members: a branch with submitted or approved results stays until they are withdrawn / returned
        if (self::perf() && !$caps['assign'] && \Omps\Modules\Performance\Services\PerformanceService::branchResults($ids) > 0) {
            throw HttpException::conflict('perf.err.delete_has_results');
        }
        $titles = DB::table('plans')->whereIn('id', $ids)->orderBy('id')->pluck('title');
        $uid = $req->userId();
        DB::transaction(function () use ($ids, $uid) {
            DB::table('plans')->whereIn('id', $ids)->update(array('deleted_at' => now(), 'deleted_by' => $uid));
        });
        if (self::perf()) {
            \Omps\Modules\Performance\Services\PerformanceService::onPlansDeleted($req, $ids);
        }
        Audit::change($req, 'plan', 'plan_deleted', 'plan', $p['id'], $p['title'], array('plans' => $titles), null);
        return array('deleted' => count($ids));
    }
}
