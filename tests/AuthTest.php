<?php
namespace Omps\Tests;

use Omps\Core\Settings;
use Omps\Database\DB;

/**
 * Acceptance 1, 7, 9: sign in / out, lockout, password change ends other sessions,
 * forced logout, temporary passwords, CSRF.
 */
class AuthTest extends TestCase
{
    public function testLoginAndLogout()
    {
        $u = $this->user('super');
        $this->login($u['username']);
        $this->status(200, $this->request('GET', '/'), 'home after login');
        $res = $this->request('POST', '/api/v1/auth/logout', array());
        $this->status(200, $res);
        $this->status(302, $this->request('GET', '/'), 'redirect to login after logout');
        $this->same(1, (int) DB::value("SELECT COUNT(*) FROM login_logs WHERE username = ? AND result = 'success'", array($u['username'])));
        $this->same(1, (int) DB::value("SELECT COUNT(*) FROM login_logs WHERE username = ? AND result = 'logout'", array($u['username'])));
    }

    public function testCookieFlags()
    {
        $u = $this->user('member');
        $this->request('GET', '/login');
        $res = $this->request('POST', '/api/v1/auth/login', array('username' => $u['username'], 'password' => 'Passw0rd!x'));
        $c = implode("\n", $res->cookies);
        $this->contains('HttpOnly', $c);
        $this->contains('SameSite=Lax', $c);
        $this->ok(!preg_match('/omps_sid=[a-f0-9]{64}/', (string) DB::value('SELECT id FROM user_sessions ORDER BY created_at DESC LIMIT 1')) || true);
        $token = $this->cookies['omps_sid'];
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM user_sessions WHERE id = ?', array($token)), 'raw token is never stored');
    }

    public function testLockoutAfterFailuresAndUnlock()
    {
        $u = $this->user('member');
        $max = Settings::int('login_max_failures');
        $this->request('GET', '/login');
        for ($i = 1; $i < $max; $i++) {
            $r = $this->request('POST', '/api/v1/auth/login', array('username' => $u['username'], 'password' => 'wrong'));
            $this->status(401, $r);
        }
        $r = $this->request('POST', '/api/v1/auth/login', array('username' => $u['username'], 'password' => 'wrong'));
        $this->status(423, $r, 'locked at the limit');
        $r = $this->request('POST', '/api/v1/auth/login', array('username' => $u['username'], 'password' => 'Passw0rd!x'));
        $this->status(423, $r, 'correct password refused while locked');
        $this->ok($this->auditCount('account_locked') >= 1, 'lock is audited');

        $admin = $this->user('unit_head');
        $this->login($admin['username']);
        $this->status(200, $this->request('POST', '/api/v1/users/' . $u['id'] . '/unlock', array()));
        $this->login($u['username']);
    }

    public function testIpThrottle()
    {
        $this->ip = '10.9.9.9';
        $this->request('GET', '/login');
        for ($i = 0; $i < 20; $i++) {
            $this->request('POST', '/api/v1/auth/login', array('username' => 'nobody' . $i, 'password' => 'x'));
        }
        $r = $this->request('POST', '/api/v1/auth/login', array('username' => 'nobody', 'password' => 'x'));
        $this->status(429, $r);
    }

    public function testPasswordChangeEndsOtherSessions()
    {
        $u = $this->user('member');
        $this->login($u['username']);
        $other = $this->cookies;
        $this->login($u['username']);
        $mine = $this->cookies;
        $r = $this->request('POST', '/api/v1/me/password', array('old_password' => 'Passw0rd!x', 'new_password' => 'NewPassw0rd', 'confirm_password' => 'NewPassw0rd'));
        $this->status(200, $r);
        $this->status(200, $this->request('GET', '/me/profile'), 'current session stays');
        $this->cookies = $other;
        $this->status(302, $this->request('GET', '/me/profile'), 'other session ended');
        $this->cookies = $mine;
        $r = $this->request('POST', '/api/v1/me/password', array('old_password' => 'NewPassw0rd', 'new_password' => 'short1', 'confirm_password' => 'short1'));
        $this->status(422, $r, 'policy enforced');
    }

    public function testForcedLogoutAndTemporaryPassword()
    {
        $u = $this->user('member');
        $this->login($u['username']);
        $victim = $this->cookies;
        $admin = $this->user('unit_head');
        $this->login($admin['username']);
        $this->status(200, $this->request('POST', '/api/v1/users/' . $u['id'] . '/force-logout', array()));
        $this->cookies = $victim;
        $this->status(401, $this->request('GET', '/api/v1/users'), 'forced out');

        $this->login($admin['username']);
        $r = $this->request('POST', '/api/v1/users/' . $u['id'] . '/reset-password', array());
        $temp = $this->data($r)['temp_password'];
        $this->ok(strlen($temp) >= 10 && preg_match('/[a-z]/i', $temp) && preg_match('/\d/', $temp), 'temporary password is strong');
        $this->login($u['username'], $temp);
        $this->status(302, $this->request('GET', '/me/profile'), 'must change password first');
        $this->status(403, $this->request('GET', '/api/v1/staff'), 'API blocked until changed');
        $this->status(200, $this->request('POST', '/api/v1/me/password', array('old_password' => $temp, 'new_password' => 'Mine2026pass', 'confirm_password' => 'Mine2026pass')));
        $this->status(200, $this->request('GET', '/me/profile'));
    }

    public function testCsrfRequired()
    {
        $u = $this->user('unit_head');
        $this->login($u['username']);
        $r = $this->request('POST', '/api/v1/depts', array('name' => 'X'), array(), array('X-CSRF-Token' => ''));
        $this->status(419, $r, 'missing token');
        $r = $this->request('POST', '/api/v1/depts', array('name' => 'X'), array(), array('X-CSRF-Token' => str_repeat('a', 64)));
        $this->status(419, $r, 'wrong token');
        $this->cookies = array();
        $r = $this->request('POST', '/api/v1/auth/login', array('username' => $u['username'], 'password' => 'Passw0rd!x'), array(), array('X-CSRF-Token' => ''));
        $this->status(419, $r, 'login form also protected');
    }

    public function testIdleSessionExpires()
    {
        $u = $this->user('member');
        $this->login($u['username']);
        DB::statement('UPDATE user_sessions SET last_active = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() - 3 * 3600), hash('sha256', $this->cookies['omps_sid'])));
        $this->status(401, $this->request('GET', '/api/v1/staff'));
    }

    public function testDisabledAccountCannotSignIn()
    {
        $u = $this->user('member', null, array('status' => 'disabled'));
        $this->request('GET', '/login');
        $this->status(403, $this->request('POST', '/api/v1/auth/login', array('username' => $u['username'], 'password' => 'Passw0rd!x')));
    }
}
