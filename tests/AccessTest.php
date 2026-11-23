<?php
namespace Omps\Tests;

use Omps\Database\DB;

/**
 * Acceptance 4, 5, 6: menus by role, device-admin menu, data isolation by department,
 * function permissions enforced by the API (not just hidden buttons).
 */
class AccessTest extends TestCase
{
    /** @var array */
    private $f;

    public function setUp()
    {
        parent::setUp();
        $tech = $this->dept('技术部');
        $soft = $this->dept('软件组', $tech);
        $hr = $this->dept('综合部');
        $this->f = array(
            'tech' => $tech, 'soft' => $soft, 'hr' => $hr,
            'lead' => $this->person('李建国', $tech), 'wang' => $this->person('王晓', $soft), 'zhou' => $this->person('周敏', $hr), 'zhao' => $this->person('赵磊', $tech),
        );
    }

    private function menuOf($user)
    {
        $this->login($user['username']);
        // members are sent from / to 我的任务, so the menu is read on the own-profile page
        $html = $this->request('GET', '/me/profile')->body;
        preg_match_all('/<a class="mi[^"]*" href="[^"]*"[^>]*>(?:<svg[^>]*><\/svg>)?<span class="lbl">([^<]+)<\/span>/u', $html, $m);
        return $m[1];
    }

    public function testMenusDifferByRole()
    {
        $super = $this->menuOf($this->user('super'));
        $unit = $this->menuOf($this->user('unit_head'));
        $lead = $this->menuOf($this->user('dept_head', $this->f['lead']));
        $member = $this->menuOf($this->user('member', $this->f['wang']));
        $dev = $this->menuOf($this->user('member', $this->f['zhao'], array('is_device_admin' => 1)));
        $this->ok(in_array('角色权限', $super, true) && in_array('日志查询', $super, true), 'super sees technical pages');
        $this->ok(in_array('用户管理', $unit, true) && !in_array('角色权限', $unit, true) && !in_array('系统设置', $unit, true), 'unit head: users but no technical pages');
        $this->ok(in_array('人员档案', $lead, true) && !in_array('用户管理', $lead, true), 'dept head');
        $this->ok(!in_array('部门管理', $member, true) && !in_array('设备档案', $member, true), 'member');
        $this->ok(in_array('设备档案', $dev, true), 'device admin sees the device menu');
        $this->status(200, $this->request('GET', '/device/archive'), 'device admin opens the device page');
    }

    public function testDeptHeadCannotReadOtherDepartments()
    {
        $this->login($this->user('dept_head', $this->f['lead'])['username']);
        $list = $this->data($this->request('GET', '/api/v1/staff', null, array('size' => 100)));
        $names = array_map(function ($r) { return $r['name']; }, $list['rows']);
        $this->ok(in_array('王晓', $names, true) && in_array('李建国', $names, true), 'own department and its groups');
        $this->ok(!in_array('周敏', $names, true), 'not other departments');

        $before = $this->auditCount('access_denied');
        $r = $this->request('GET', '/api/v1/staff', null, array('dept_id' => $this->f['hr']));
        $this->status(200, $r);
        $this->same(0, $this->data($r)['total'], 'filter outside scope returns nothing');
        $this->status(404, $this->request('GET', '/api/v1/staff/' . $this->f['zhou']), 'single record outside scope is 404');
        $this->status(404, $this->request('PUT', '/api/v1/staff/' . $this->f['zhou'], array('name' => 'x', 'gender' => 'male')), 'cannot edit outside scope');
        $this->status(404, $this->request('GET', '/api/v1/staff/999999'), 'missing record looks the same');
        $this->ok($this->auditCount('access_denied') >= $before + 3, 'out-of-scope attempts are audited');
    }

    public function testMemberSeesOnlySelf()
    {
        $this->login($this->user('member', $this->f['wang'])['username']);
        $list = $this->data($this->request('GET', '/api/v1/staff'));
        $this->same(1, $list['total']);
        $this->same('王晓', $list['rows'][0]['name']);
        $this->status(404, $this->request('GET', '/api/v1/staff/' . $this->f['lead']));
    }

    public function testFunctionPermissionsEnforcedByApi()
    {
        $this->login($this->user('member', $this->f['wang'])['username']);
        $this->status(403, $this->request('GET', '/api/v1/users'), 'member cannot list users');
        $this->status(403, $this->request('POST', '/api/v1/users', array('username' => 'abc', 'role_id' => 1)), 'member cannot create users');
        $this->status(403, $this->request('POST', '/api/v1/depts', array('name' => 'x')), 'member cannot add departments');
        $this->status(403, $this->request('POST', '/api/v1/staff', array('name' => 'x', 'gender' => 'male')), 'member cannot add people');

        $this->login($this->user('unit_head')['username']);
        $this->status(403, $this->request('GET', '/system/settings'), 'unit head cannot open settings');
        $this->status(403, $this->request('GET', '/api/v1/logs/audit'), 'unit head cannot read logs');
        $this->status(403, $this->request('PUT', '/api/v1/roles/2/permissions', array('codes' => array())), 'unit head cannot change permissions');
        $this->ok($this->auditCount('access_denied') > 0);
    }

    public function testUnitHeadCannotCreateSuperOrEditSuper()
    {
        $super = $this->user('super');
        $this->login($this->user('unit_head')['username']);
        $superRole = (int) DB::value("SELECT id FROM roles WHERE code = 'super'");
        $r = $this->request('POST', '/api/v1/users', array('username' => 'evil_admin', 'display_name' => 'x', 'role_id' => $superRole));
        $this->status(422, $r, 'cannot assign super');
        $this->status(404, $this->request('POST', '/api/v1/users/' . $super['id'] . '/reset-password', array()), 'super accounts are invisible to unit heads');
    }
}
