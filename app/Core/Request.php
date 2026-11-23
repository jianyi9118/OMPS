<?php
namespace Omps\Core;

/**
 * HTTP request. Built from PHP globals in production, or directly in tests.
 */
class Request
{
    /** @var string */
    public $method;
    /** @var string path without base, always starting with / */
    public $path;
    /** @var array */
    public $query;
    /** @var array */
    public $body;
    /** @var array */
    public $headers;
    /** @var array */
    public $cookies;
    /** @var array */
    public $server;
    /** @var array route parameters */
    public $params = array();
    /** @var array|null authenticated user row (with role info) */
    public $user = null;
    /** @var array|null current session row */
    public $session = null;
    /** @var array route options */
    public $route = array();
    /** @var array uploaded files: field => list of [name, tmp_name, size, error] */
    public $files = array();
    /** @var bool the body claimed to be JSON but was not valid JSON (or not valid UTF-8) */
    public $badJson = false;

    public function __construct($method, $path, array $query = array(), array $body = array(), array $headers = array(), array $cookies = array(), array $server = array())
    {
        $this->method = strtoupper((string) $method);
        $this->path = '/' . trim((string) $path, '/');
        $this->query = $query;
        $this->body = $body;
        $this->headers = array();
        foreach ($headers as $k => $v) {
            $this->headers[strtolower((string) $k)] = (string) $v;
        }
        $this->cookies = $cookies;
        $this->server = $server;
    }

    public static function fromGlobals()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        $query = $_GET;
        $path = self::detectPath($query);
        $headers = array();
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) === 0) {
                $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $body = $_POST;
        $badJson = false;
        $ct = isset($headers['content-type']) ? $headers['content-type'] : '';
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $json = $raw === false || $raw === '' ? array() : json_decode($raw, true);
            $badJson = !is_array($json);
            $body = is_array($json) ? $json : array();
        }
        $req = new self($method, $path, $query, $body, $headers, $_COOKIE, $_SERVER);
        $req->badJson = $badJson;
        $req->files = self::normalizeFiles($_FILES);
        return $req;
    }

    /** Path relative to the application base, from REQUEST_URI or the ?r= fallback. */
    private static function detectPath(array &$query)
    {
        if (isset($query['r']) && is_string($query['r'])) {
            $p = $query['r'];
            unset($query['r']);
            return $p;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $qpos = strpos($uri, '?');
        if ($qpos !== false) {
            $uri = substr($uri, 0, $qpos);
        }
        $uri = rawurldecode($uri);
        $base = Url::basePath();
        if ($base !== '' && strncmp($uri, $base, strlen($base)) === 0) {
            $uri = substr($uri, strlen($base));
        }
        if (strncmp($uri, '/index.php', 10) === 0) {
            $uri = substr($uri, 10);
        }
        return $uri === '' || $uri === false ? '/' : $uri;
    }

    /** $_FILES in one shape: field => list of files (works for name[] and single fields). */
    public static function normalizeFiles(array $files)
    {
        $out = array();
        foreach ($files as $field => $f) {
            if (!is_array($f) || !isset($f['name'])) {
                continue;
            }
            if (is_array($f['name'])) {
                foreach ($f['name'] as $i => $n) {
                    $out[$field][] = array('name' => (string) $n, 'tmp_name' => (string) $f['tmp_name'][$i], 'size' => (int) $f['size'][$i], 'error' => (int) $f['error'][$i]);
                }
            } else {
                $out[$field][] = array('name' => (string) $f['name'], 'tmp_name' => (string) $f['tmp_name'], 'size' => (int) $f['size'], 'error' => (int) $f['error']);
            }
        }
        return $out;
    }

    public function input($key, $default = null)
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    /** Trimmed string input ('' when missing or not a scalar). */
    public function str($key, $default = '')
    {
        $v = $this->input($key, null);
        if ($v === null || is_array($v)) {
            return $default;
        }
        return trim((string) $v);
    }

    public function int($key, $default = 0)
    {
        $v = $this->input($key, null);
        if ($v === null || $v === '' || is_array($v) || !is_numeric($v)) {
            return $default;
        }
        return (int) $v;
    }

    public function all()
    {
        return array_merge($this->query, $this->body);
    }

    public function header($name, $default = '')
    {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name] : $default;
    }

    public function cookie($name, $default = '')
    {
        return isset($this->cookies[$name]) && is_string($this->cookies[$name]) ? $this->cookies[$name] : $default;
    }

    public function param($name, $default = null)
    {
        return isset($this->params[$name]) ? $this->params[$name] : $default;
    }

    public function isApi()
    {
        return strncmp($this->path, '/api/', 5) === 0;
    }

    public function isSecure()
    {
        if (isset($this->server['HTTPS']) && $this->server['HTTPS'] !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        return isset($this->server['SERVER_PORT']) && (int) $this->server['SERVER_PORT'] === 443;
    }

    public function ip()
    {
        return isset($this->server['REMOTE_ADDR']) ? substr((string) $this->server['REMOTE_ADDR'], 0, 45) : '';
    }

    public function userAgent()
    {
        return isset($this->server['HTTP_USER_AGENT']) ? mb_substr((string) $this->server['HTTP_USER_AGENT'], 0, 255) : $this->header('user-agent');
    }

    public function userId()
    {
        return $this->user ? (int) $this->user['id'] : 0;
    }
}
