<?php
namespace Omps\Tests;

use Omps\Database\DB;
use Omps\Modules\Plan\Services\PlanService;

/**
 * Phase 3: plans. Who may create what, visibility by scope, the closure table,
 * editing and deleting, weights and warnings, filters.
 */
class PlanTest extends TestCase
{
    /** @var array */
    private $f;

    public function setUp()
    {
        parent::setUp();
        $s = substr(md5(uniqid('', true)), 0, 4);
        $tech = $this->dept('技术部' . $s);
        $soft = $this->dept('软件组', $tech);
        $hr = $this->dept('综合部' . $s);
        $lead = $this->person('李建国', $tech);
        $wang = $this->person('王晓', $soft);
        $chen = $this->person('陈静', $soft);
        $zhou = $this->person('周敏', $hr);
        $boss = $this->person('张伟', null);
        $this->f = array('tech' => $tech, 'soft' => $soft, 'hr' => $hr, 'lead' => $lead, 'wang' => $wang, 'chen' => $chen, 'zhou' => $zhou, 'boss' => $boss,
            'uLead' => $this->user('dept_head', $lead), 'uWang' => $this->user('member', $wang), 'uChen' => $this->user('member', $chen),
            'uZhou' => $this->user('dept_head', $zhou), 'uUnit' => $this->user('unit_head', $boss));
    }

    private function make(array $body)
    {
        $r = $this->request('POST', '/api/v1/plans', $body);
        $this->status(200, $r, 'create ' . $body['title']);
        return $this->data($r)['id'];
    }

    /** Unit long-term plan -> department annual plan -> member quarter plan. */
    private function chain()
    {
        $this->login($this->f['uUnit']['username']);
        $long = $this->make(array('title' => '2026–2030 数字化建设规划', 'level' => 'long', 'start' => '2026-01-01', 'end' => '2030-12-31', 'assignees' => array($this->f['lead'])));
        $this->login($this->f['uLead']['username']);
        $annual = $this->make(array('title' => '2026 年度数字化建设计划', 'level' => 'annual', 'parent_id' => $long, 'start' => '2026-01-01', 'end' => '2026-12-31',
            'weight' => 20, 'assignees' => array($this->f['wang'], $this->f['chen'])));
        $this->login($this->f['uWang']['username']);
        $q = $this->make(array('title' => '第四季度 · 联调与测试', 'level' => 'quarter', 'parent_id' => $annual, 'start' => '2026-10-01', 'end' => '2026-12-31'));
        return array($long, $annual, $q);
    }

    public function testCreationRules()
    {
        list($long, $annual, $q) = $this->chain();
        $this->same(null, DB::value('SELECT dept_id FROM plans WHERE id = ?', array($long)), 'unit plan');
        $this->same($this->f['tech'], (int) DB::value('SELECT dept_id FROM plans WHERE id = ?', array($annual)), 'department plan');
        $this->same(array($this->f['wang']), array_map('intval', DB::table('plan_assignments')->where('plan_id', $q)->pluck('person_id')), 'member plans are their own');
        $this->same('2026-Q4', (string) DB::value('SELECT period_key FROM plans WHERE id = ?', array($q)));
        // member limits
        $this->status(403, $this->request('POST', '/api/v1/plans', array('title' => 'x', 'level' => 'long', 'start' => '2026-01-01', 'end' => '2026-12-31')), 'members cannot create main plans');
        $this->status(422, $this->request('POST', '/api/v1/plans', array('title' => 'x', 'level' => 'annual', 'parent_id' => $annual, 'start' => '2026-01-01', 'end' => '2026-12-31')), 'members: quarter and below');
        $this->status(404, $this->request('POST', '/api/v1/plans', array('title' => 'x', 'level' => 'quarter', 'parent_id' => $long, 'start' => '2026-01-01', 'end' => '2026-03-31')), 'not their task');
        $this->status(422, $this->request('POST', '/api/v1/plans', array('title' => 'x', 'level' => 'month', 'parent_id' => $q, 'start' => '2026-11-30', 'end' => '2026-11-01')), 'end before start');
        $m = $this->make(array('title' => '11 月 · 汇总与报表功能', 'level' => 'month', 'parent_id' => $q, 'start' => '2026-11-01', 'end' => '2026-11-30', 'tl_mode' => 'text', 'tl_text' => '11 月'));
        $w = $this->make(array('title' => '第 47 周', 'level' => 'week', 'parent_id' => $m, 'start' => '2026-11-16', 'end' => '2026-11-22'));
        $this->same('2026-W47', (string) DB::value('SELECT period_key FROM plans WHERE id = ?', array($w)));
        $this->same(4, (int) DB::value('SELECT depth FROM plan_paths WHERE ancestor_id = ? AND descendant_id = ?', array($long, $w)), 'closure depth');
        $this->same(5, (int) DB::value('SELECT COUNT(*) FROM plan_paths WHERE descendant_id = ?', array($w)), 'ancestors incl. itself');
        // department head: assignees only in his department
        $this->login($this->f['uLead']['username']);
        $r = $this->request('POST', '/api/v1/plans', array('title' => 'x', 'level' => 'annual', 'start' => '2026-01-01', 'end' => '2026-12-31', 'assignees' => array($this->f['zhou'])));
        $this->status(422, $r, 'assignee outside scope');
        $r = $this->request('POST', '/api/v1/plans', array('title' => 'x', 'start' => '2026-01-01', 'end' => '2026-03-31', 'assignees' => array($this->f['wang'])));
        $this->status(200, $r, 'leaders may start a main plan of any length');
        $this->same('quarter', (string) DB::value('SELECT level FROM plans WHERE id = ?', array($this->data($r)['id'])));
    }

    public function testLevelFollowsTheDates()
    {
        $cases = array(
            array('2026-03-18', '2026-03-18', 'day'), array('2026-03-30', '2026-04-05', 'week'), array('2026-03-02', '2026-03-20', 'month'),
            array('2026-02-10', '2026-03-31', 'quarter'), array('2026-01-01', '2026-05-31', 'annual'), array('2026-01-01', '2026-12-31', 'annual'),
            array('2026-11-01', '2027-02-28', 'long'), array('2026-01-01', '2030-12-31', 'long'),
        );
        foreach ($cases as $c) {
            $this->same($c[2], PlanService::levelOf($c[0], $c[1]), $c[0] . ' – ' . $c[1]);
        }
        $y = date('Y');
        $this->same('1–5 月', \Omps\Modules\Plan\Services\PlanLabel::auto('annual', $y . '-01-01', $y . '-05-31'), 'whole months');
    }

    /**
     * The user's example: a long-term plan holds several multi-year goals; each goal has yearly goals; a yearly
     * goal has goals of any length (Jan–May, Mar–Jul) given to several people, who split them further.
     */
    public function testGoalsNestByDates()
    {
        $y = (int) date('Y');
        $this->login($this->f['uLead']['username']);
        $plan = $this->make(array('title' => $y . '-' . ($y + 4) . '计划', 'start' => $y . '-01-01', 'end' => ($y + 4) . '-12-31', 'assignees' => array($this->f['lead'])));
        $omps = $this->make(array('title' => 'OMPS开发', 'parent_id' => $plan, 'start' => $y . '-01-01', 'end' => ($y + 4) . '-12-31', 'assignees' => array($this->f['lead'])));
        $robot = $this->make(array('title' => '机器人开发', 'parent_id' => $plan, 'start' => ($y + 1) . '-01-01', 'end' => ($y + 3) . '-12-31', 'assignees' => array($this->f['lead'])));
        $year = $this->make(array('title' => $y . '年OMPS开发', 'parent_id' => $omps, 'start' => $y . '-01-01', 'end' => $y . '-12-31', 'assignees' => array($this->f['wang'], $this->f['chen'])));
        $design = $this->make(array('title' => '制作OMPS设计图', 'parent_id' => $year, 'start' => $y . '-01-01', 'end' => $y . '-05-31', 'assignees' => array($this->f['wang'], $this->f['chen'])));
        $front = $this->make(array('title' => 'OMPS前端开发', 'parent_id' => $year, 'start' => $y . '-03-01', 'end' => $y . '-07-31', 'assignees' => array($this->f['wang'])));
        $level = function ($id) {
            return (string) DB::value('SELECT level FROM plans WHERE id = ?', array($id));
        };
        $this->same(array('long', 'long', 'long', 'annual', 'annual', 'annual'), array_map($level, array($plan, $omps, $robot, $year, $design, $front)));
        $this->same(2, (int) DB::value('SELECT COUNT(*) FROM plan_assignments WHERE plan_id = ?', array($design)), 'two developers');
        $this->same(3, (int) DB::value('SELECT depth FROM plan_paths WHERE ancestor_id = ? AND descendant_id = ?', array($plan, $design)));
        // a sub-plan cannot cover a longer period than its parent
        $r = $this->request('POST', '/api/v1/plans', array('title' => 'x', 'parent_id' => $year, 'start' => $y . '-01-01', 'end' => ($y + 1) . '-06-30', 'assignees' => array($this->f['wang'])));
        $this->status(422, $r, 'longer than the parent');
        // the developers split their goal; plans longer than a quarter stay with the leader
        $this->login($this->f['uWang']['username']);
        $tree = $this->data($this->request('GET', '/api/v1/plans/' . $design));
        $this->same(array('quarter', 'month', 'week', 'day'), $tree['caps']['add'], 'members add a quarter and below');
        $this->status(422, $this->request('POST', '/api/v1/plans', array('title' => 'x', 'parent_id' => $design, 'start' => $y . '-01-01', 'end' => $y . '-04-30')), 'member: longer than a quarter');
        $feb = $this->make(array('title' => '首页设计', 'parent_id' => $design, 'start' => $y . '-02-01', 'end' => $y . '-02-28'));
        $this->same('month', $level($feb));
        // a slipping schedule: the new dates are saved (with a warning past the parent) and the level follows them
        $p = $this->data($this->request('GET', '/api/v1/plans/' . $feb));
        $this->status(200, $this->request('PUT', '/api/v1/plans/' . $feb, array('version' => $p['version'], 'title' => '首页设计', 'start' => $y . '-02-01', 'end' => $y . '-03-31')));
        $this->same('quarter', $level($feb), 'the level follows edited dates');
        $this->same($y . '-Q1', (string) DB::value('SELECT period_key FROM plans WHERE id = ?', array($feb)));
        $p = $this->data($this->request('GET', '/api/v1/plans/' . $feb));
        $r = $this->request('PUT', '/api/v1/plans/' . $feb, array('version' => $p['version'], 'title' => '首页设计', 'start' => $y . '-02-01', 'end' => $y . '-06-15'));
        $this->status(200, $r);
        $this->same(1, count($this->data($r)['warnings']), 'past the goal: saved with a warning');
        $this->same('annual', $level($feb));
    }

    public function testVisibilityByScope()
    {
        list($long, $annual, $q) = $this->chain();
        $this->login($this->f['uZhou']['username']);
        $hr = $this->make(array('title' => '综合部 2026 年度培训', 'level' => 'annual', 'start' => '2026-01-01', 'end' => '2026-12-31', 'assignees' => array($this->f['zhou'])));
        $rows = $this->data($this->request('GET', '/api/v1/plans', null, array('mode' => 'tree')))['rows'];
        $ids = array_map(function ($r) { return $r['id']; }, $rows);
        $this->ok(in_array($long, $ids, true) && in_array($hr, $ids, true), 'unit plans and own department');
        $this->ok(!in_array($annual, $ids, true) && !in_array($q, $ids, true), 'not another department');
        $this->status(404, $this->request('GET', '/api/v1/plans/' . $annual), 'detail outside scope');

        $this->login($this->f['uChen']['username']);
        $mine = $this->data($this->request('GET', '/api/v1/plans', null, array('mode' => 'mine')))['rows'];
        $this->same(array($annual), array_map(function ($r) { return $r['id']; }, $mine), 'only tasks given to me (not a colleague\'s sub-plans)');
        $this->status(404, $this->request('GET', '/api/v1/plans/' . $q), 'colleague sub-plan');
        $this->status(403, $this->request('GET', '/plans/tree'), 'members have no tree page');

        $this->login($this->f['uWang']['username']);
        $tree = $this->data($this->request('GET', '/api/v1/plans', null, array('mode' => 'tree')))['rows'];
        $ctx = array();
        foreach ($tree as $r) {
            $ctx[$r['id']] = $r['context'];
        }
        $this->same(true, $ctx[$long], 'parent shown grey as context');
        $this->same(false, $ctx[$q]);

        $this->login($this->f['uUnit']['username']);
        $all = $this->data($this->request('GET', '/api/v1/plans', null, array('mode' => 'tree')))['rows'];
        $this->ok(count($all) >= 4, 'unit head sees everything');
    }

    public function testEditAndDeleteRights()
    {
        list($long, $annual, $q) = $this->chain();
        $this->login($this->f['uWang']['username']);
        $d = $this->data($this->request('GET', '/api/v1/plans/' . $annual));
        $this->ok(!$d['caps']['edit'] && !$d['caps']['del'], 'assigned task is read-only for the member');
        $this->ok($d['caps']['add'] === array('quarter', 'month', 'week', 'day'), 'member may add sub-plans of a quarter or less');
        $this->status(403, $this->request('PUT', '/api/v1/plans/' . $annual, array('title' => 'x', 'start' => '2026-01-01', 'end' => '2026-12-31')));
        $dq = $this->data($this->request('GET', '/api/v1/plans/' . $q));
        $r = $this->request('PUT', '/api/v1/plans/' . $q, array('version' => $dq['version'], 'title' => '第四季度 · 联调', 'start' => '2026-10-01', 'end' => '2027-01-15'));
        $this->status(200, $r);
        $this->ok(count($this->data($r)['warnings']) >= 1, 'outside the parent: warning, still saved');
        $this->status(409, $this->request('PUT', '/api/v1/plans/' . $q, array('version' => $dq['version'], 'title' => 'x', 'start' => '2026-10-01', 'end' => '2026-12-31')), 'stale version');

        $this->login($this->f['uLead']['username']);
        $da = $this->data($this->request('GET', '/api/v1/plans/' . $annual));
        $this->ok($da['caps']['edit'] && $da['caps']['assign'], 'department head manages');
        $this->status(200, $this->request('PUT', '/api/v1/plans/' . $annual, array('title' => '2026 年度数字化建设计划', 'start' => '2026-01-01', 'end' => '2026-12-31', 'assignees' => array($this->f['wang']))));
        $this->same(array($this->f['wang']), array_map('intval', DB::table('plan_assignments')->where('plan_id', $annual)->pluck('person_id')), 'assignees replaced');
        $da = $this->data($this->request('GET', '/api/v1/plans/' . $annual));
        $this->status(200, $this->request('PUT', '/api/v1/plans/' . $annual, array('title' => '2026 年度数字化建设计划', 'start' => '2026-01-01', 'end' => '2026-12-31', 'version' => $da['version'])),
            'editing without the assignee field keeps the assignees');
        $this->same(array($this->f['wang']), array_map('intval', DB::table('plan_assignments')->where('plan_id', $annual)->pluck('person_id')));
        $dl = $this->data($this->request('GET', '/api/v1/plans/' . $long));
        $this->ok(!$dl['caps']['edit'], 'unit plan is read-only for the department head');
        $this->same(array('long', 'annual', 'quarter', 'month', 'week', 'day'), $dl['caps']['add'], 'but he may break it down, into multi-year goals too');

        $im = $this->data($this->request('GET', '/api/v1/plans/' . $annual . '/delete-impact'));
        $this->same(1, $im['children']);
        $this->same(array('deleted' => 2), $this->data($this->request('DELETE', '/api/v1/plans/' . $annual)));
        $this->ok(DB::value('SELECT deleted_at FROM plans WHERE id = ?', array($q)) !== null, 'sub-plans deleted too');
        $this->status(404, $this->request('GET', '/api/v1/plans/' . $q));
        $this->ok($this->auditCount('plan_deleted') >= 1);
    }

    public function testWeightsAndFilters()
    {
        list($long, $annual, $q) = $this->chain();
        $this->login($this->f['uLead']['username']);
        $r = $this->request('POST', '/api/v1/plans', array('title' => '2027 年度', 'level' => 'annual', 'parent_id' => $long, 'start' => '2027-01-01', 'end' => '2027-12-31', 'assignees' => array($this->f['wang'])));
        $id = $this->data($r)['id'];
        $this->same(80.0, (float) DB::value('SELECT local_weight FROM plans WHERE id = ?', array($id)), 'default weight = what is left');
        $this->same(array(), $this->data($r)['warnings'], 'sum is 100%');
        $r = $this->request('POST', '/api/v1/plans', array('title' => '2028 年度', 'level' => 'annual', 'parent_id' => $long, 'start' => '2028-01-01', 'end' => '2028-12-31', 'weight' => 30, 'assignees' => array($this->f['wang'])));
        $this->contains('130', implode(' ', $this->data($r)['warnings']), 'sum warning');
        $d = $this->data($this->request('GET', '/api/v1/plans/' . $long));
        $this->same(130.0, (float) $d['children_weight']);

        $this->login($this->f['uWang']['username']);
        $this->make(array('title' => '今天的日计划', 'level' => 'day', 'parent_id' => $this->make(array('title' => '本周', 'level' => 'week', 'parent_id' =>
            $this->make(array('title' => '本月', 'level' => 'month', 'parent_id' => $q, 'start' => date('Y-m-01'), 'end' => date('Y-m-t'))),
            'start' => PlanService::ranges()['week'][0], 'end' => PlanService::ranges()['week'][1])), 'start' => date('Y-m-d'), 'end' => date('Y-m-d')));
        $week = $this->data($this->request('GET', '/api/v1/plans', null, array('mode' => 'mine', 'per' => 'week')))['rows'];
        $titles = array_map(function ($r) { return $r['title']; }, $week);
        $this->ok(in_array('今天的日计划', $titles, true), 'this week');
        $kw = $this->data($this->request('GET', '/api/v1/plans', null, array('mode' => 'mine', 'kw' => '日计划')))['rows'];
        $last = end($kw);
        $this->same('今天的日计划', $last['title']);
        $this->ok($kw[0]['context'], 'parents of a match shown as context');
    }

    public function testMenusAndPages()
    {
        $this->login($this->f['uWang']['username']);
        $html = $this->request('GET', '/plans/mine')->body;
        $this->contains('我的任务', $html);
        $this->notContains('/omps/plans/tree', $html, 'no tree menu for members');
        $this->login($this->f['uLead']['username']);
        $this->status(200, $this->request('GET', '/plans/tree'));
        $meta = $this->data($this->request('GET', '/api/v1/plans/meta'));
        $this->ok($meta['canAddTop'] && count($meta['candidates']) === 3, 'department head assigns his own people');
    }
}
