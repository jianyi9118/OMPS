<?php
namespace Omps\Middleware;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Engines\Audit;
use Omps\Engines\Permission;

/**
 * Checks the route's 'perm' option (one code, or several separated by | meaning "any of").
 * Denials are written to the audit log.
 */
class PermissionCheck
{
    public function handle(Request $req, $next, $route)
    {
        if ($route === null || empty($route['opt']['perm'])) {
            return $next($req);
        }
        foreach (explode('|', (string) $route['opt']['perm']) as $code) {
            if (Permission::can($req->user, $code)) {
                return $next($req);
            }
        }
        Audit::denied($req, 'permission', array('perm' => $route['opt']['perm']));
        throw HttpException::forbidden();
    }
}
