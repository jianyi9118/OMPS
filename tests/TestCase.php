<?php
namespace Omps\Tests;

use Omps\Core\App;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Database\DB;

/**
 * Base class for tests: assertions, an in-process HTTP client with a cookie jar
 * (requests go through the full middleware pipeline), and fixture helpers.
 */
abstract class TestCase
{
    /** @var int */
    public $assertions = 0;
    /** @var array cookie jar */
    protected $cookies = array();
    /** @var string */
    protected $ip = '10.0.0.1';
    /** @var int */
    private static $seq = 0;

    public function setUp()
    {
        DB::statement('DELETE FROM login_attempts WHERE id > 0');
        $this->cookies = array();
    }

    // ---------- assertions ----------
    protected function ok($cond, $msg = 'assertion failed')
    {
        $this->assertions++;
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    protected function same($expected, $actual, $msg = '')
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new \RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function status($code, Response $res, $msg = '')
    {
        $this->assertions++;
        if ($res->status !== $code) {
            throw new \RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'expected HTTP ' . $code . ', got ' . $res->status . ' ' . mb_substr($res->body, 0, 300));
        }
    }

    protected function contains($needle, $haystack, $msg = '')
    {
        $this->assertions++;
        if (strpos((string) $haystack, (string) $needle) === false) {
            throw new \RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'missing "' . $needle . '"');
        }
    }

    protected function notContains($needle, $haystack, $msg = '')
    {
        $this->assertions++;
        if (strpos((string) $haystack, (string) $needle) !== false) {
            throw new \RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'unexpected "' . $needle . '"');
        }
    }

    // ---------- HTTP ----------
    /** Upload: $files = field => list of local file paths (copied to temp, like PHP uploads). */
    protected function upload($path, array $paths, array $fields = array())
    {
        $list = array();
        foreach ($paths as $p) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            copy($p['path'], $tmp);
            $list[] = array('name' => $p['name'], 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => 0);
        }
        return $this->request('POST', $path, $fields, array(), array(), array('file' => $list));
    }

    protected function request($method, $path, $body = null, array $query = array(), array $headers = array(), array $files = array())
    {
        $sid = isset($this->cookies['omps_sid']) ? $this->cookies['omps_sid'] : '';
        if (!isset($headers['X-CSRF-Token'])) {
            $tok = '';
            if ($sid !== '') {
                $tok = (string) DB::value('SELECT csrf_token FROM user_sessions WHERE id = ?', array(hash('sha256', $sid)));
            } elseif (isset($this->cookies['omps_csrf'])) {
                $tok = $this->cookies['omps_csrf'];
            }
            $headers['X-CSRF-Token'] = $tok;
        }
        $headers['Accept'] = 'application/json';
        $req = new Request($method, $path, $query, $body === null ? array() : $body, $headers, $this->cookies,
            array('REMOTE_ADDR' => $this->ip, 'HTTP_USER_AGENT' => 'omps-test'));
        $req->files = $files;
        $res = App::instance()->handle($req);
        foreach ($res->cookies as $c) {
            $parts = explode(';', $c);
            list($name, $value) = explode('=', $parts[0], 2);
            $name = rawurldecode(trim($name));
            if (stripos($c, 'Max-Age=-') !== false) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = rawurldecode($value);
            }
        }
        return $res;
    }

    protected function login($username, $password = 'Passw0rd!x')
    {
        $this->cookies = array();
        $this->request('GET', '/login');
        $res = $this->request('POST', '/api/v1/auth/login', array('username' => $username, 'password' => $password));
        $this->status(200, $res, 'login ' . $username);
        return $res;
    }

    protected function data(Response $res)
    {
        return $res->data !== null && isset($res->data['data']) ? $res->data['data'] : null;
    }

    // ---------- fixtures ----------
    protected static function uniq($p)
    {
        self::$seq++;
        return $p . self::$seq . substr(md5(uniqid('', true)), 0, 5);
    }

    protected function dept($name, $parentId = null)
    {
        $path = '/';
        if ($parentId) {
            $p = DB::table('departments')->find($parentId);
            $path = $p['path'] . (int) $p['id'] . '/';
        }
        return DB::table('departments')->insert(array('parent_id' => $parentId, 'path' => $path, 'code' => self::uniq('T'), 'name' => $name, 'sort' => 10, 'status' => 'active', 'created_at' => now()));
    }

    protected function person($name, $deptId)
    {
        $id = DB::table('personnel_profiles')->insert(array('staff_no' => self::uniq('S'), 'name' => $name, 'gender' => 'male', 'dept_id' => $deptId, 'status' => 'active', 'created_at' => now()));
        DB::table('personnel_assignments')->insert(array('person_id' => $id, 'dept_id' => $deptId, 'change_type' => 'join', 'start_date' => date('Y-m-d'), 'created_at' => now()));
        return $id;
    }

    protected function user($roleCode, $personId = null, array $extra = array())
    {
        $username = self::uniq('u');
        $roleId = (int) DB::value('SELECT id FROM roles WHERE code = ?', array($roleCode));
        $name = $personId ? (string) DB::value('SELECT name FROM personnel_profiles WHERE id = ?', array($personId)) : $username;
        $id = DB::table('users')->insert(array_merge(array('username' => $username, 'display_name' => $name, 'person_id' => $personId,
            'password_hash' => password_hash('Passw0rd!x', PASSWORD_DEFAULT), 'role_id' => $roleId, 'status' => 'active', 'created_at' => now()), $extra));
        return array('id' => $id, 'username' => $username);
    }

    protected function auditCount($action)
    {
        return (int) DB::value('SELECT COUNT(*) FROM audit_logs WHERE action = ?', array($action));
    }
}
