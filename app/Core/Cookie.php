<?php
namespace Omps\Core;

/**
 * Builds Set-Cookie header values. PHP 7.2's setcookie() cannot set SameSite,
 * so the header is written by hand (HttpOnly, SameSite=Lax, Secure on HTTPS).
 */
class Cookie
{
    public static function make($name, $value, $maxAge, Request $req, $httpOnly = true)
    {
        $parts = array(rawurlencode($name) . '=' . rawurlencode((string) $value));
        $path = Url::basePath() === '' ? '/' : Url::basePath() . '/';
        $parts[] = 'Path=' . $path;
        if ($maxAge !== null) {
            $parts[] = 'Max-Age=' . (int) $maxAge;
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', time() + (int) $maxAge) . ' GMT';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=Lax';
        if ($req->isSecure() || (bool) config('security.force_secure_cookie', false)) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    public static function forget($name, Request $req)
    {
        return self::make($name, '', -3600, $req);
    }
}
