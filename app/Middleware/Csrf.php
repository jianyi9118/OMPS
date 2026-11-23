<?php
namespace Omps\Middleware;

use Omps\Core\HttpException;
use Omps\Core\Request;

/**
 * CSRF check for state-changing requests. The token travels in the X-CSRF-Token header.
 *  - Logged in: must equal the token stored with the session.
 *  - Not logged in (login form): double-submit, must equal the omps_csrf cookie.
 */
class Csrf
{
    public function handle(Request $req, $next, $route)
    {
        if ($route === null || !$route['opt']['csrf']) {
            return $next($req);
        }
        $sent = $req->header('x-csrf-token');
        if ($sent === '') {
            $sent = $req->str('_token');
        }
        if ($req->session) {
            $expected = (string) $req->session['csrf_token'];
        } else {
            $expected = $req->cookie((string) config('security.csrf_cookie'));
        }
        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419, 'csrf', 'error.csrf');
        }
        return $next($req);
    }
}
