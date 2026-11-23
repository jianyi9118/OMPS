<?php
namespace Omps\Core;

/**
 * Router. Patterns like /api/v1/users/{id}. Options per route:
 *   'auth' => true (default) | false      login required
 *   'perm' => 'users.view'                permission code (checked by the Permission middleware)
 *   'csrf' => true (default for POST/PUT/PATCH/DELETE)
 *   'allow_must_change' => false          reachable while the user must change the password
 */
class Router
{
    /** @var array */
    private $routes = array();
    /** @var array */
    private $groupOptions = array();

    public function get($pattern, $handler, array $opt = array())
    {
        return $this->add('GET', $pattern, $handler, $opt);
    }

    public function post($pattern, $handler, array $opt = array())
    {
        return $this->add('POST', $pattern, $handler, $opt);
    }

    public function put($pattern, $handler, array $opt = array())
    {
        return $this->add('PUT', $pattern, $handler, $opt);
    }

    public function delete($pattern, $handler, array $opt = array())
    {
        return $this->add('DELETE', $pattern, $handler, $opt);
    }

    /**
     * Routes registered inside $fn share $opt (e.g. a permission or module).
     * @param callable $fn
     */
    public function group(array $opt, $fn)
    {
        $saved = $this->groupOptions;
        $this->groupOptions = array_merge($this->groupOptions, $opt);
        call_user_func($fn, $this);
        $this->groupOptions = $saved;
    }

    public function add($method, $pattern, $handler, array $opt = array())
    {
        $opt = array_merge(array('auth' => true, 'csrf' => !in_array($method, array('GET', 'HEAD'), true), 'allow_must_change' => false), $this->groupOptions, $opt);
        $pattern = '/' . trim($pattern, '/');
        $names = array();
        $regex = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use (&$names) {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = array('method' => $method, 'pattern' => $pattern, 'regex' => '#^' . $regex . '$#u', 'names' => $names, 'handler' => $handler, 'opt' => $opt);
        return $this;
    }

    /**
     * @return array|null [route, params]; ['__method_not_allowed' => true] when the path exists with another method
     */
    public function match($method, $path)
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $pathExists = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            $pathExists = true;
            if ($r['method'] !== $method) {
                continue;
            }
            $params = array();
            foreach ($r['names'] as $i => $n) {
                $params[$n] = $m[$i + 1];
            }
            return array($r, $params);
        }
        return $pathExists ? array('__method_not_allowed' => true) : null;
    }

    public function all()
    {
        return $this->routes;
    }
}
