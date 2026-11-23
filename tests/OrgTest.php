<?php
namespace Omps\Tests;

use Omps\Database\DB;

/**
 * Acceptance 2 and design v0.29/v0.30: departments (tree, order, delete rules),
 * staff add / remove by department heads following role permissions and scope.
 */
class OrgTest extends TestCase
{
    public function testDepartmentTreeOrderAndDelete()
    {
        $this->login($this->user('unit_head')['username']);
        $a = $this->data($this->request('POST', '/api/v1/depts', array('name' => '甲部门')))['id'];
        $b = $this->data($this->request('POST', '/api/v1/depts', array('name' => '乙部门')))['id'];
        $g = $this->data($this->request('POST', '/api/v1/depts', array('name' => '甲一组', 'parent_id' => $a)))['id'];
        $this->status(200, $this->request('POST', '/api/v1/depts/' . $b . '/move', array('dir' => 'up')));
        $tree = $this->data($this->request('GET', '/api/v1/depts'))['depts'];
        $order = array_map(function ($d) { return $d['name']; }, $tree);
        $this->ok(array_search('乙部门', $order, true) < array_search('甲部门', $order, true), 'order kept after move');
        $kid = array_values(array_filter($tree, function ($d) use ($g) { return $d['id'] === $g; }))[0];
        $this->same(1, $kid['depth']);

        $this->status(422, $this->request('PUT', '/api/v1/depts/' . $a, array('name' => '甲部门', 'parent_id' => $g)), 'no cycles');
        $this->status(409, $this->request('DELETE', '/api/v1/depts/' . $a), 'cannot delete with groups');
        $this->person('张三', $g);
        $this->status(409, $this->request('DELETE', '/api/v1/depts/' . $g), 'cannot delete with people');
        $this->status(200, $this->request('DELETE', '/api/v1/depts/' . $b));
        // move a group under another top department: paths of the subtree follow
        $sub = $this->data($this->request('POST', '/api/v1/depts', array('name' => '甲一组子组', 'parent_id' => $g)))['id'];
        $c = $this->data($this->request('POST', '/api/v1/depts', array('name' => '丙部门')))['id'];
        $this->status(200, $this->request('PUT', '/api/v1/depts/' . $g, array('name' => '甲一组', 'parent_id' => $c)));
        $this->same('/' . $c . '/' . $g . '/', (string) DB::value('SELECT path FROM departments WHERE id = ?', array($sub)));
    }

    public function testDeptHeadAddsAndRemovesOwnPeople()
    {
        $tech = $this->dept('技术部');
        $soft = $this->dept('软件组', $tech);
        $hr = $this->dept('综合部');
        $leadP = $this->person('李建国', $tech);
        $this->login($this->user('dept_head', $leadP)['username']);

        $r = $this->request('POST', '/api/v1/staff', array('name' => '新人甲', 'gender' => 'female', 'dept_id' => $hr));
        $this->status(422, $r, 'cannot add into another department');
        $r = $this->request('POST', '/api/v1/staff', array('name' => '新人甲', 'gender' => 'female'));
        $this->status(422, $r, 'department is required for department scope');
        $id = $this->data($this->request('POST', '/api/v1/staff', array('name' => '新人甲', 'gender' => 'female', 'dept_id' => $soft)))['id'];
        $this->same('join', (string) DB::value('SELECT change_type FROM personnel_assignments WHERE person_id = ?', array($id)));

        // leave: status changes and the account is disabled
        $acc = $this->user('member', $id);
        $this->status(200, $this->request('POST', '/api/v1/staff/' . $id . '/remove', array('type' => 'leave', 'date' => date('Y-m-d'))));
        $this->same('left', (string) DB::value('SELECT status FROM personnel_profiles WHERE id = ?', array($id)));
        $this->same('disabled', (string) DB::value('SELECT status FROM users WHERE id = ?', array($acc['id'])));

        // move out: unassigned, no longer visible to the department head, visible to the unit head
        $p2 = $this->person('王晓', $soft);
        $this->status(200, $this->request('POST', '/api/v1/staff/' . $p2 . '/remove', array('type' => 'move_out', 'date' => date('Y-m-d'), 'note' => '支援')));
        $this->status(404, $this->request('GET', '/api/v1/staff/' . $p2));
        $this->status(400, $this->request('POST', '/api/v1/staff/' . $leadP . '/remove', array('type' => 'leave', 'date' => date('Y-m-d'))), 'cannot remove self');
        $this->status(403, $this->request('DELETE', '/api/v1/staff/' . $id), 'department scope cannot delete records');
        $this->status(403, $this->request('POST', '/api/v1/staff/' . $id . '/transfer', array('type' => 'transfer', 'date' => date('Y-m-d'))), 'department scope cannot transfer');

        $this->login($this->user('unit_head')['username']);
        $row = $this->data($this->request('GET', '/api/v1/staff/' . $p2));
        $this->ok($row['unassigned'] && $row['dept_id'] === null, 'unassigned');
        $this->status(200, $this->request('POST', '/api/v1/staff/' . $p2 . '/transfer', array('type' => 'transfer', 'dept_id' => $hr, 'date' => date('Y-m-d'))));
        $row = $this->data($this->request('GET', '/api/v1/staff/' . $p2));
        $this->ok(!$row['unassigned'] && $row['dept_id'] === $hr, 'assigned again');
        $types = DB::table('personnel_assignments')->where('person_id', $p2)->orderBy('id')->pluck('change_type');
        $this->same(array('join', 'move_out', 'transfer'), $types);
    }

    public function testPermissionsControlStaffButtons()
    {
        $tech = $this->dept('技术部');
        $leadP = $this->person('李建国', $tech);
        $lead = $this->user('dept_head', $leadP);
        $su = $this->user('super');
        $this->login($lead['username']);
        $caps = $this->data($this->request('GET', '/api/v1/staff/meta'))['caps'];
        $this->ok($caps['add'] && $caps['remove'] && !$caps['delete'] && !$caps['transfer'], 'default dept head caps');

        // super removes "add" and "del" from the department head role
        $this->login($su['username']);
        $roleId = (int) DB::value("SELECT id FROM roles WHERE code = 'dept_head'");
        $original = DB::select('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', array($roleId));
        $original = array_map(function ($r) { return (string) $r['code']; }, $original);
        $reduced = array_values(array_diff($original, array('staff.add', 'staff.del')));
        $this->status(200, $this->request('PUT', '/api/v1/roles/' . $roleId . '/permissions', array('codes' => $reduced)));

        $this->login($lead['username']);
        $caps = $this->data($this->request('GET', '/api/v1/staff/meta'))['caps'];
        $this->ok(!$caps['add'] && !$caps['remove'] && $caps['edit'], 'buttons follow the permission matrix');
        $this->status(403, $this->request('POST', '/api/v1/staff', array('name' => 'x', 'gender' => 'male', 'dept_id' => $tech)), 'API refuses too');

        $this->login($su['username']);
        $this->request('PUT', '/api/v1/roles/' . $roleId . '/permissions', array('codes' => $original));
    }

    public function testDeleteDisablesAccountAndClearsHead()
    {
        $d = $this->dept('综合部');
        $p = $this->person('周敏', $d);
        DB::table('departments')->where('id', $d)->update(array('leader_person_id' => $p));
        $acc = $this->user('dept_head', $p);
        $this->login($acc['username']);
        $victim = $this->cookies;

        $this->login($this->user('unit_head')['username']);
        $im = $this->data($this->request('GET', '/api/v1/staff/' . $p . '/delete-impact'));
        $this->same(array($acc['username']), $im['accounts'], 'confirmation lists the account');
        $this->same(array('综合部'), $im['leads'], 'confirmation lists the department');
        $this->status(200, $this->request('DELETE', '/api/v1/staff/' . $p));
        $this->same('disabled', (string) DB::value('SELECT status FROM users WHERE id = ?', array($acc['id'])));
        $this->same(null, DB::value('SELECT leader_person_id FROM departments WHERE id = ?', array($d)));
        $this->cookies = $victim;
        $this->status(401, $this->request('GET', '/api/v1/staff'), 'the account is signed out');
    }

    public function testDeleteRefusedWithoutUserManagement()
    {
        $d = $this->dept('业务部');
        $p = $this->person('孙丽', $d);
        $this->user('member', $p);
        $su = $this->user('super');
        $this->login($su['username']);
        $member = (int) DB::value("SELECT id FROM roles WHERE code = 'member'");
        $rid = $this->data($this->request('POST', '/api/v1/roles', array('names' => array('zh-CN' => '档案员' . mt_rand(100, 999), 'en-US' => 'Records ' . mt_rand(100, 999)), 'scope' => 'unit', 'template_id' => $member)))['id'];
        $this->request('PUT', '/api/v1/roles/' . $rid . '/permissions', array('codes' => array('staff.view', 'staff.del')));
        $clerk = $this->user('member');
        DB::table('users')->where('id', $clerk['id'])->update(array('role_id' => $rid));
        $this->login($clerk['username']);
        $r = $this->request('DELETE', '/api/v1/staff/' . $p);
        $this->status(409, $r, 'cannot disable accounts without user management');
        $this->contains('孙丽', $r->data['error']['message']);
    }
}
