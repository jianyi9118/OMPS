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
