<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\WorkCalendar;

/**
 * Bell, 我的通知 and personal reminder preferences (design §13.1).
 * Every page asks /api/v1/me/pulse every 60 seconds (plain AJAX): unread count, menu counts,
 * the newest unread message (for the 5-second pop-up) and orders due within 24 hours (yellow bar).
 */
class NotificationService
{
    const PREFS = array('quiet_on' => '0', 'quiet_from' => '12:00', 'quiet_to' => '13:30', 'popup' => '1');

    public static function present(array $n)
    {
        $p = $n['params'] === null ? array() : json_decode((string) $n['params'], true);
        return array('id' => (int) $n['id'], 'type' => (string) $n['type'], 'level' => (string) $n['level'], 'params' => is_array($p) ? $p : array(),
            'link' => str_or_empty($n['link']), 'read' => $n['read_at'] !== null, 'at' => (string) $n['created_at']);
    }

    public static function unread($userId)
    {
        return DB::table('notifications')->where('user_id', (int) $userId)->whereNull('read_at')->count();
    }

    public static function pulse(Request $req)
    {
        try {
            Scheduler::maybeRun();
        } catch (\Exception $e) {
            \Omps\Core\Logger::error('scheduler: ' . $e->getMessage());
        }
        $uid = $req->userId();
        $todo = TodoService::build($req->user);
        $unread = self::unread($uid);
        $latest = DB::table('notifications')->where('user_id', $uid)->whereNull('read_at')->orderBy('id', 'DESC')->first();
        $counts = $todo['counts'];
        $counts['notify'] = $unread;
        return array('unread' => $unread, 'counts' => $counts, 'latest' => $latest ? self::present($latest) : null, 'urgent' => $todo['urgent'],
            'popup' => self::prefs($uid)['popup'] === '1');
    }

    public static function index(Request $req, $tab, $page, $size)
    {
        $q = DB::table('notifications')->where('user_id', $req->userId());
        if ($tab === 'unread') {
            $q->whereNull('read_at');
        }
        $res = $q->orderBy('id', 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'present'), $res['rows']);
        $res['unread'] = self::unread($req->userId());
        return $res;
    }

    public static function readOne(Request $req, $id)
    {
        $n = DB::table('notifications')->where('id', (int) $id)->where('user_id', $req->userId())->first();
        if (!$n) {
            throw HttpException::notFound();
        }
        if ($n['read_at'] === null) {
            DB::table('notifications')->where('id', (int) $id)->update(array('read_at' => now()));
        }
        return array('link' => str_or_empty($n['link']), 'unread' => self::unread($req->userId()));
    }

    public static function readAll(Request $req)
    {
        $n = DB::table('notifications')->where('user_id', $req->userId())->whereNull('read_at')->update(array('read_at' => now()));
        return array('marked' => (int) $n, 'unread' => 0);
    }

    // ---------- personal preferences ----------

    public static function prefs($userId)
    {
        $out = self::PREFS;
        foreach (DB::table('user_prefs')->where('user_id', (int) $userId)->get() as $p) {
            if (array_key_exists((string) $p['pkey'], $out)) {
                $out[(string) $p['pkey']] = (string) $p['pvalue'];
            }
        }
        return $out;
    }

    public static function savePrefs(Request $req, array $in)
    {
        $cur = self::prefs($req->userId());
        $new = array(
            'quiet_on' => !empty($in['quiet_on']) ? '1' : '0',
            'quiet_from' => isset($in['quiet_from']) ? trim((string) $in['quiet_from']) : $cur['quiet_from'],
            'quiet_to' => isset($in['quiet_to']) ? trim((string) $in['quiet_to']) : $cur['quiet_to'],
            'popup' => !isset($in['popup']) || !empty($in['popup']) ? '1' : '0',
        );
        foreach (array('quiet_from', 'quiet_to') as $k) {
            if (!WorkCalendar::validTime($new[$k])) {
                throw ValidationException::field($k, 'pref.err.time');
            }
        }
        if ($new['quiet_on'] === '1' && $new['quiet_from'] === $new['quiet_to']) {
            throw ValidationException::field('quiet_to', 'pref.err.same');
        }
        $uid = $req->userId();
        DB::transaction(function () use ($new, $uid) {
            foreach ($new as $k => $v) {
                DB::table('user_prefs')->where('user_id', $uid)->where('pkey', $k)->delete();
                DB::table('user_prefs')->insert(array('user_id' => $uid, 'pkey' => $k, 'pvalue' => $v));
            }
        });
        return $new;
    }
}
