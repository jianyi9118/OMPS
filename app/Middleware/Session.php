<?php
namespace Omps\Middleware;

use Omps\Core\Auth;
use Omps\Core\Request;

/**
 * Loads the logged-in user from the session cookie (if any).
 */
class Session
{
    public function handle(Request $req, $next, $route)
    {
        Auth::load($req);
        return $next($req);
    }
}
