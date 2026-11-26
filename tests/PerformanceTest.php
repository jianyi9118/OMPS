<?php
namespace Omps\Tests;

use Omps\Database\DB;

/**
 * Phase 4: results. Writing, the fixed review chain, edit-and-approve, batch, return, withdraw,
 * completion roll-up, the suggested %, attachments, delete rules, search in results.
 */
class PerformanceTest extends TestCase
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
        $this->f = array('tech' => $tech, 'lead' => $lead, 'wang' => $wang, 'chen' => $chen, 'zhou' => $zhou, 'boss' => $boss,
            'uLead' => $this->user('dept_head', $lead), 'uWang' => $this->user('member', $wang), 'uChen' => $this->user('member', $chen),
            'uZhou' => $this->user('dept_head', $zhou), 'uUnit' => $this->user('unit_head', $boss));
    }

    private function make(array $body)
    {
        $r = $this->request('POST', '/api/v1/plans', $body);
        $this->status(200, $r, 'create ' . $body['title']);
        return $this->data($r)['id'];
    }

    /** unit long (李建国) -> department annual (王晓, 陈静) -> 王晓 quarter (50%) -> 王晓 month */
    private function chain()
    {
        $this->login($this->f['uUnit']['username']);
        $long = $this->make(array('title' => '2026–2030 数字化建设规划', 'level' => 'long', 'start' => '2026-01-01', 'end' => '2030-12-31', 'assignees' => array($this->f['lead'])));
        $this->login($this->f['uLead']['username']);
        $annual = $this->make(array('title' => '2026 年度数字化建设计划', 'level' => 'annual', 'parent_id' => $long, 'start' => '2026-01-01', 'end' => '2026-12-31',
            'weight' => 20, 'assignees' => array($this->f['wang'], $this->f['chen'])));
        $this->login($this->f['uWang']['username']);
        $q = $this->make(array('title' => '第四季度 · 联调与测试', 'level' => 'quarter', 'parent_id' => $annual, 'start' => '2026-10-01', 'end' => '2026-12-31', 'weight' => 50));
        $m = $this->make(array('title' => '11 月 · 汇总与报表', 'level' => 'month', 'parent_id' => $q, 'start' => '2026-11-01', 'end' => '2026-11-30'));
        return array($long, $annual, $q, $m);
    }

    private function save($planId, array $body)
    {
        return $this->request('PUT', '/api/v1/perf/plans/' . $planId . '/mine', $body);
    }

    private function reportId($planId, $personId)
    {
        return (int) DB::value('SELECT id FROM performance_reports WHERE plan_id = ? AND person_id = ?', array($planId, $personId));
    }

    private function rows($mode, array $q = array())
    {
        $out = array();
        foreach ($this->data($this->request('GET', '/api/v1/plans', null, array_merge(array('mode' => $mode), $q)))['rows'] as $r) {
            $out[$r['id']] = $r;
        }
        return $out;
    }

    public function testWriteReviewAndRollUp()
    {
        list($long, $annual, $q, $m) = $this->chain();
        // member: draft, then submit
        $this->status(422, $this->save($q, array('progress' => '40', 'submit' => true)), 'results text required on submit');
        $this->status(422, $this->save($q, array('progress' => '140', 'done' => 'x')), 'over 100');
        $r = $this->save($q, array('progress' => '40', 'done' => '完成联调环境搭建', 'submit' => false));
        $this->status(200, $r);
        $this->same('draft', $this->data($r)['status']);
        $this->status(404, $this->request('GET', '/api/v1/perf/plans/' . $long . '/mine'), 'plan of someone else, not visible');
        $mine = $this->rows('mine');
        $this->ok($mine[$q]['caps']['write'] && $mine[$q]['my']['status'] === 'draft', 'my result shown on my tasks');
        $this->same(0.0, (float) $mine[$q]['progress'], 'drafts do not count');
        $this->status(200, $this->save($q, array('progress' => '40', 'done' => '完成联调环境搭建', 'submit' => true)));
        $this->status(409, $this->save($q, array('progress' => '50', 'done' => 'x')), 'locked while submitted');

        // reviewers: a colleague cannot, another department head cannot, own department head can
        $rid = $this->reportId($q, $this->f['wang']);
        $this->login($this->f['uChen']['username']);
        $this->status(403, $this->request('GET', '/api/v1/perf/reviews'), 'members do not review');
        $this->login($this->f['uZhou']['username']);
        $this->same(0, count($this->data($this->request('GET', '/api/v1/perf/reviews'))['rows']), 'other department');
        $this->status(404, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($rid))), 'out of scope');
        $this->login($this->f['uLead']['username']);
        $list = $this->data($this->request('GET', '/api/v1/perf/reviews'));
        $this->same(array($rid), array_map(function ($x) { return $x['id']; }, $list['rows']));
        $this->same(1, $list['pending']);
        // list filters: period on the submitted time, keyword in one field
        $this->same(0, $this->data($this->request('GET', '/api/v1/perf/reviews', null, array('from' => '2000-01-01', 'to' => '2000-12-31')))['total'], 'period');
        $this->same(1, $this->data($this->request('GET', '/api/v1/perf/reviews', null, array('from' => date('Y-m-d'), 'to' => date('Y-m-d'))))['total'], 'submitted today');
        $this->same(0, $this->data($this->request('GET', '/api/v1/perf/reviews', null, array('kw' => 'zz-no-such-text', 'field' => 'task')))['total'], 'keyword');
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($rid))));
        $this->same('approved', (string) DB::value('SELECT status FROM performance_reports WHERE id = ?', array($rid)));
        $this->ok($this->auditCount('perf_approved') >= 1);

        // completion: quarter 40 (approved); annual = (wang: roll-up 40 via the quarter at 50% + nothing else = 40, chen: same roll-up) = 40
        $tree = $this->rows('tree');
        $this->same(40.0, (float) $tree[$q]['progress'], 'approved %');
        $this->same(0.0, (float) $tree[$m]['progress'], 'month not written');
        $this->same(40.0, (float) $tree[$annual]['progress'], 'parent from sub-plans by weight');
        $this->same(40.0, (float) $tree[$long]['progress'], 'up to the long-term plan');

        // the suggested % for 王晓 on the annual plan comes from his own sub-plans
        $this->login($this->f['uWang']['username']);
        $d = $this->data($this->request('GET', '/api/v1/perf/plans/' . $annual . '/mine'));
        $this->same(40.0, (float) $d['reference']);
        $this->same('第四季度 · 联调与测试', $d['sub'][0]['plan']['title'], 'my sub-plan results listed');
        $this->status(200, $this->save($annual, array('progress' => '60', 'done' => '年度：平台联调完成一半', 'submit' => true)));
        // 王晓's annual approved at 60, 陈静 still none -> chen counts the sub-plan roll-up 40 -> (60 + 40) / 2
        $this->login($this->f['uLead']['username']);
        $aid = $this->reportId($annual, $this->f['wang']);
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($aid))));
        $this->same(50.0, (float) $this->rows('tree')[$annual]['progress'], 'average over assignees');

        // department head's own result goes to the unit head; unit head's own result takes effect at once
        $this->status(200, $this->save($long, array('progress' => '10', 'done' => '规划启动', 'submit' => true)));
        $lid = $this->reportId($long, $this->f['lead']);
        $this->same(0, count(array_filter($this->data($this->request('GET', '/api/v1/perf/reviews'))['rows'], function ($x) use ($lid) { return $x['id'] === $lid; })), 'never review oneself');
        $this->status(404, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($lid))));
        $this->login($this->f['uUnit']['username']);
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($lid))));
        $own = $this->make(array('title' => '单位 2026 年度重点工作', 'level' => 'annual', 'start' => '2026-01-01', 'end' => '2026-12-31', 'assignees' => array($this->f['boss'])));
        $r = $this->save($own, array('progress' => '30', 'done' => '单位层面推进', 'submit' => true));
        $this->same('approved', $this->data($r)['status'], 'unit head: in effect when submitted');
    }

    public function testEditApproveReturnWithdraw()
    {
        list($long, $annual, $q, $m) = $this->chain();
        $this->status(200, $this->save($m, array('progress' => '70', 'done' => '报表初稿', 'problems' => '模板未定', 'submit' => true)));
        $rid = $this->reportId($m, $this->f['wang']);
        $this->status(200, $this->request('POST', '/api/v1/perf/reports/' . $rid . '/withdraw', array()));
        $this->same('withdrawn', (string) DB::value('SELECT status FROM performance_reports WHERE id = ?', array($rid)));
        $this->status(200, $this->save($m, array('progress' => '75', 'done' => '报表初稿完成', 'submit' => true)));

        $this->login($this->f['uLead']['username']);
        $this->status(422, $this->request('POST', '/api/v1/perf/reviews/return', array('ids' => array($rid), 'reason' => '')), 'reason required');
        $v = (int) DB::value('SELECT version FROM performance_reports WHERE id = ?', array($rid));
        $r = $this->request('POST', '/api/v1/perf/reviews/' . $rid . '/approve', array('progress' => '80', 'done' => '报表初稿完成，导出待模板确认', 'score' => '90', 'comment' => '补充了说明', 'version' => $v));
        $this->status(200, $r);
        $row = DB::table('performance_reports')->find($rid);
        $this->same('approved', (string) $row['status']);
        $this->same(80.0, (float) $row['appr_progress']);
        $this->same(1, (int) $row['reviewer_edited']);
        $this->same(90.0, (float) $row['score']);
        $kinds = DB::table('performance_versions')->where('report_id', $rid)->orderBy('version_no')->pluck('kind');
        $this->same(array('submit', 'withdraw', 'submit', 'edit_approve'), $kinds, 'history kept');
        $this->ok($this->auditCount('perf_edit_approved') >= 1);
        $hist = $this->data($this->request('GET', '/api/v1/perf/reports/' . $rid . '/history'));
        $this->same('报表初稿完成', $hist['rows'][1]['data']['done'], 'the submitted text is still in the history');

        // the member cannot withdraw an approved result; the head returns it, the approval goes
        $this->login($this->f['uWang']['username']);
        $this->status(409, $this->request('POST', '/api/v1/perf/reports/' . $rid . '/withdraw', array()));
        $this->login($this->f['uLead']['username']);
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/return', array('ids' => array($rid), 'reason' => '请补充附件')));
        $row = DB::table('performance_reports')->find($rid);
        $this->same('returned', (string) $row['status']);
        $this->same(null, $row['appr_at'], 'approval removed');
        $this->login($this->f['uWang']['username']);
        $d = $this->data($this->request('GET', '/api/v1/perf/plans/' . $m . '/mine'));
        $this->same('请补充附件', $d['report']['comment']);
        $this->ok($d['editable'], 'returned results can be changed');

        // update after approval: the old approval stays valid until the new one is approved
        $this->status(200, $this->save($m, array('progress' => '85', 'done' => '报表完成', 'submit' => true)));
        $this->login($this->f['uLead']['username']);
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($rid))));
        $this->login($this->f['uWang']['username']);
        $this->status(200, $this->save($m, array('progress' => '95', 'done' => '报表完成并上线')));
        $row = DB::table('performance_reports')->find($rid);
        $this->same('draft', (string) $row['status']);
        $this->same(85.0, (float) $row['appr_progress'], 'approved value kept');
        $this->login($this->f['uLead']['username']);
        $view = $this->data($this->request('GET', '/api/v1/perf/plans/' . $m));
        $this->same(true, $view['rows'][0]['shown']['approved_only'], 'others see the approved text while a new draft exists');
        $this->same('报表完成', $view['rows'][0]['shown']['done']);
    }

    public function testBatchFilesDeleteAndSearch()
    {
        list($long, $annual, $q, $m) = $this->chain();
        $this->status(200, $this->save($q, array('progress' => '20', 'done' => '季度联调 zeta-marker', 'submit' => true)));
        $this->status(200, $this->save($m, array('progress' => '30', 'done' => '月度汇总', 'submit' => true)));
        $this->login($this->f['uChen']['username']);
        $this->status(200, $this->save($annual, array('progress' => '10', 'done' => '设备登记', 'submit' => true)));
        // attachments
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, "%PDF-1.4\n% test\n");
        $this->login($this->f['uWang']['username']);
        $this->status(409, $this->upload('/api/v1/perf/plans/' . $q . '/mine/files', array(array('path' => $pdf, 'name' => '联调记录.pdf'))), 'no uploads while submitted');
        $w = $this->make(array('title' => '第 47 周', 'level' => 'week', 'parent_id' => $m, 'start' => '2026-11-16', 'end' => '2026-11-22'));
        $up = $this->upload('/api/v1/perf/plans/' . $w . '/mine/files', array(array('path' => $pdf, 'name' => '联调记录.pdf')));
        $this->status(200, $up);
        $rel = $this->data($up)['files'][0]['relation_id'];
        $this->same('draft', (string) DB::value('SELECT status FROM performance_reports WHERE plan_id = ?', array($w)), 'upload creates a draft');
        $this->contains('/', (string) DB::value('SELECT f.rel_path FROM files f JOIN file_relations r ON r.file_id = f.id WHERE r.id = ?', array($rel)));
        $this->login($this->f['uChen']['username']);
        $this->status(404, $this->request('GET', '/api/v1/files/' . $rel), 'colleague cannot download');
        $this->login($this->f['uWang']['username']);
        $this->status(200, $this->save($w, array('progress' => '100', 'done' => '第 47 周联调', 'submit' => true)));
        $this->status(409, $this->request('DELETE', '/api/v1/files/' . $rel), 'attachment of submitted results stays');
        $this->login($this->f['uLead']['username']);
        $this->status(200, $this->request('GET', '/api/v1/files/' . $rel), 'reviewer downloads');

        // batch: all or nothing
        $ids = array($this->reportId($q, $this->f['wang']), $this->reportId($m, $this->f['wang']), $this->reportId($annual, $this->f['chen']));
        $res = $this->data($this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => $ids)));
        $this->same(3, $res['approved']);
        $this->same(3, (int) DB::table('performance_reports')->whereIn('id', $ids)->where('status', 'approved')->count());

        // search finds result text
        $hit = $this->rows('tree', array('kw' => 'zeta-marker'));
        $this->ok(isset($hit[$q]) && !$hit[$q]['context'], 'result text is searchable');

        // a member cannot delete a branch with approved results; the department head can
        $this->login($this->f['uWang']['username']);
        $im = $this->data($this->request('GET', '/api/v1/plans/' . $q . '/delete-impact'));
        $this->ok($im['blocked'] && $im['results'] >= 2, 'impact explains why');
        $this->status(409, $this->request('DELETE', '/api/v1/plans/' . $q));
        $d = $this->make(array('title' => '草稿计划', 'level' => 'month', 'parent_id' => $q, 'start' => '2026-12-01', 'end' => '2026-12-31'));
        $this->status(200, $this->save($d, array('progress' => '5', 'done' => '草稿')));
        $this->status(200, $this->request('DELETE', '/api/v1/plans/' . $d));
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM performance_reports WHERE plan_id = ?', array($d)), 'drafts go with the plan');
        $this->login($this->f['uLead']['username']);
        $this->status(200, $this->request('DELETE', '/api/v1/plans/' . $q), 'managers may delete');
        $this->same(0, count(array_filter($this->data($this->request('GET', '/api/v1/perf/reviews', null, array('tab' => 'done')))['rows'], function ($x) use ($q) {
            return $x['plan']['id'] === $q;
        })), 'results of deleted plans are not listed');
        @unlink($pdf);
    }

    /** Workdays in [from, to] (the reports that are due), today only after work hours. */
    private function workdays($from, $to)
    {
        $cal = \Omps\Engines\WorkCalendar::fromSettings();
        $n = 0;
        $today = date('Y-m-d');
        $past = (int) date('G') * 60 + (int) date('i') >= $cal->end;
        for ($ts = strtotime($from . ' 12:00:00'); date('Y-m-d', $ts) <= $to; $ts = strtotime('+1 day', $ts)) {
            $d = date('Y-m-d', $ts);
            if ($cal->isWorkday($ts) && ($d < $today || ($d === $today && $past))) {
                $n++;
            }
        }
        return $n;
    }

    /** The last Monday–Friday day before $before. */
    private function lastWorkday($before)
    {
        $ts = strtotime($before . ' 12:00:00 -1 day');
        while ((int) date('N', $ts) > 5) {
            $ts = strtotime('-1 day', $ts);
        }
        return date('Y-m-d', $ts);
    }

    public function testStatistics()
    {
        list($long, $annual, $q, $m) = $this->chain();
        $f = $this->f;
        $today = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-20 days'));
        $to = date('Y-m-d', strtotime('+30 days'));
        // nothing is expected before a record exists: move the records back
        DB::table('personnel_profiles')->whereIn('id', array($f['wang'], $f['chen'], $f['lead']))->update(array('created_at' => date('Y-m-d H:i:s', strtotime('-60 days'))));
        $d0 = $this->lastWorkday(date('Y-m-d', strtotime('-2 days')));
        $d1 = $this->lastWorkday($d0);
        $mon = date('Y-m-d', strtotime('monday this week -7 days'));
        $fri = date('Y-m-d', strtotime($mon . ' +4 days'));

        // 王晓: a daily report submitted on d0, a draft on d1, a weekly report last week, a result on his own quarter
        $this->login($f['uWang']['username']);
        $day0 = $this->make(array('title' => '日报 ' . $d0, 'level' => 'day', 'parent_id' => $q, 'start' => $d0, 'end' => $d0));
        $day1 = $this->make(array('title' => '日报 ' . $d1, 'level' => 'day', 'parent_id' => $q, 'start' => $d1, 'end' => $d1));
        $week = $this->make(array('title' => '上周周报', 'level' => 'week', 'parent_id' => $q, 'start' => $mon, 'end' => $fri));
        $this->status(200, $this->save($day0, array('progress' => '100', 'done' => '完成接口联调', 'submit' => true)));
        $this->status(200, $this->save($day1, array('progress' => '50', 'done' => '草稿', 'submit' => false)));
        $this->status(200, $this->save($week, array('progress' => '80', 'done' => '本周完成联调', 'submit' => true)));
        $this->status(200, $this->save($q, array('progress' => '30', 'done' => '联调进行中', 'submit' => true)));
        // members do not see statistics
        $this->status(403, $this->request('GET', '/api/v1/perf/stats'));

        // a plan given to 陈静 that has ended without a result, and one for 王晓 that has not started
        $this->login($f['uLead']['username']);
        $ended = $this->make(array('title' => '需求调研', 'level' => 'week', 'parent_id' => $annual, 'start' => $d1, 'end' => $d0, 'assignees' => array($f['chen'])));
        $later = $this->make(array('title' => '验收准备', 'level' => 'day', 'parent_id' => $annual, 'start' => date('Y-m-d', strtotime('+10 days')), 'end' => date('Y-m-d', strtotime('+10 days')),
            'assignees' => array($f['wang'])));
        $q0 = array('from' => $from, 'to' => $to);
        $res = $this->request('GET', '/api/v1/perf/stats', null, $q0);
        $this->status(200, $res);
        $d = $this->data($res);
        $rows = array();
        foreach ($d['rows'] as $r) {
            $rows[$r['person_id']] = $r;
        }
        $this->ok(isset($rows[$f['wang']], $rows[$f['chen']]) && !isset($rows[$f['lead']]), 'my people, not me');
        $this->ok(!isset($rows[$f['zhou']]), 'not another department');

        // assigned plans: 王晓 annual (doing through his quarter) + later (future); his own quarter, month, days, week do not count
        $w = $rows[$f['wang']]['plans'];
        $this->same(array(0, 1, 0, 1, 1, 100.0), array($w['done'], $w['doing'], $w['none'], $w['future'], $w['due'], (float) $w['rate']), 'wang plans');
        $c = $rows[$f['chen']]['plans'];
        $this->same(array(0, 0, 2, 2, 1, 0.0), array($c['executed'], $c['doing'], $c['none'], $c['due'], $c['overdue'], (float) $c['rate']), 'chen plans: annual + ended (overdue)');

        // daily reports: every workday from the period start is due
        $exp = $this->workdays($from, $today);
        $wd = $rows[$f['wang']]['day'];
        $this->same(array($exp, 1, $exp - 1), array($wd['expected'], $wd['submitted'], $wd['missing']), 'wang daily');
        $this->same(0, $rows[$f['chen']]['day']['submitted']);
        $this->ok($rows[$f['wang']]['week']['submitted'] === 1 && $rows[$f['wang']]['week']['expected'] >= 2, 'weekly');
        $this->same($exp * 2, $d['total']['day']['expected'], 'total');

        // the lists behind the figures
        $it = $this->data($this->request('GET', '/api/v1/perf/stats/items', null, array_merge($q0, array('kind' => 'plans', 'person_id' => $f['wang']))));
        $ids = array_map(function ($x) { return $x['plan']['id']; }, $it['rows']);
        sort($ids);
        $want = array($annual, $later);
        sort($want);
        $this->same($want, $ids, 'assigned plans of wang');
        foreach ($it['rows'] as $x) {
            if ($x['plan']['id'] === $annual) {
                $this->ok($x['state'] === 'doing' && $x['via_sub'], 'executed through a sub-plan');
            }
        }
        $it = $this->data($this->request('GET', '/api/v1/perf/stats/items', null, array_merge($q0, array('kind' => 'day', 'person_id' => $f['wang'], 'state' => 'missing', 'size' => 100))));
        $this->same($exp - 1, $it['total']);
        $st = array();
        foreach ($it['rows'] as $x) {
            $st[$x['key']] = $x['status'];
        }
        $this->same('draft', isset($st[$d1]) ? $st[$d1] : '', 'draft counts as missing');
        $this->same(1, $it['counts']['submitted']);
        $it = $this->data($this->request('GET', '/api/v1/perf/stats/items', null, array_merge($q0, array('kind' => 'plans', 'state' => 'overdue'))));
        $this->same(array($ended), array_map(function ($x) { return $x['plan']['id']; }, $it['rows']), 'overdue');
        // filters: one person, only people with gaps, keyword
        $this->same(1, count($this->data($this->request('GET', '/api/v1/perf/stats', null, array_merge($q0, array('person_id' => $f['chen']))))['rows']));
        $this->same(0, count($this->data($this->request('GET', '/api/v1/perf/stats', null, array_merge($q0, array('kw' => 'zz-nobody'))))['rows']));
        $this->ok(count($this->data($this->request('GET', '/api/v1/perf/stats', null, array_merge($q0, array('gaps' => 1))))['rows']) >= 1, 'gaps');
        $x = $this->request('GET', '/api/v1/perf/stats/export', null, $q0);
        $this->status(200, $x);
        $this->same('PK', substr($x->body, 0, 2), 'xlsx');

        // another department head sees neither; the unit head sees the department head too
        $this->login($f['uZhou']['username']);
        $ids = array_map(function ($r) { return $r['person_id']; }, $this->data($this->request('GET', '/api/v1/perf/stats', null, $q0))['rows']);
        $this->ok(!in_array($f['wang'], $ids, true) && !in_array($f['zhou'], $ids, true), 'other department');
        $this->login($f['uUnit']['username']);
        $ids = array_map(function ($r) { return $r['person_id']; }, $this->data($this->request('GET', '/api/v1/perf/stats', null, $q0))['rows']);
        $this->ok(in_array($f['lead'], $ids, true) && in_array($f['wang'], $ids, true) && !in_array($f['boss'], $ids, true), 'unit scope');
    }

    private function figures($q0, $personId)
    {
        foreach ($this->data($this->request('GET', '/api/v1/perf/stats', null, $q0))['rows'] as $r) {
            if ($r['person_id'] === $personId) {
                return $r;
            }
        }
        return null;
    }

    public function testHolidaysAndLeave()
    {
        $this->chain();
        $f = $this->f;
        DB::table('personnel_profiles')->whereIn('id', array($f['wang'], $f['chen'], $f['lead']))->update(array('created_at' => date('Y-m-d H:i:s', strtotime('-60 days'))));
        $q0 = array('from' => date('Y-m-d', strtotime('-20 days')), 'to' => date('Y-m-d'));
        $d0 = $this->lastWorkday(date('Y-m-d', strtotime('-2 days')));
        $d1 = $this->lastWorkday($d0);
        $sat = date('Y-m-d', strtotime('last saturday', strtotime('-1 day')));
        $this->login($f['uLead']['username']);
        $base = $this->figures($q0, $f['wang']);
        $exp = $base['day']['expected'];
        $this->ok($exp > 3, 'workdays expected');

        // holidays (system settings, super admin): a day off is no workday, a make-up workday is one
        $this->status(403, $this->request('POST', '/api/v1/holidays', array('name' => 'x', 'kind' => 'off', 'start' => $d0, 'end' => $d0)), 'leaders do not set holidays');
        $super = $this->user('super');
        $this->login($super['username']);
        $r = $this->request('POST', '/api/v1/holidays', array('name' => '测试假日', 'kind' => 'off', 'start' => $d0, 'end' => $d0));
        $this->status(200, $r);
        $hOff = $this->data($r)['id'];
        $this->status(422, $this->request('POST', '/api/v1/holidays', array('name' => '重叠', 'kind' => 'off', 'start' => $d0, 'end' => $d0)), 'overlap');
        $this->status(422, $this->request('POST', '/api/v1/holidays', array('name' => '倒序', 'kind' => 'off', 'start' => $d0, 'end' => $d1)), 'end before start');
        $this->status(422, $this->request('POST', '/api/v1/holidays', array('name' => '类型', 'kind' => 'x', 'start' => $d0, 'end' => $d0)), 'kind');
        $hWork = $this->data($this->request('POST', '/api/v1/holidays', array('name' => '周末补班', 'kind' => 'work', 'start' => $sat, 'end' => $sat)))['id'];
        $list = $this->data($this->request('GET', '/api/v1/holidays', null, array('year' => (int) substr($d0, 0, 4))));
        $this->ok(count($list['rows']) >= 1 && $list['workdays'] > 200, 'year list');
        $this->ok((int) DB::value('SELECT COUNT(*) FROM operation_logs WHERE action = ?', array('holiday_added')) >= 2, 'logged');
        $this->login($f['uLead']['username']);
        $after = $this->figures($q0, $f['wang']);
        $this->same($exp - 1 + ($sat >= $q0['from'] ? 1 : 0), $after['day']['expected'], 'holiday out, make-up day in');
        $days = array();
        foreach ($this->data($this->request('GET', '/api/v1/perf/stats/items', null, array_merge($q0, array('kind' => 'day', 'person_id' => $f['wang'], 'size' => 100))))['rows'] as $x) {
            $days[$x['key']] = true;
        }
        $this->ok(!isset($days[$d0]) && ($sat < $q0['from'] || isset($days[$sat])), 'the days listed');

        // leave (leaders of the person): no daily report expected that day
        $exp = $after['day']['expected'];
        $r = $this->request('POST', '/api/v1/perf/leaves', array('person_id' => $f['wang'], 'kind' => 'annual', 'start' => $d1, 'end' => $d1, 'note' => '回老家'));
        $this->status(200, $r);
        $lid = $this->data($r)['id'];
        $this->status(422, $this->request('POST', '/api/v1/perf/leaves', array('person_id' => $f['wang'], 'kind' => 'sick', 'start' => $d1, 'end' => $d1)), 'overlap');
        $this->status(422, $this->request('POST', '/api/v1/perf/leaves', array('person_id' => $f['zhou'], 'kind' => 'sick', 'start' => $d1, 'end' => $d1)), 'not my person');
        $this->status(422, $this->request('POST', '/api/v1/perf/leaves', array('person_id' => $f['lead'], 'kind' => 'sick', 'start' => $d1, 'end' => $d1)), 'not myself');
        $this->status(422, $this->request('POST', '/api/v1/perf/leaves', array('person_id' => $f['wang'], 'kind' => 'holiday', 'start' => $d1, 'end' => $d1)), 'kind');
        $this->same($exp - 1, $this->figures($q0, $f['wang'])['day']['expected'], 'leave day out');
        $this->same($exp, $this->figures($q0, $f['chen'])['day']['expected'], 'others unchanged');
        $l = $this->data($this->request('GET', '/api/v1/perf/leaves', null, array('person_id' => $f['wang'])));
        $this->same(1, $l['total']);
        $this->same(array('annual', 1, 1, '回老家'), array($l['rows'][0]['kind'], $l['rows'][0]['days'], $l['rows'][0]['workdays'], $l['rows'][0]['note']));
        $this->ok((int) DB::value('SELECT COUNT(*) FROM operation_logs WHERE action = ?', array('leave_added')) >= 1, 'logged');
        // another department head neither sees nor changes it; members have no access
        $this->login($f['uZhou']['username']);
        $this->same(0, $this->data($this->request('GET', '/api/v1/perf/leaves', null, array('person_id' => $f['wang'])))['total']);
        $this->status(404, $this->request('PUT', '/api/v1/perf/leaves/' . $lid, array('person_id' => $f['wang'], 'kind' => 'sick', 'start' => $d1, 'end' => $d1)));
        $this->status(404, $this->request('DELETE', '/api/v1/perf/leaves/' . $lid));
        $this->login($f['uWang']['username']);
        $this->status(403, $this->request('GET', '/api/v1/perf/leaves'));

        // a whole week of leave: no weekly report expected for it
        $this->login($f['uLead']['username']);
        $this->status(200, $this->request('DELETE', '/api/v1/perf/leaves/' . $lid));
        $weeks = $this->figures($q0, $f['wang'])['week']['expected'];
        $mon = date('Y-m-d', strtotime('monday this week -7 days'));
        $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
        $r = $this->request('POST', '/api/v1/perf/leaves', array('person_id' => $f['wang'], 'kind' => 'sick', 'start' => $mon, 'end' => $sun));
        $this->status(200, $r);
        $this->same($weeks - 1, $this->figures($q0, $f['wang'])['week']['expected'], 'week of leave');
        $this->status(200, $this->request('PUT', '/api/v1/perf/leaves/' . $this->data($r)['id'], array('person_id' => $f['wang'], 'kind' => 'sick', 'start' => $mon, 'end' => $mon)));
        $this->same($weeks, $this->figures($q0, $f['wang'])['week']['expected'], 'one day only');
        $this->same(array(), \Omps\Modules\Performance\Services\LeaveService::days(array($f['chen']), $mon, $sun));

        // clean up: holidays apply to the whole system
        $this->login($super['username']);
        $this->status(200, $this->request('DELETE', '/api/v1/holidays/' . $hOff));
        $this->status(200, $this->request('DELETE', '/api/v1/holidays/' . $hWork));
        $this->status(404, $this->request('DELETE', '/api/v1/holidays/' . $hWork));
    }

    private function notified($userId, $type, $leaveId)
    {
        return (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND biz_type = ? AND biz_id = ?', array((int) $userId, $type, 'person_leave', (int) $leaveId));
    }

    public function testLeaveApplication()
    {
        $this->chain();
        $f = $this->f;
        DB::table('personnel_profiles')->whereIn('id', array($f['wang'], $f['chen'], $f['lead']))->update(array('created_at' => date('Y-m-d H:i:s', strtotime('-60 days'))));
        $q0 = array('from' => date('Y-m-d', strtotime('-20 days')), 'to' => date('Y-m-d'));
        $d0 = $this->lastWorkday(date('Y-m-d', strtotime('-2 days')));
        $d1 = $this->lastWorkday($d0);
        $this->login($f['uLead']['username']);
        $exp = $this->figures($q0, $f['wang'])['day']['expected'];

        // 王晓 applies: waiting, not counted yet; his leader is told and has a to-do
        $this->login($f['uWang']['username']);
        $this->status(200, $this->request('GET', '/affairs/leave'));
        $r = $this->request('POST', '/api/v1/leave/mine', array('kind' => 'personal', 'start' => $d1, 'end' => $d1, 'note' => '家里有事'));
        $this->status(200, $r);
        $this->same('pending', $this->data($r)['status']);
        $lid = $this->data($r)['id'];
        $this->status(422, $this->request('POST', '/api/v1/leave/mine', array('kind' => 'sick', 'start' => $d1, 'end' => $d1)), 'overlaps the pending one');
        $this->status(200, $this->request('PUT', '/api/v1/leave/mine/' . $lid, array('kind' => 'annual', 'start' => $d1, 'end' => $d1)), 'change while pending');
        $mine = $this->data($this->request('GET', '/api/v1/leave/mine'));
        $this->same(array('annual', 'pending', true), array($mine['rows'][0]['kind'], $mine['rows'][0]['status'], $mine['rows'][0]['applied']));
        $this->ok($this->notified($f['uLead']['id'], 'leave_applied', $lid) >= 1, 'leader told');
        $this->status(403, $this->request('POST', '/api/v1/perf/leaves/' . $lid . '/approve', array()), 'members do not approve');
        $this->login($f['uLead']['username']);
        $this->same($exp, $this->figures($q0, $f['wang'])['day']['expected'], 'pending leave not counted');
        $l = $this->data($this->request('GET', '/api/v1/perf/leaves', null, array('status' => 'pending')));
        $this->same(array(1, 1), array($l['pending'], $l['total']));
        $kinds = array_map(function ($x) { return $x['kind']; }, $this->data($this->request('GET', '/api/v1/todo'))['rows']);
        $this->ok(in_array('leave_review', $kinds, true), 'in the to-do list');
        $this->login($f['uZhou']['username']);
        $this->status(404, $this->request('POST', '/api/v1/perf/leaves/' . $lid . '/approve', array()), 'another department');

        // approve: counted, the person is told; no more changes by the person
        $this->login($f['uLead']['username']);
        $this->status(422, $this->request('POST', '/api/v1/perf/leaves/' . $lid . '/reject', array()), 'a reason is needed');
        $this->status(200, $this->request('POST', '/api/v1/perf/leaves/' . $lid . '/approve', array()));
        $this->status(409, $this->request('POST', '/api/v1/perf/leaves/' . $lid . '/approve', array()), 'decided already');
        $this->same($exp - 1, $this->figures($q0, $f['wang'])['day']['expected'], 'approved leave counted');
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND biz_type = ? AND biz_id = ? AND read_at IS NULL', array($f['uLead']['id'], 'person_leave', $lid)), 'the leader message is read');
        $this->ok($this->notified($f['uWang']['id'], 'leave_approved', $lid) === 1, 'person told');
        $this->login($f['uWang']['username']);
        $this->status(409, $this->request('PUT', '/api/v1/leave/mine/' . $lid, array('kind' => 'sick', 'start' => $d1, 'end' => $d1)), 'approved: no change');
        $this->status(409, $this->request('POST', '/api/v1/leave/mine/' . $lid . '/withdraw', array()), 'approved: no withdraw');

        // withdraw while pending; reject with a reason
        $r2 = $this->data($this->request('POST', '/api/v1/leave/mine', array('kind' => 'sick', 'start' => $d0, 'end' => $d0)))['id'];
        $this->status(200, $this->request('POST', '/api/v1/leave/mine/' . $r2 . '/withdraw', array()));
        $r3 = $this->data($this->request('POST', '/api/v1/leave/mine', array('kind' => 'sick', 'start' => $d0, 'end' => $d0)))['id'];
        $this->login($f['uLead']['username']);
        $this->status(409, $this->request('POST', '/api/v1/perf/leaves/' . $r2 . '/approve', array()), 'withdrawn');
        $this->status(200, $this->request('POST', '/api/v1/perf/leaves/' . $r3 . '/reject', array('reason' => '那天有验收，请改期')));
        $this->ok($this->notified($f['uWang']['id'], 'leave_rejected', $r3) === 1, 'rejection told');
        $this->same($exp - 1, $this->figures($q0, $f['wang'])['day']['expected'], 'rejected leave not counted');
        $h = $this->data($this->request('GET', '/api/v1/todo/history', null, array('size' => 20)));
        $done = array_map(function ($x) { return $x['kind'] . '|' . $x['result']; }, $h['rows']);
        $this->ok(in_array('leave_review|approved', $done, true) && in_array('leave_review|rejected', $done, true), 'decisions in the history');
        $this->login($f['uWang']['username']);
        $this->same('rejected', $this->data($this->request('GET', '/api/v1/leave/mine', null, array('status' => 'rejected')))['rows'][0]['status']);

        // nobody above the unit head: applied leave applies at once; an account without a person cannot apply
        $this->login($f['uUnit']['username']);
        $this->same('approved', $this->data($this->request('POST', '/api/v1/leave/mine', array('kind' => 'annual', 'start' => $d0, 'end' => $d0)))['status']);
        $super = $this->user('super');
        $this->login($super['username']);
        $this->same(true, $this->data($this->request('GET', '/api/v1/leave/mine'))['no_person']);
        $this->status(403, $this->request('POST', '/api/v1/leave/mine', array('kind' => 'annual', 'start' => $d0, 'end' => $d0)));
    }

    public function testPagesAndMenus()
    {
        $this->login($this->f['uWang']['username']);
        $this->status(403, $this->request('GET', '/performance/review'));
        $html = $this->request('GET', '/plans/mine')->body;
        $this->contains('perf.js', $html);
        $this->contains('综合报告', $html, 'members have reports');
        $this->login($this->f['uLead']['username']);
        $html = $this->request('GET', '/performance/review')->body;
        $this->contains('review.js', $html);
        $home = $this->request('GET', '/')->body;
        $this->contains('业绩', $home);
    }
}
