<?php
/**
 * Global helper functions.
 */

use Omps\Core\Config;
use Omps\Core\Env;
use Omps\Core\Lang;

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        return Env::get($key, $default);
    }
}

function config($key, $default = null)
{
    return Config::get($key, $default);
}

/** Escape for HTML output. Always use this in views. */
function e($value)
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Translate a key in the current language. */
function __($key, array $params = array())
{
    return Lang::get((string) $key, $params);
}

/** Current date-time string (Y-m-d H:i:s). */
function now()
{
    return date('Y-m-d H:i:s');
}

/** Build an application URL (handles rewrite fallback ?r=). */
function url($path = '/', array $query = array())
{
    return Omps\Core\Url::to($path, $query);
}

/** URL of a static asset under public/assets with cache-busting version. */
function asset($path)
{
    return Omps\Core\Url::asset($path);
}

function storage_path($rel = '')
{
    $base = (string) config('app.storage_path', OMPS_ROOT . '/storage');
    return $rel === '' ? $base : $base . '/' . ltrim($rel, '/');
}

/** Encode JSON safely for embedding in <script>. */
function json_for_script($data)
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** Cast a database value to int, keeping null. PHP 8.1 returns ints natively; 7.2 returns strings. */
function int_or_null($v)
{
    return $v === null || $v === '' ? null : (int) $v;
}

function str_or_empty($v)
{
    return $v === null ? '' : (string) $v;
}
