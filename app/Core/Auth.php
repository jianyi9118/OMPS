<?php
namespace Omps\Core;

use Omps\Database\DB;
use Omps\Engines\Audit;

/**
 * Login, sessions stored in the database, lockout, password changes, forced logout.
 *
 * Session cookie holds a random 256-bit token; the database keeps only its SHA-256.
 * A session is valid while: not revoked, not expired (absolute), not idle too long,
 * and its session_version equals the user's (changing the password or forcing a
 * logout increments the user's version, which invalidates every other session).
 */
class Auth
{
    const TOKEN_BYTES = 32;

    /** Columns of the authenticated user row (with role and department). */
    public static function userQuery()
    {
        return 'SELECT u.id, u.org_id, u.person_id, u.username, u.display_name, u.role_id, u.is_device_admin, u.lang, u.status,
                u.session_version, u.must_change_password, u.last_login_at, u.last_login_ip, u.password_hash,
                r.code AS role_code, r.scope AS role_scope, r.role_rank, r.is_builtin AS role_builtin,
                p.dept_id, p.name AS person_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN personnel_profiles p ON p.id = u.person_id AND p.deleted_at IS NULL';
    }

    public static function findUser($id)
    {
        $row = DB::selectOne(self::userQuery() . ' WHERE u.id = ? AND u.deleted_at IS NULL', array((int) $id));
        return $row ? self::normalize($row) : null;
    }

    public static function normalize(array $u)
    {
        foreach (array('id', 'org_id', 'role_id', 'session_version', 'role_rank') as $k) {
            $u[$k] = (int) $u[$k];
        }
        $u['person_id'] = int_or_null($u['person_id']);
        $u['dept_id'] = int_or_null($u['dept_id']);
        $u['is_device_admin'] = (int) $u['is_device_admin'] === 1;
        $u['must_change_password'] = (int) $u['must_change_password'] === 1;
        $u['role_builtin'] = (int) $u['role_builtin'] === 1;
        $u['name'] = $u['person_name'] !== null && $u['person_name'] !== '' ? (string) $u['person_name'] : (string) $u['display_name'];
        unset($u['password_hash']);
        return $u;
    }

    /**
     * Attempt a login. Returns ['user' => row, 'cookie' => header] or throws HttpException with a reason.
     */
    public static function attempt($username, $password, Request $req)
    {
        $username = trim((string) $username);
        $ip = $req->ip();
        $ipMax = (int) config('security.ip_max_failures', 20);
        $win = (int) config('security.ip_window_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - $win * 60);
        $ipFails = (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?', array($ip, $since));
        if ($ipFails >= $ipMax) {
            self::logLogin(null, $username, $req, 'ip_blocked', 'ip_limit');
            throw new HttpException(429, 'ip_blocked', 'auth.ip_blocked', array('minutes' => $win));
        }

        $row = DB::selectOne('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL', array($username));
        if (!$row) {
            password_verify((string) $password, '$2y$10$usesomesillystringforsaltzzzzzzzzzzzzzzzzzzzzzzzzzzzz'); // equalize timing
            self::recordFailure($ip, $username);
            self::logLogin(null, $username, $req, 'fail', 'no_user');
            throw new HttpException(401, 'bad_credentials', 'auth.bad_credentials');
        }
        $uid = (int) $row['id'];
        if ((string) $row['status'] !== 'active') {
            self::logLogin($uid, $username, $req, 'disabled', 'disabled');
            throw new HttpException(403, 'account_disabled', 'auth.account_disabled');
        }
        if ($row['locked_until'] !== null && strtotime((string) $row['locked_until']) > time()) {
            $mins = (int) ceil((strtotime((string) $row['locked_until']) - time()) / 60);
            self::logLogin($uid, $username, $req, 'locked', 'locked');
            throw new HttpException(423, 'account_locked', 'auth.account_locked', array('minutes' => max(1, $mins)));
        }
        if (!password_verify((string) $password, (string) $row['password_hash'])) {
            self::recordFailure($ip, $username);
            $max = max(1, Settings::int('login_max_failures'));
            $fails = (int) $row['failed_count'] + 1;
            $upd = array('failed_count' => $fails);
            if ($fails >= $max) {
                $upd['locked_until'] = date('Y-m-d H:i:s', time() + max(1, Settings::int('login_lock_minutes')) * 60);
                $upd['failed_count'] = 0;
                DB::table('users')->where('id', $uid)->update($upd);
                self::logLogin($uid, $username, $req, 'locked', 'too_many_failures');
                Audit::log(array('id' => $uid, 'username' => $username), 'auth', 'account_locked', 'user', $uid, $username, null, array('failures' => $fails), $ip);
                throw new HttpException(423, 'account_locked', 'auth.account_locked', array('minutes' => Settings::int('login_lock_minutes')));
            }
            DB::table('users')->where('id', $uid)->update($upd);
            self::logLogin($uid, $username, $req, 'fail', 'bad_password');
            throw new HttpException(401, 'bad_credentials', 'auth.bad_credentials_left', array('left' => $max - $fails));
        }

        $upd = array('failed_count' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip);
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $upd['password_hash'] = password_hash((string) $password, PASSWORD_DEFAULT);
        }
        DB::table('users')->where('id', $uid)->update($upd);
        $cookie = self::startSession($uid, (int) $row['session_version'], $req);
        self::logLogin($uid, $username, $req, 'success', null);
        return array('user' => self::findUser($uid), 'cookie' => $cookie);
    }

    private static function recordFailure($ip, $username)
    {
        DB::table('login_attempts')->insert(array('ip' => $ip, 'username' => mb_substr($username, 0, 50), 'created_at' => now()));
    }

    public static function logLogin($userId, $username, Request $req, $result, $reason)
    {
        DB::table('login_logs')->insert(array(
            'org_id' => (int) config('app.org_id', 1), 'user_id' => $userId, 'username' => mb_substr((string) $username, 0, 50),
            'ip' => $req->ip(), 'user_agent' => $req->userAgent(), 'result' => $result, 'reason' => $reason, 'created_at' => now(),
        ));
    }

    /** Create a session row and return the Set-Cookie header. */
    public static function startSession($userId, $sessionVersion, Request $req)
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hours = max(1, Settings::int('session_absolute_hours'));
        DB::table('user_sessions')->insert(array(
            'id' => hash('sha256', $token), 'user_id' => (int) $userId, 'session_version' => (int) $sessionVersion,
            'csrf_token' => bin2hex(random_bytes(32)), 'ip' => $req->ip(), 'user_agent' => $req->userAgent(),
            'created_at' => now(), 'last_active' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600),
        ));
        return Cookie::make((string) config('security.session_cookie'), $token, $hours * 3600, $req);
    }

    /** Load the session from the cookie into $req->user / $req->session. */
    public static function load(Request $req)
    {
        $token = $req->cookie((string) config('security.session_cookie'));
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $s = DB::selectOne('SELECT * FROM user_sessions WHERE id = ?', array(hash('sha256', $token)));
        if (!$s || $s['revoked_at'] !== null || strtotime((string) $s['expires_at']) <= time()) {
            return false;
        }
        $idle = max(5, Settings::int('session_idle_minutes'));
        if (strtotime((string) $s['last_active']) < time() - $idle * 60) {
            DB::table('user_sessions')->where('id', $s['id'])->update(array('revoked_at' => now(), 'revoke_reason' => 'idle'));
            return false;
        }
        $user = self::findUser((int) $s['user_id']);
        if (!$user || $user['status'] !== 'active' || $user['session_version'] !== (int) $s['session_version']) {
            return false;
        }
        if (strtotime((string) $s['last_active']) < time() - 60) {
            DB::table('user_sessions')->where('id', $s['id'])->update(array('last_active' => now()));
        }
        $req->user = $user;
        $req->session = $s;
        return true;
    }

    public static function logout(Request $req)
    {
        if ($req->session) {
            DB::table('user_sessions')->where('id', $req->session['id'])->update(array('revoked_at' => now(), 'revoke_reason' => 'logout'));
            self::logLogin($req->userId(), $req->user['username'], $req, 'logout', null);
        }
        return Cookie::forget((string) config('security.session_cookie'), $req);
    }

    /** Password policy: minimum length, letters and digits, not the username. Returns an error message or null. */
    public static function passwordProblem($password, $username = '')
    {
        $password = (string) $password;
        $min = max(6, Settings::int('password_min_length'));
        if (mb_strlen($password) < $min) {
            return __('auth.password_too_short', array('min' => $min));
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return __('auth.password_letters_digits');
        }
        if ($username !== '' && strcasecmp($password, (string) $username) === 0) {
            return __('auth.password_is_username');
        }
        return null;
    }

    /** Change own password; other sessions end, the current one stays. */
    public static function changePassword(Request $req, $old, $new)
    {
        $u = $req->user;
        $row = DB::selectOne('SELECT password_hash, username FROM users WHERE id = ?', array($u['id']));
        if (!$row || !password_verify((string) $old, (string) $row['password_hash'])) {
            throw ValidationException::field('old_password', 'auth.old_password_wrong');
        }
        $p = self::passwordProblem($new, (string) $row['username']);
        if ($p !== null) {
            throw new ValidationException(array('new_password' => $p));
        }
        if (password_verify((string) $new, (string) $row['password_hash'])) {
            throw ValidationException::field('new_password', 'auth.password_same');
        }
        DB::transaction(function () use ($u, $new, $req) {
            DB::table('users')->where('id', $u['id'])->increment('session_version', 1, array(
                'password_hash' => password_hash((string) $new, PASSWORD_DEFAULT), 'must_change_password' => 0, 'password_changed_at' => now(), 'updated_at' => now(),
            ));
            $ver = (int) DB::value('SELECT session_version FROM users WHERE id = ?', array($u['id']));
            DB::statement('UPDATE user_sessions SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL', array(now(), 'password_changed', $u['id'], $req->session['id']));
            DB::table('user_sessions')->where('id', $req->session['id'])->update(array('session_version' => $ver));
        });
        Audit::log($u, 'auth', 'password_changed', 'user', $u['id'], $u['username'], null, null, $req->ip());
    }

    /** End every session of a user (forced logout, reset password, disable). */
    public static function revokeAll($userId, $reason)
    {
        DB::table('users')->where('id', (int) $userId)->increment('session_version', 1);
        DB::statement('UPDATE user_sessions SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL', array(now(), $reason, (int) $userId));
    }

    /** Readable random temporary password (letters + digits, no look-alikes). */
    public static function tempPassword($len = 10)
    {
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $letters . $digits;
        $out = $letters[random_int(0, strlen($letters) - 1)] . $digits[random_int(0, strlen($digits) - 1)];
        while (strlen($out) < $len) {
            $out .= $all[random_int(0, strlen($all) - 1)];
        }
        $chars = str_split($out);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $t = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $t;
        }
        return implode('', $chars);
    }
}
