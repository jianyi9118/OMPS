<?php
namespace Omps\Middleware;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\Response;

/**
 * Routes require login unless 'auth' => false. A user who must change the password
 * can only reach routes marked 'allow_must_change'.
 */
class Authenticate
{
    public function handle(Request $req, $next, $route)
    {
        if ($route === null || !$route['opt']['auth']) {
            return $next($req);
        }
        if (!$req->user) {
            throw HttpException::unauthorized();
        }
        if ($req->user['must_change_password'] && !$route['opt']['allow_must_change']) {
            if ($req->isApi()) {
                throw new HttpException(403, 'must_change_password', 'auth.must_change_password');
            }
            return Response::redirect(url('/password'));
        }
        return $next($req);
    }
}
