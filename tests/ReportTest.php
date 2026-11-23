<?php
namespace Omps\Tests;

use Omps\Database\DB;

/**
 * Phase 4: comprehensive reports. Listing rules (own results, results below when a sub-task has
 * none, approved only), scope of tasks and people, the report list and its period filter,
 * versions, manual reports with documents, downloads, owner-only access.
 */
class ReportTest extends TestCase
{
    /** @var array */
    private $f;

    public function setUp()
    {
        parent::setUp();
        $s = substr(md5(uniqid('', true)), 0, 4);
        $tech = $this->dept('技术部' . $s);
        $hr = $this->dept('综合部' . $s);
        $lead = $this->person('李建国', $tech);
        $wang = $this->person('王晓', $tech);
        $chen = $this->person('陈静', $tech);
        $zhou = $this->person('周敏', $hr);
        $boss = $this->person('张伟', null);
        $this->f = array('lead' => $lead, 'wang' => $wang, 'chen' => $chen, 'zhou' => $zhou, 'boss' => $boss,
            'uLead' => $this->user('dept_head', $lead), 'uWang' => $this->user('member', $wang), 'uChen' => $this->user('member', $chen),
            'uZhou' => $this->user('dept_head', $zhou), 'uUnit' => $this->user('unit_head', $boss));
    }

    private function make(array $body)
    {
        $r = $this->request('POST', '/api/v1/plans', $body);
        $this->status(200, $r, 'create ' . $body['title']);
        return $this->data($r)['id'];
    }

    private function submit($planId, $progress, $done)
    {
        $this->status(200, $this->request('PUT', '/api/v1/perf/plans/' . $planId . '/mine', array('progress' => $progress, 'done' => $done, 'submit' => true)));
        return (int) DB::value('SELECT id FROM performance_reports WHERE plan_id = ? ORDER BY id DESC LIMIT 1', array($planId));
    }

    /**
     * long (unit) -> annual (王晓, 陈静, no own results) -> 王晓 Q4 (approved) and 陈静 November (approved),
     * plus 王晓 week under Q4 (submitted only, must not appear).
     */
    private function data4()
    {
        $this->login($this->f['uUnit']['username']);
        $long = $this->make(array('title' => '2026–2030 数字化建设规划', 'level' => 'long', 'start' => '2026-01-01', 'end' => '2030-12-31', 'assignees' => array($this->f['lead'])));
        $this->login($this->f['uLead']['username']);
        $annual = $this->make(array('title' => '2026 年度数字化建设计划', 'level' => 'annual', 'parent_id' => $long, 'start' => '2026-01-01', 'end' => '2026-12-31',
            'weight' => 20, 'assignees' => array($this->f['wang'], $this->f['chen'])));
        $this->login($this->f['uWang']['username']);
        $q = $this->make(array('title' => '第四季度 · 联调与测试', 'level' => 'quarter', 'parent_id' => $annual, 'start' => '2026-10-01', 'end' => '2026-12-31', 'weight' => 50));
        $wk = $this->make(array('title' => '第 47 周', 'level' => 'week', 'parent_id' => $q, 'start' => '2026-11-16', 'end' => '2026-11-22'));
        $r1 = $this->submit($q, '60', '完成平台第一阶段验收');
        $this->submit($wk, '50', '未审核的周业绩');
        $this->login($this->f['uChen']['username']);
        $nov = $this->make(array('title' => '11 月 · 设备登记', 'level' => 'month', 'parent_id' => $annual, 'start' => '2026-11-01', 'end' => '2026-11-30', 'weight' => 50));
        $r2 = $this->submit($nov, '90', '完成 8 台办公电脑登记');
        $this->login($this->f['uLead']['username']);
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($r1, $r2))));
        return array($long, $annual, $q, $nov, $wk);
    }

    private function gen(array $body)
    {
        $r = $this->request('POST', '/api/v1/reports/generate', $body);
        $this->status(200, $r, 'generate');
        $id = $this->data($r)['id'];
        return array($id, $this->data($this->request('GET', '/api/v1/reports/' . $id)));
    }

    public function testListingRules()
    {
        list($long, $annual, $q, $nov) = $this->data4();
        $this->login($this->f['uUnit']['username']);
        list($id, $d) = $this->gen(array('start' => '2026-10-01', 'end' => '2026-11-30'));
        $c = $d['content'];
        $sec = null;
        foreach ($c['sections'] as $s) {
            if ($s['plan']['id'] === $long) {
                $sec = $s;
            }
        }
        $this->ok($sec !== null, 'long-term plan is a section');
        $this->same(1, count($sec['children']), 'its sub-task in the period');
        $ch = $sec['children'][0];
        $this->same($annual, $ch['plan']['id']);
        $this->same(array(), $ch['own'], 'annual plan has no results of its own');
        $this->same(array('完成平台第一阶段验收', '完成 8 台办公电脑登记'), array_map(function ($x) { return $x['done']; }, $ch['below']), 'results below, by date');
        $this->same(array('王晓', '陈静'), array_map(function ($x) { return $x['person']; }, $ch['below']));
        $this->notContains('未审核的周业绩', json_encode($c, JSON_UNESCAPED_UNICODE), 'approved results only');
        $this->same(75.0, (float) $ch['progress'], 'annual completion = sub-plans by weight (60 and 90 at 50% each)');
        $this->same('', $d['title_custom']);
        $this->contains('2026-10-01', $d['title'], 'default title from the period');

        // people filter and choosing a task together with its parent
        list(, $d2) = $this->gen(array('start' => '2026-10-01', 'end' => '2026-11-30', 'person_ids' => array($this->f['chen']), 'plan_ids' => array($annual, $nov)));
        $this->same(1, count($d2['content']['sections']), 'a chosen task under a chosen parent is listed once');
        $this->same($annual, $d2['content']['sections'][0]['plan']['id']);
        $this->same(array('陈静'), array_map(function ($x) { return $x['person']; }, $d2['content']['sections'][0]['children'][0]['own']), 'only chosen people');
        $this->same(array('陈静'), $d2['content']['people']);

        // a period outside the plans
        list(, $d3) = $this->gen(array('start' => '2025-01-01', 'end' => '2025-03-31'));
        $this->same(0, $d3['content']['stats']['tasks']);
    }

    public function testScopeAndOwnership()
    {
        list($long, $annual, $q, $nov) = $this->data4();
        // member: always only themself
        $this->login($this->f['uWang']['username']);
        $meta = $this->data($this->request('GET', '/api/v1/reports/meta'));
        $this->ok($meta['fixed_person'] && count($meta['people']) === 1, 'members: only themself');
        list($id, $d) = $this->gen(array('start' => '2026-10-01', 'end' => '2026-12-31', 'person_ids' => array($this->f['chen'])));
        $this->same(array('王晓'), $d['content']['people'], 'people fixed to oneself');
        $this->notContains('完成 8 台办公电脑登记', json_encode($d['content'], JSON_UNESCAPED_UNICODE), 'no results of others');
        $this->status(422, $this->request('POST', '/api/v1/reports/generate', array('start' => '2026-10-01', 'end' => '2026-12-31', 'plan_ids' => array($nov))), 'task not visible');
        // department head: people of the own department only
        $this->login($this->f['uZhou']['username']);
        $this->status(422, $this->request('POST', '/api/v1/reports/generate', array('start' => '2026-10-01', 'end' => '2026-12-31', 'person_ids' => array($this->f['wang']))), 'person outside scope');
        $this->status(404, $this->request('GET', '/api/v1/reports/' . $id), 'reports belong to their creator');
        $this->status(404, $this->request('DELETE', '/api/v1/reports/' . $id));
        $this->ok($this->auditCount('access_denied') >= 1);
    }

    public function testListVersionsManualAndDownloads()
    {
        $this->data4();
        $this->login($this->f['uLead']['username']);
        list($id, $d) = $this->gen(array('start' => '2026-10-01', 'end' => '2026-11-30'));
        // edit texts -> version 2; version 1 unchanged
        $this->status(200, $this->request('PUT', '/api/v1/reports/' . $id, array('title' => '10–11 月技术部业绩', 'intro' => '开头说明', 'outro' => '', 'version' => 1)));
        $this->status(409, $this->request('PUT', '/api/v1/reports/' . $id, array('title' => 'x', 'version' => 1)), 'stale version');
        $v2 = $this->data($this->request('GET', '/api/v1/reports/' . $id));
        $this->same(2, $v2['version']);
        $this->same('10–11 月技术部业绩', $v2['title']);
        $v1 = $this->data($this->request('GET', '/api/v1/reports/' . $id, null, array('v' => 1)));
        $this->same('', $v1['title_custom']);
        $this->same($d['content'], $v2['content'], 'data snapshot kept');

        // manual reports: text or documents
        $this->status(422, $this->upload('/api/v1/reports/manual', array(), array('title' => '10 月份工作总结', 'start' => '2026-10-01', 'end' => '2026-10-31', 'body' => '')), 'text or document required');
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, "%PDF-1.4\n% test\n");
        $r = $this->upload('/api/v1/reports/manual', array(array('path' => $pdf, 'name' => '10月总结.pdf')), array('title' => '10 月份工作总结', 'start' => '2026-10-01', 'end' => '2026-10-31', 'body' => ''));
        $this->status(200, $r);
        $mid = $this->data($r)['id'];
        $md = $this->data($this->request('GET', '/api/v1/reports/' . $mid));
        $this->same(1, count($md['docs']));
        $this->status(200, $this->upload('/api/v1/reports/' . $mid . '/files', array(array('path' => $pdf, 'name' => '10月总结-签字版.pdf'))));

        // list filter by period (fully inside the range)
        $all = $this->data($this->request('GET', '/api/v1/reports'));
        $this->same(2, $all['total']);
        $inOct = $this->data($this->request('GET', '/api/v1/reports', null, array('per' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31')));
        $this->same(array($mid), array_map(function ($x) { return $x['id']; }, $inOct['rows']), 'October only: the generated Oct–Nov report is not inside');
        $kw = $this->data($this->request('GET', '/api/v1/reports', null, array('kw' => '技术部业绩')));
        $this->same(array($id), array_map(function ($x) { return $x['id']; }, $kw['rows']));

        // downloads
        $w = $this->request('GET', '/api/v1/reports/' . $id . '/download', null, array('format' => 'docx'));
        $this->status(200, $w);
        $this->same('PK', substr($w->body, 0, 2), 'Word file');
        $this->contains('wordprocessingml', $w->headers['Content-Type']);
        $x = $this->request('GET', '/api/v1/reports/' . $id . '/download', null, array('format' => 'xlsx'));
        $this->status(200, $x);
        $this->same('PK', substr($x->body, 0, 2), 'Excel file');
        $this->status(400, $this->request('GET', '/api/v1/reports/' . $mid . '/download', null, array('format' => 'xlsx')), 'manual: no details');
        $this->status(200, $this->request('POST', '/api/v1/reports/' . $id . '/printed', array()));
        $this->same(3, (int) DB::value('SELECT COUNT(*) FROM report_exports WHERE report_id = ?', array($id)), 'every print and download logged');

        // delete: documents go to the recycle bin
        $fileIds = DB::table('file_relations')->where('biz_type', 'report')->where('biz_id', $mid)->pluck('file_id');
        $this->status(200, $this->request('DELETE', '/api/v1/reports/' . $mid));
        $this->same(0, (int) DB::table('files')->whereIn('id', $fileIds)->where('status', 'normal')->count(), 'documents in the recycle bin');
        $this->same(1, $this->data($this->request('GET', '/api/v1/reports'))['total']);
        @unlink($pdf);
    }
}
