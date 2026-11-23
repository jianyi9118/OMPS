<?php
namespace Omps\Tests;

use Omps\Core\Lang;
use Omps\Database\DB;
use Omps\Modules\Plan\Services\PlanService;

/**
 * 工作台 by role (design §17), the one "lagging" rule, the super admin's system status and the
 * recording of missing translations.
 */
class WorkbenchTest extends TestCase
{
    private $f;

    public function setUp()
    {
        parent::setUp();
        $s = substr(md5(uniqid('', true)), 0, 4);
        $tech = $this->dept('工作台技术部' . $s);
        $hr = $this->dept('工作台综合部' . $s);
        $lead = $this->person('台部长' . $s, $tech);
        $wang = $this->person('台成员' . $s, $tech);
        $zhou = $this->person('台综合' . $s, $hr);
        $boss = $this->person('台单位' . $s, null);
        $this->f = array('s' => $s, 'tech' => $tech, 'hr' => $hr, 'lead' => $lead, 'wang' => $wang, 'zhou' => $zhou,
            'uLead' => $this->user('dept_head', $lead), 'uWang' => $this->user('member', $wang), 'uZhou' => $this->user('dept_head', $zhou),
            'uUnit' => $this->user('unit_head', $this->person('台负责人' . $s, null)), 'uSuper' => $this->user('super'));
        unset($boss);
    }

    private function make(array $body)
    {
        $r = $this->request('POST', '/api/v1/plans', $body);
        $this->status(200, $r, 'create ' . $body['title']);
        return $this->data($r)['id'];
    }

    public function testLagRule()
    {
        $now = strtotime('2026-07-02 12:00:00');
        $this->ok(abs(PlanService::timePct('2026-01-01', '2026-12-31', $now) - 50) < 1, 'half of the year used');
        $this->ok(PlanService::lagging('annual', 'active', '2026-01-01', '2026-12-31', 39, $now), '11 points behind');
        $this->ok(!PlanService::lagging('annual', 'active', '2026-01-01', '2026-12-31', 41, $now), 'within 10 points');
        $this->ok(!PlanService::lagging('week', 'active', '2026-06-29', '2026-07-05', 0, $now), 'week plans are not judged');
        $this->ok(!PlanService::lagging('annual', 'done', '2026-01-01', '2026-12-31', 0, $now), 'finished plans are not judged');
        $this->ok(!PlanService::lagging('annual', 'active', '2026-08-01', '2026-12-31', 0, $now), 'not started yet');
    }

    public function testWorkbenchByRole()
    {
        $f = $this->f;
        $y = (int) date('Y');
        $today = date('Y-m-d');
        $s = $f['s'];
        // a department long-term plan (no results: lagging), a department annual plan, a unit annual plan, another department's plan
        $this->login($f['uLead']['username']);
        $long = $this->make(array('title' => '部门长期' . $s, 'level' => 'long', 'start' => ($y - 2) . '-01-01', 'end' => ($y + 2) . '-12-31', 'assignees' => array($f['lead'])));
        $annual = $this->make(array('title' => '部门年度' . $s, 'level' => 'annual', 'start' => $y . '-01-01', 'end' => $y . '-12-31', 'assignees' => array($f['wang'])));
        $this->login($f['uZhou']['username']);
        $other = $this->make(array('title' => '综合部长期' . $s, 'level' => 'long', 'start' => ($y - 1) . '-01-01', 'end' => ($y + 1) . '-12-31', 'assignees' => array($f['zhou'])));
        $this->login($f['uUnit']['username']);
        $unitAnnual = $this->make(array('title' => '单位年度' . $s, 'level' => 'annual', 'start' => $y . '-01-01', 'end' => $y . '-12-31', 'assignees' => array($f['lead'])));
        // the member plans today and writes a result
        $this->login($f['uWang']['username']);
        $month = $this->make(array('title' => '本月' . $s, 'level' => 'month', 'parent_id' => $annual, 'start' => date('Y-m-01'), 'end' => date('Y-m-t')));
        $day = $this->make(array('title' => '今天的事' . $s, 'level' => 'day', 'parent_id' => $month, 'start' => $today, 'end' => $today));
        $this->status(200, $this->request('PUT', '/api/v1/perf/plans/' . $day . '/mine', array('progress' => '100', 'done' => '做完了', 'submit' => true)));

        // members: no workbench
        $r = $this->request('GET', '/', null, array(), array('Accept' => 'text/html'));
        $this->status(302, $r, 'members land on 我的任务');
        $this->contains('/plans/mine', rawurldecode(implode(' ', $r->headers)));
        $this->status(403, $this->request('GET', '/api/v1/workbench'));
        $page = $this->request('GET', '/plans/mine', null, array(), array('Accept' => 'text/html'))->body;
        $this->notContains('data-code="dashboard"', $page, 'no 工作台 in the menu');
        $this->contains('pages/device.js', $page, '报修 is offered on 我的任务');

        // department head: the department only
        $this->login($f['uLead']['username']);
        $this->status(200, $this->request('GET', '/', null, array(), array('Accept' => 'text/html')));
        $rr = $this->request('GET', '/api/v1/workbench');
        fwrite(STDERR, substr($rr->body, 0, 600) . "
");
        $d = $this->data($rr);
        $this->same('dept', $d['kind']);
        $titles = array_map(function ($p) {
            return $p['title'];
        }, $d['plans']);
        $this->ok(in_array('部门长期' . $s, $titles, true) && in_array('部门年度' . $s, $titles, true), 'department plans');
        $this->ok(!in_array('综合部长期' . $s, $titles, true) && !in_array('单位年度' . $s, $titles, true), 'not other plans');
        $this->same('部门长期' . $s, $d['plans'][0]['title'], 'lagging first');
        $this->ok($d['plans'][0]['lagging'] && $d['plans'][0]['time_pct'] > 40);
        $members = array();
        foreach ($d['members'] as $m) {
            $members[$m['id']] = $m;
        }
        $this->ok(isset($members[$f['wang']]) && !isset($members[$f['lead']]), 'members without the head');
        $this->same(array('今天的事' . $s), $members[$f['wang']]['today']);
        $this->same('written', $members[$f['wang']]['result']);
        $this->same(1, $members[$f['wang']]['pending'], 'waiting for my review');
        $k = array();
        foreach ($d['kpis'] as $x) {
            $k[$x['k']] = $x['v'];
        }
        $this->same(1, $k['pending']);
        $this->ok($k['lagging'] >= 1);
        $this->same('1 / 2', $k['written_dept'], 'one of two people wrote today');
        $this->ok($d['system'] === null, 'no system status');

        // unit head: departments and key plans
        $this->login($f['uUnit']['username']);
        $d = $this->data($this->request('GET', '/api/v1/workbench'));
        $this->same('unit', $d['kind']);
        $titles = array_map(function ($p) {
            return $p['title'];
        }, $d['plans']);
        $this->ok(in_array('部门长期' . $s, $titles, true) && in_array('综合部长期' . $s, $titles, true) && in_array('单位年度' . $s, $titles, true), 'key plans');
        $this->ok(!in_array('部门年度' . $s, $titles, true), 'department annual plans are not key plans');
        $row = array_values(array_filter($d['depts'], function ($x) use ($f) {
            return $x['id'] === $f['tech'];
        }))[0];
        $this->same(2, $row['people']);
        $this->same(1, $row['written']);
        $this->ok($row['lagging'] >= 1 && $row['plans'] >= 2);
        $this->ok($d['system'] === null);

        // super admin: plus the system status
        $this->login($f['uSuper']['username']);
        $d = $this->data($this->request('GET', '/api/v1/workbench'));
        $this->same('unit', $d['kind']);
        $this->ok(is_array($d['system']) && $d['system']['online'] >= 1, 'people online');
        $this->ok(array_key_exists('backup', $d['system']) && isset($d['system']['task']['ok']));
    }

    public function testMissingTranslationsAreRecorded()
    {
        $super = $this->user('super');
        DB::statement('DELETE FROM missing_translations');
        $key = 'wb.no_such_key_' . mt_rand(1000, 9999);
        Lang::$missing = array();
        $this->same('⟦' . $key . '⟧', Lang::get($key));
        Lang::$missing = array();
        Lang::get($key);
        $this->same(2, (int) DB::value('SELECT hits FROM missing_translations WHERE tkey = ?', array($key)), 'counted');
        $this->same(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'missing_translation'", array((int) $super['id'])), 'super admins told once');
        $this->login($super['username']);
        $sys = $this->data($this->request('GET', '/api/v1/workbench'))['system'];
        $this->same(1, $sys['missing_total']);
        $this->same($key, $sys['missing'][0]['key']);
        $this->same($key, $this->data($this->request('GET', '/api/v1/languages'))['missing'][0]['key']);
        $this->status(200, $this->request('DELETE', '/api/v1/missing-translations'));
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM missing_translations'));
    }
}
