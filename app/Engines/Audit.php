<?php
namespace Omps\Engines;

use Omps\Core\Request;
use Omps\Database\DB;

/**
 * Operation log (what happened) and audit log (sensitive actions, before/after values,
 * security events such as out-of-scope access). Both are append-only.
 * Module and action are codes; the viewer's language translates them.
 */
class Audit
{
    public static function op($user, $module, $action, $targetType = null, $targetId = null, $label = null, $detail = null, $ip = null)
    {
        DB::table('operation_logs')->insert(array(
            'org_id' => (int) config('app.org_id', 1),
            'user_id' => $user ? (int) $user['id'] : null,
            'username' => $user ? mb_substr((string) $user['username'], 0, 50) : null,
            'module' => $module, 'action' => $action,
            'target_type' => $targetType, 'target_id' => $targetId === null ? null : (int) $targetId,
            'target_label' => $label === null ? null : mb_substr((string) $label, 0, 191),
            'detail' => $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ip' => $ip, 'created_at' => now(),
        ));
    }

    public static function log($user, $module, $action, $targetType = null, $targetId = null, $label = null, $before = null, $after = null, $ip = null)
    {
        DB::table('audit_logs')->insert(array(
            'org_id' => (int) config('app.org_id', 1),
            'user_id' => $user ? (int) $user['id'] : null,
            'username' => $user ? mb_substr((string) $user['username'], 0, 50) : null,
            'module' => $module, 'action' => $action,
            'target_type' => $targetType, 'target_id' => $targetId === null ? null : (int) $targetId,
            'target_label' => $label === null ? null : mb_substr((string) $label, 0, 191),
            'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip' => $ip, 'created_at' => now(),
        ));
    }

    /** Both logs at once (data changes on important records). */
    public static function change(Request $req, $module, $action, $targetType, $targetId, $label, $before, $after)
    {
        self::op($req->user, $module, $action, $targetType, $targetId, $label, null, $req->ip());
        self::log($req->user, $module, $action, $targetType, $targetId, $label, $before, $after, $req->ip());
    }

    /** Security event: someone asked for data outside their scope or without permission. */
    /** While true, refused checks are not logged (used when filtering lists, e.g. the file manager). */
    private static $quiet = false;

    /** Run access checks without logging refusals; returns the callback's result. */
    public static function quietly($fn)
    {
        $was = self::$quiet;
        self::$quiet = true;
        try {
            return $fn();
        } finally {
            self::$quiet = $was;
        }
    }

    public static function denied(Request $req, $what, array $detail = array())
    {
        if (self::$quiet) {
            return;
        }
        $detail['path'] = $req->method . ' ' . $req->path;
        self::log($req->user, 'security', 'access_denied', $what, null, null, null, $detail, $req->ip());
    }

    /** Only the keys whose values differ (for before/after records). */
    public static function diff(array $before, array $after)
    {
        $b = array();
        $a = array();
        foreach ($after as $k => $v) {
            $old = array_key_exists($k, $before) ? $before[$k] : null;
            if ((string) $old !== (string) $v) {
                $b[$k] = $old;
                $a[$k] = $v;
            }
        }
        return array($b, $a);
    }
}
