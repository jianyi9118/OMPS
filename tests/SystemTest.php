<?php
namespace Omps\Tests;

use Omps\Database\DB;

/**
 * Acceptance 3, 8, 9: users, custom roles, languages (no mixed-language pages), XSS.
 */
class SystemTest extends TestCase
{
    public function testCreateUsersWithTemporaryPassword()
    {
        $d = $this->dept('技术部');
        $p = $this->person('赵磊', $d);
        $this->login($this->user('unit_head')['username']);
        $memberRole = (int) DB::value("SELECT id FROM roles WHERE code = 'member'");
        $r = $this->request('POST', '/api/v1/users', array('username' => 'zhaolei', 'person_id' => $p, 'role_id' => $memberRole, 'is_device_admin' => true));
        $this->status(200, $r);
        $temp = $this->data($r)['temp_password'];
        $row = DB::selectOne('SELECT * FROM users WHERE username = ?', array('zhaolei'));
        $this->same('赵磊', (string) $row['display_name']);
        $this->same(1, (int) $row['must_change_password']);
        $this->same(1, (int) $row['is_device_admin']);
        $this->ok(password_verify($temp, (string) $row['password_hash']), 'hash stored, not the password');
        $this->status(422, $this->request('POST', '/api/v1/users', array('username' => 'zhaolei', 'display_name' => 'x', 'role_id' => $memberRole)), 'duplicate user name');
        $this->status(422, $this->request('POST', '/api/v1/users', array('username' => 'other', 'person_id' => $p, 'role_id' => $memberRole)), 'one account per person');
    }

    public function testCustomRoles()
    {
        $this->login($this->user('super')['username']);
        $member = (int) DB::value("SELECT id FROM roles WHERE code = 'member'");
        $r = $this->request('POST', '/api/v1/roles', array('names' => array('zh-CN' => '档案管理员'), 'scope' => 'dept', 'template_id' => $member));
        $this->status(422, $r, 'every enabled language needs a name');
        $this->ok(isset($r->data['error']['fields']['name_en-US']));
        $r = $this->request('POST', '/api/v1/roles', array('names' => array('zh-CN' => '部门负责人', 'en-US' => 'X'), 'scope' => 'dept', 'template_id' => $member));
        $this->status(422, $r, 'name may not clash with a built-in role');
        $r = $this->request('POST', '/api/v1/roles', array('names' => array('zh-CN' => '档案管理员', 'en-US' => 'Records officer'), 'scope' => 'dept', 'template_id' => $member, 'description' => '录入档案'));
        $this->status(200, $r);
        $id = $this->data($r)['id'];
        $roles = $this->data($this->request('GET', '/api/v1/roles'))['roles'];
        $mine = array_values(array_filter($roles, function ($x) use ($id) { return $x['id'] === $id; }))[0];
        $this->same('档案管理员', $mine['name']);
        $tpl = array_values(array_filter($roles, function ($x) { return $x['code'] === 'member'; }))[0];
        $a = $mine['perms'];
        $b = $tpl['perms'];
        sort($a);
        sort($b);
        $this->same($b, $a, 'permissions copied from the template');

        // used by a user: cannot delete
        $u = $this->user('member');
        DB::table('users')->where('id', $u['id'])->update(array('role_id' => $id));
        $r = $this->request('DELETE', '/api/v1/roles/' . $id);
        $this->status(409, $r);
        $this->contains($u['username'], $r->data['error']['message']);
        DB::table('users')->where('id', $u['id'])->update(array('role_id' => $member));
        $this->status(200, $this->request('DELETE', '/api/v1/roles/' . $id));
        $this->status(400, $this->request('DELETE', '/api/v1/roles/' . $member), 'built-in roles cannot be deleted');
        $super = (int) DB::value("SELECT id FROM roles WHERE code = 'super'");
        $this->status(400, $this->request('PUT', '/api/v1/roles/' . $super . '/permissions', array('codes' => array())), 'super is locked');
    }

    public function testEnglishPagesHaveNoChinese()
    {
        $d = $this->dept('技术部');
        $p = $this->person('李建国', $d);
        $lead = $this->user('dept_head', $p);
        $super = $this->user('super');
        foreach (array($super, $lead) as $u) {
            $this->login($u['username']);
            $this->status(200, $this->request('POST', '/api/v1/lang', array('lang' => 'en-US')));
            foreach (array('/', '/org/staff', '/org/depts', '/password', '/system/users', '/system/roles', '/system/settings', '/system/logs') as $path) {
                $res = $this->request('GET', $path);
                if ($res->status === 403) {
                    continue;
                }
                $this->status(200, $res, $path);
                $html = $res->body;
                // user content (people's names, department names, the unit name) is allowed; take it out first
                $html = str_replace(array('李建国', '技术部', '<span class="av">李</span>'), '', $html);
                $html = preg_replace('/<script type="application\/json" id="omps-boot">.*?<\/script>/s', '', $html);
                $found = preg_match('/.{0,30}[\x{3400}-\x{9FFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]+.{0,10}/u', $html, $m);
                $this->ok(!$found, 'no Chinese on ' . $path . ' for ' . $u['username'] . ($found ? ': ' . $m[0] : ''));
            }
            // API messages too
            $r = $this->request('POST', '/api/v1/me/password', array('old_password' => 'bad', 'new_password' => 'x', 'confirm_password' => 'y'));
            $this->ok(!preg_match('/[\x{3400}-\x{9FFF}]/u', $r->body), 'API error in English');
        }
        // back to the system default
        $this->status(200, $this->request('POST', '/api/v1/lang', array('lang' => 'auto')));
        $this->contains('工作台', $this->request('GET', '/')->body);
    }

    public function testLoginPageLanguageIsSavedAtLogin()
    {
        $u = $this->user('member');
        $this->cookies = array();
        $this->request('GET', '/login');
        $this->request('POST', '/api/v1/lang', array('lang' => 'en-US'));
        $this->contains('Sign in', $this->request('GET', '/login')->body);
        $this->request('POST', '/api/v1/auth/login', array('username' => $u['username'], 'password' => 'Passw0rd!x'));
        $this->same('en-US', (string) DB::value('SELECT lang FROM users WHERE id = ?', array($u['id'])));
    }

    public function testDefaultLanguageSetBySuperAdmin()
    {
        $su = $this->user('super');
        $m = $this->user('member');
        $this->login($su['username']);
        $s = $this->data($this->request('GET', '/api/v1/settings'));
        $s['default_language'] = 'en-US';
        $s['unit_name'] = '示例市数据中心';
        unset($s['languages'], $s['limits']);
        $this->status(200, $this->request('PUT', '/api/v1/settings', $s));
        $this->login($m['username']);
        $this->contains('My affairs', $this->request('GET', '/me/profile')->body, 'users without a choice follow the default');
        $this->login($su['username']);
        $s['default_language'] = 'zh-CN';
        $this->status(200, $this->request('PUT', '/api/v1/settings', $s));
        $s['default_language'] = 'xx-XX';
        $this->status(400, $this->request('PUT', '/api/v1/settings', $s), 'only enabled languages');
    }

    public function testXssIsEscaped()
    {
        $evil = '<script>alert(1)</script>';
        $d = $this->dept($evil);
        $p = $this->person($evil, $d);
        $u = $this->user('dept_head', $p);
        $this->login($u['username']);
        $html = $this->request('GET', '/')->body;
        $this->notContains($evil, $html, 'raw script never printed');
        $this->contains('&lt;script&gt;', $html);
        $api = $this->request('GET', '/api/v1/staff');
        $this->contains('application/json', $api->headers['Content-Type'], 'API data is JSON, rendered as text by the page script');
        $res = $this->request('GET', '/');
        $this->contains("script-src 'self'", $res->headers['Content-Security-Policy']);
        $this->same('nosniff', $res->headers['X-Content-Type-Options']);
    }

    public function testLogsAreReadableAndTranslated()
    {
        $su = $this->user('super');
        $this->login($su['username']);
        $this->request('POST', '/api/v1/lang', array('lang' => 'en-US'));
        $this->request('POST', '/api/v1/depts', array('name' => 'Log test dept'));
        $res = $this->data($this->request('GET', '/api/v1/logs/operation', null, array('module' => 'org')));
        $this->ok($res['total'] >= 1);
        $this->same('Department created', $res['rows'][0]['action']);
        $this->same('Departments', $res['rows'][0]['module']);
        $login = $this->data($this->request('GET', '/api/v1/logs/login'));
        $this->ok($login['total'] >= 1);
        $this->status(404, $this->request('GET', '/api/v1/logs/secret'));
    }
}
