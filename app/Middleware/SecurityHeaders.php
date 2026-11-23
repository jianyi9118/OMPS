<?php
namespace Omps\Middleware;

use Omps\Core\Request;
use Omps\Core\Response;

/**
 * Security response headers on every response.
 */
class SecurityHeaders
{
    public function handle(Request $req, $next, $route)
    {
        return $this->apply($next($req));
    }

    public function apply(Response $res)
    {
        $res->headers += array(
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'",
            'Cache-Control' => 'no-store, private',
        );
        return $res;
    }
}
