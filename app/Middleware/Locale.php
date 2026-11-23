<?php
namespace Omps\Middleware;

use Omps\Core\Lang;
use Omps\Core\Request;

/**
 * Picks the language: the user's own choice, else the login-page cookie, else the system default.
 */
class Locale
{
    public function handle(Request $req, $next, $route)
    {
        $userLang = $req->user ? $req->user['lang'] : null;
        Lang::setLocale(Lang::resolve($userLang, $req->cookie((string) config('security.lang_cookie'))));
        return $next($req);
    }
}
