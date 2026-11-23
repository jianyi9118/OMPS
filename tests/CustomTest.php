<?php
namespace Omps\Tests;

use Omps\Core\Settings;
use Omps\Database\DB;

/**
 * Phase 7: custom fields (personnel, devices, plans), statistics (scope, built-in pages, designed
 * pages, export), theme and login pictures. Every test removes what it defines, so later tests are
 * not affected.
 */
class CustomTest extends TestCase
{
    /** @var array */
    private $f;

    public function setUp()
    {
        parent::setUp();
        $s = substr(md5(uniqid('', true)), 0, 4);
        $a = $this->dept('统计甲部' . $s);
        $b = $this->dept('统计乙部' . $s);
        $lead = $this->person('甲部长' . $s, $a);
        $p1 = $this->person('甲一' . $s, $a);
        $p2 = $this->person('甲二' . $s, $a);
        $p3 = $this->person('乙一' . $s, $b);
        DB::table('personnel_profiles')->where('id', $p2)->update(array('gender' => 'female', 'birth_date' => '1990-05-01'));
        $this->f = array('s' => $s, 'a' => $a, 'b' => $b, 'lead' => $lead, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3,
            'uSuper' => $this->user('super'), 'uLead' => $this->user('dept_head', $lead), 'uP1' => $this->user('member', $p1), 'uUnit' => $this->user('unit_head', $this->person('统计单位' . $s, null)));
    }

    private function field(array $body)
    {
        $r = $this->request('POST', '/api/v1/custom-fields', $body);
        $this->status(200, $r, 'create field ' . (isset($body['field_key']) ? $body['field_key'] : ''));
        return $this->data($r)['id'];
    }

    private function names($zh, $en)
    {
        return array('zh-CN' => $zh, 'en-US' => $en);
    }

    public function testCustomFields()
    {
        $f = $this->f;
        $this->login($f['uUnit']['username']);
        $this->status(403, $this->request('GET', '/api/v1/custom-fields'), 'super admin only');
        $this->login($f['uSuper']['username']);
        $this->status(200, $this->request('GET', '/system/custom', null, array(), array('Accept' => 'text/html')));
        $k = 'k' . $f['s'];
        $this->status(422, $this->request('POST', '/api/v1/custom-fields', array('entity' => 'personnel', 'field_key' => 'Bad Key', 'data_type' => 'text', 'names' => $this->names('爱好', 'Hobby'))), 'key format');
        $r = $this->request('POST', '/api/v1/custom-fields', array('entity' => 'personnel', 'field_key' => $k . 'h', 'data_type' => 'text', 'names' => array('zh-CN' => '爱好')));
        $this->status(422, $r, 'a name in every language');
        $this->ok(isset($r->data['error']['fields']['name_en-US']));
        $this->status(422, $this->request('POST', '/api/v1/custom-fields', array('entity' => 'personnel', 'field_key' => $k . 's', 'data_type' => 'select', 'names' => $this->names('技能等级', 'Skill level'))), 'choices needed');
        $hobby = $this->field(array('entity' => 'personnel', 'field_key' => $k . 'h', 'data_type' => 'text', 'names' => $this->names('爱好' . $f['s'], 'Hobby ' . $f['s']), 'searchable' => true, 'in_list' => true));
        $skill = $this->field(array('entity' => 'personnel', 'field_key' => $k . 's', 'data_type' => 'select', 'names' => $this->names('技能等级' . $f['s'], 'Skill ' . $f['s']),
            'searchable' => true, 'statable' => true, 'options' => array(array('names' => $this->names('高级', 'Senior')), array('names' => $this->names('中级', 'Middle')))));
        $this->status(422, $this->request('POST', '/api/v1/custom-fields', array('entity' => 'personnel', 'field_key' => $k . 'h', 'data_type' => 'text', 'names' => $this->names('x' . $f['s'], 'x' . $f['s']))), 'key unique');
        $this->status(422, $this->request('POST', '/api/v1/custom-fields', array('entity' => 'personnel', 'field_key' => $k . 'x', 'data_type' => 'text', 'names' => $this->names('爱好' . $f['s'], 'y'))), 'name unique');

        // values on a record (administrators), audit, list column, keyword search incl. a choice name
        $this->login($f['uUnit']['username']);
        $this->status(422, $this->request('PUT', '/api/v1/personnel/' . $f['p1'], array('custom' => array($k . 's' => 'nope'))), 'unknown choice');
        $this->status(200, $this->request('PUT', '/api/v1/personnel/' . $f['p1'], array('custom' => array($k . 'h' => '钓鱼' . $f['s'], $k . 's' => 'o1'))));
        $d = $this->data($this->request('GET', '/api/v1/personnel/' . $f['p1']));
        $this->same('钓鱼' . $f['s'], $d['custom']['values'][$k . 'h']);
        $this->same('o1', $d['custom']['values'][$k . 's']);
        $this->ok((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'person_updated' AND after_data LIKE ?", array('%cf:' . $k . 's%')) >= 1, 'change logged');
        $lr = $this->request('GET', '/api/v1/staff', null, array('kw' => '钓鱼' . $f['s']));
        $this->status(200, $lr, 'staff search');
        $list = $this->data($lr);
        $this->same(1, $list['total']);
        $this->ok(in_array($k . 'h', array_column($list['custom_cols'], 'key'), true));
        $this->same('钓鱼' . $f['s'], $list['rows'][0]['custom'][$k . 'h']);
        $this->status(200, $this->request('POST', '/api/v1/lang', array('lang' => 'en-US')));
        $er = $this->request('GET', '/api/v1/staff', null, array('kw' => 'Senior', 'dept_id' => $f['a']));
        $this->status(200, $er, 'english search');
        $this->same(1, $this->data($er)['total'], 'choice names in any language are searched');
        // one's own profile shows them read-only; values sent there are ignored
        $this->login($f['uP1']['username']);
        $this->request('PUT', '/api/v1/me/profile', array('profile' => array('email' => 'a' . $f['s'] . '@example.com'), 'custom' => array($k . 'h' => 'x')));
        $this->same('钓鱼' . $f['s'], (string) DB::value('SELECT v_string FROM custom_field_values WHERE field_id = ? AND entity_id = ?', array($hobby, $f['p1'])));

        // devices: a required number field
        $this->login($f['uSuper']['username']);
        $years = $this->field(array('entity' => 'device', 'field_key' => $k . 'y', 'data_type' => 'int', 'names' => $this->names('保修年限' . $f['s'], 'Warranty years ' . $f['s']), 'required' => true, 'statable' => true));
        $this->login($f['uUnit']['username']);
        $r = $this->request('POST', '/api/v1/devices', array('asset_no' => 'CF-' . $f['s'], 'name' => 'x', 'category' => 'pc'));
        $this->status(422, $r, 'required custom field');
        $this->ok(isset($r->data['error']['fields']['cf__' . $k . 'y']));
        $this->status(422, $this->request('POST', '/api/v1/devices', array('asset_no' => 'CF-' . $f['s'], 'name' => 'x', 'category' => 'pc', 'custom' => array($k . 'y' => 'abc'))));
        $dev = $this->data($this->request('POST', '/api/v1/devices', array('asset_no' => 'CF-' . $f['s'], 'name' => 'x', 'category' => 'pc', 'custom' => array($k . 'y' => '3'))))['id'];
        $this->same(3, $this->data($this->request('GET', '/api/v1/devices/' . $dev))['custom']['values'][$k . 'y']);

        // plans: a date field, searched in the plan tree
        $this->login($f['uSuper']['username']);
        $note = $this->field(array('entity' => 'plan', 'field_key' => $k . 'n', 'data_type' => 'text', 'names' => $this->names('立项文号' . $f['s'], 'File no. ' . $f['s']), 'searchable' => true));
        $this->login($f['uUnit']['username']);
        $plan = $this->data($this->request('POST', '/api/v1/plans', array('title' => '自定义字段计划', 'level' => 'annual', 'start' => '2026-01-01', 'end' => '2026-12-31',
            'assignees' => array($f['lead']), 'custom' => array($k . 'n' => '综发〔2026〕' . $f['s']))))['id'];
        $tree = $this->data($this->request('GET', '/api/v1/plans', null, array('mode' => 'tree', 'kw' => '〔2026〕' . $f['s'])));
        $this->ok(in_array($plan, array_column($tree['rows'], 'id'), true), 'plan found by custom text');

        // fields with values: cannot be deleted, can be disabled (values kept); used choices cannot go
        $this->login($f['uSuper']['username']);
        $this->status(409, $this->request('DELETE', '/api/v1/custom-fields/' . $skill));
        $this->status(422, $this->request('PUT', '/api/v1/custom-fields/' . $skill, array('names' => $this->names('技能等级' . $f['s'], 'Skill ' . $f['s']), 'data_type' => 'select',
            'options' => array(array('key' => 'o2', 'names' => $this->names('中级', 'Middle'))))), 'a used choice cannot be removed');
        $this->status(422, $this->request('PUT', '/api/v1/custom-fields/' . $skill, array('names' => $this->names('技能等级' . $f['s'], 'Skill ' . $f['s']), 'data_type' => 'text')), 'type locked');
        foreach (array($hobby, $skill, $years, $note) as $id) {
            $row = DB::table('custom_fields')->find($id);
            $names = array();
            foreach (DB::table('translations')->where('namespace', 'cf')->where('tkey', $row['entity'] . '.' . $row['field_key'])->get() as $t) {
                $names[$t['lang']] = $t['text'];
            }
            $opts = array();
            foreach (DB::table('custom_field_options')->where('field_id', $id)->get() as $o) {
                $on = array();
                foreach (DB::table('translations')->where('namespace', 'cfo')->where('tkey', $id . '.' . $o['opt_key'])->get() as $t) {
                    $on[$t['lang']] = $t['text'];
                }
                $opts[] = array('key' => $o['opt_key'], 'names' => $on);
            }
            $this->status(200, $this->request('PUT', '/api/v1/custom-fields/' . $id, array('names' => $names, 'data_type' => $row['data_type'], 'options' => $opts, 'enabled' => false)));
        }
        $this->login($f['uUnit']['username']);
        $d = $this->data($this->request('GET', '/api/v1/personnel/' . $f['p1']));
        $this->same(array(), array_values(array_filter($d['custom']['defs'], function ($x) use ($k) {
            return strpos($x['key'], $k) === 0;
        })), 'disabled fields are not shown');
        $this->ok(DB::table('custom_field_values')->where('field_id', $hobby)->exists(), 'values kept');
        $this->status(200, $this->request('POST', '/api/v1/devices', array('asset_no' => 'CF2-' . $f['s'], 'name' => 'y', 'category' => 'pc')), 'a disabled required field is not required');
    }

    public function testStatistics()
    {
        $f = $this->f;
        $this->login($f['uP1']['username']);
        $this->status(403, $this->request('GET', '/api/v1/stats/people'), 'members have no statistics');
        $this->login($f['uLead']['username']);
        $home = $this->request('GET', '/', null, array(), array('Accept' => 'text/html'));
        $this->contains('数据统计', $home->body);
        $p = $this->data($this->request('GET', '/api/v1/stats/people'));
        $this->same(3, $p['kpis'][0]['v'], 'department scope: 3 people in 甲部');
        $this->same(1, $p['kpis'][2]['v'], 'one woman');
        $byDept = $p['widgets'][0]['result']['groups'];
        $this->same(array('统计甲部' . $f['s']), array_column($byDept, 'label'));
        $d = $this->data($this->request('GET', '/api/v1/stats/depts'));
        $this->same(array($f['a']), array_column($d['rows'], 'id'), 'only own department');
        $this->same(3, $d['rows'][0]['people']);
        $this->login($f['uUnit']['username']);
        $all = $this->data($this->request('GET', '/api/v1/stats/people'));
        $this->ok($all['kpis'][0]['v'] >= 4, 'unit head counts everyone');

        // designed page: names in every language, controlled widgets, results within each viewer's scope
        $this->login($f['uSuper']['username']);
        $this->status(422, $this->request('POST', '/api/v1/stat-pages', array('names' => array('zh-CN' => '人员概况'), 'widgets' => array())));
        $this->status(422, $this->request('POST', '/api/v1/stat-pages', array('names' => $this->names('人员概况', 'People'), 'widgets' => array(array('chart' => 'bar', 'def' => array('entity' => 'personnel', 'dim' => 'salary'))))), 'unknown dimension');
        $this->status(422, $this->request('POST', '/api/v1/stat-pages', array('names' => $this->names('人员概况', 'People'), 'widgets' => array(array('chart' => 'bar', 'def' => array('entity' => 'users', 'dim' => 'dept'))))), 'unknown entity');
        $widgets = array(array('chart' => 'pie', 'def' => array('entity' => 'personnel', 'dim' => 'gender', 'measure' => 'count')),
            array('chart' => 'number', 'def' => array('entity' => 'personnel', 'measure' => 'count')),
            array('chart' => 'bar', 'def' => array('entity' => 'plan', 'dim' => 'level', 'measure' => 'count')));
        $prev = $this->data($this->request('POST', '/api/v1/stat-pages/preview', $widgets[0]));
        $this->ok($prev['result']['total'] >= 4);
        $pid = $this->data($this->request('POST', '/api/v1/stat-pages', array('names' => $this->names('人员概况' . $f['s'], 'People ' . $f['s']), 'widgets' => $widgets)))['id'];
        $this->login($f['uLead']['username']);
        $pages = $this->data($this->request('GET', '/api/v1/stats/pages'))['pages'];
        $this->ok(in_array($pid, array_column($pages, 'id'), true));
        $pg = $this->data($this->request('GET', '/api/v1/stats/pages/' . $pid));
        $this->same('人员概况' . $f['s'], $pg['title']);
        $this->same(3, (int) $pg['widgets'][1]['result']['total'], 'the department head counts only 甲部');
        $this->status(200, $this->request('GET', '/api/v1/stats/export', null, array('which' => 'page', 'id' => $pid)));
        $this->status(200, $this->request('GET', '/api/v1/stats/export', null, array('which' => 'depts')));
        $this->status(200, $this->request('GET', '/stats/people', null, array(), array('Accept' => 'text/html')));
        $this->login($f['uSuper']['username']);
        $this->status(200, $this->request('DELETE', '/api/v1/stat-pages/' . $pid));
        $this->ok(!DB::table('translations')->where('namespace', 'stat')->where('tkey', 'page.' . $pid)->exists());
        $this->ok($this->auditCount('stat_page_created') >= 1 && $this->auditCount('stat_page_deleted') >= 1);
    }

    public function testThemeAndLoginPictures()
    {
        $f = $this->f;
        $this->login($f['uUnit']['username']);
        $this->status(403, $this->request('PUT', '/api/v1/theme', array('primary' => 'teal')));
        $this->login($f['uSuper']['username']);
        $this->status(422, $this->request('PUT', '/api/v1/theme', array('primary' => 'pink')));
        $this->status(200, $this->request('PUT', '/api/v1/theme', array('primary' => 'teal', 'nav' => 'light', 'top' => 'primary', 'default' => 'dark')));
        $page = $this->request('GET', '/', null, array(), array('Accept' => 'text/html'))->body;
        $this->contains('data-theme="dark"', $page);
        $this->contains('nav-light top-primary', $page);
        $this->contains('--primary:#0E7480', $page);
        $this->status(200, $this->request('POST', '/api/v1/me/theme', array('mode' => 'light')));
        $this->contains('data-theme="light"', $this->request('GET', '/', null, array(), array('Accept' => 'text/html'))->body, 'own choice wins');
        $this->status(422, $this->request('POST', '/api/v1/me/theme', array('mode' => 'pink')));

        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->status(200, $this->upload('/api/v1/theme/logo', array(array('path' => $png, 'name' => 'logo.png'))));
        $txt = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($txt, 'not an image');
        $this->status(422, $this->upload('/api/v1/theme/banners', array(array('path' => $txt, 'name' => 'a.txt'))), 'pictures only');
        $this->status(200, $this->upload('/api/v1/theme/banners', array(array('path' => $png, 'name' => 'banner.png'))));
        $rel = (int) Settings::get('login_banners')[0]['rel'];
        $this->status(200, $this->request('PUT', '/api/v1/theme/banners', array('banners' => array(array('rel' => $rel, 'enabled' => true, 'captions' => $this->names('欢迎使用', 'Welcome'))))));
        // before sign-in: the configured pictures only
        $this->cookies = array();
        $this->status(200, $this->request('GET', '/brand/logo'));
        $this->status(200, $this->request('GET', '/brand/banner/' . $rel));
        $this->status(404, $this->request('GET', '/brand/banner/' . ($rel + 100000)));
        $login = $this->request('GET', '/login', null, array(), array('Accept' => 'text/html'))->body;
        $this->ok(strpos($login, '/brand/banner/' . $rel) !== false || strpos($login, rawurlencode('/brand/banner/' . $rel)) !== false, 'login picture shown');
        $this->contains('欢迎使用', $login);
        $this->contains('data-theme="dark"', $login, 'the default mode applies before sign-in');

        // back to the defaults for the other tests
        $this->login($f['uSuper']['username']);
        $this->status(200, $this->request('PUT', '/api/v1/theme/banners', array('banners' => array())));
        $this->status(200, $this->request('DELETE', '/api/v1/theme/logo'));
        $this->status(200, $this->request('PUT', '/api/v1/theme', array('primary' => 'blue', 'nav' => 'dark', 'top' => 'white', 'default' => 'auto')));
        $this->status(404, $this->request('GET', '/brand/logo'));
        $this->same(array(), Settings::get('login_banners'));
        $this->ok($this->auditCount('theme_changed') >= 2);
    }
}
