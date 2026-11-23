<?php
namespace Omps\Core;

use Omps\Middleware\Authenticate;
use Omps\Middleware\Csrf;
use Omps\Middleware\Locale;
use Omps\Middleware\PermissionCheck;
use Omps\Middleware\SecurityHeaders;
use Omps\Middleware\Session;

/**
 * Application kernel: loads module routes, runs the middleware pipeline
 * (security headers → session → language → login → CSRF → permission → controller)
 * and turns exceptions into responses.
 */
class App
{
    /** @var App|null */
    private static $instance = null;
    /** @var Router */
    public $router;
    /** @var array */
    public $modules = array();

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset()
    {
        self::$instance = null;
    }

    public function __construct()
    {
        $this->router = new Router();
        $this->modules = Modules::all();
        foreach ($this->modules as $m) {
            $file = $m['path'] . '/routes.php';
            if (is_file($file)) {
                $router = $this->router;
                require $file;
            }
        }
    }

    public function handleHttp()
    {
        $req = Request::fromGlobals();
        $res = $this->handle($req);
        $res->send();
    }

    public function handle(Request $req)
    {
        $pipeline = array(new SecurityHeaders(), new Session(), new Locale(), new Authenticate(), new Csrf(), new PermissionCheck());
        \Omps\Engines\Notify::clearCache();
        \Omps\Engines\CustomFields::clearCache();
        \Omps\Core\Lang::clearCache();
        \Omps\Engines\Documents::clearCache();
        try {
            if ($req->badJson) {
                throw HttpException::bad('error.bad_json');
            }
            $match = $this->router->match($req->method, $req->path);
            if ($match === null) {
                $route = null;
            } elseif (isset($match['__method_not_allowed'])) {
                throw new HttpException(405, 'method_not_allowed');
            } else {
                $route = $match[0];
                $req->params = $match[1];
                $req->route = $route['opt'];
            }
            $self = $this;
            $core = function (Request $r) use ($route, $self) {
                if ($route === null) {
                    throw HttpException::notFound();
                }
                return $self->callHandler($route['handler'], $r);
            };
            $next = $core;
            for ($i = count($pipeline) - 1; $i >= 0; $i--) {
                $mw = $pipeline[$i];
                $inner = $next;
                $next = function (Request $r) use ($mw, $inner, $route) {
                    return $mw->handle($r, $inner, $route);
                };
            }
            return $next($req);
        } catch (\Exception $e) {
            return (new SecurityHeaders())->apply($this->renderException($req, $e));
        } catch (\Throwable $e) {
            return (new SecurityHeaders())->apply($this->renderException($req, $e));
        }
    }

    public function callHandler($handler, Request $req)
    {
        if (is_string($handler) && strpos($handler, '@') !== false) {
            list($class, $method) = explode('@', $handler, 2);
            $obj = new $class();
            $out = $obj->$method($req);
        } else {
            $out = call_user_func($handler, $req);
        }
        if ($out instanceof Response) {
            return $out;
        }
        if (is_string($out)) {
            return Response::html($out);
        }
        return Response::json($out);
    }

    /** @param \Exception|\Throwable $e */
    public function renderException(Request $req, $e)
    {
        if ($e instanceof ValidationException) {
            return Response::error('validation', __('error.validation'), 422, $e->errors);
        }
        if ($e instanceof HttpException) {
            $msg = Lang::has($e->getMessage()) ? __($e->getMessage(), $e->params) : __('error.' . $e->errorCode);
            if ($req->isApi()) {
                return Response::error($e->errorCode, $msg, $e->status);
            }
            if ($e->status === 401) {
                return Response::redirect(url('/login'));
            }
            return Response::html(View::render('errors/http', array('status' => $e->status, 'message' => $msg)), $e->status);
        }
        Logger::error(get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), array('trace' => explode("\n", $e->getTraceAsString())));
        $detail = (bool) config('app.debug', false) ? ' [' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']' : '';
        $msg = __('error.server') . $detail;
        if ($req->isApi()) {
            return Response::error('server_error', $msg, 500);
        }
        try {
            return Response::html(View::render('errors/http', array('status' => 500, 'message' => $msg)), 500);
        } catch (\Exception $e2) {
            return Response::html(e($msg), 500);
        }
    }
}
