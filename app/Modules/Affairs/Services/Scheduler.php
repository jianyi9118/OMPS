<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Core\Settings;
use Omps\Database\DB;
use Omps\Engines\Notify;
use Omps\Engines\WorkCalendar;

/**
 * Reminder engine (design §13). Run every 5 minutes by Windows Task Scheduler
 * (`php omps schedule:run`); when no task is set up, signed-in pages start a run at most every
 * 5 minutes, so reminders still appear.
 *
 *  - Only one run at a time (file lock).
 *  - Reminders are sent only in work time; a user's quiet hours postpone theirs.
 *  - For each object and recipient only the latest passed trigger point (within 2 days) is sent,
 *    so a scheduler that was off does not flood anyone; the dedupe key keeps it to one message.
 */
class Scheduler
{
    const LOOKBACK = 172800;
    const ISSUE_REMINDERS = array('remind_issue_unread', 'remind_issue_due', 'remind_issue_overdue', 'remind_issue_interval');

    /** @var WorkCalendar */
    private static $cal;
    /** @var int */
    private static $now;
    /** @var array user_id => [from, to] quiet hours in minutes */
    private static $quiet = array();
    /** @var int */
    private static $sent = 0;

    /** Start a run when the last one is more than 5 minutes old (called by the page poll). */
    public static function maybeRun()
    {
        $last = (string) Settings::get('scheduler_last_run', '');
        if ($last !== '' && strtotime($last) > time() - 300) {
            return null;
        }
        return self::run();
    }

    /**
     * @param int|null $now  time to act as "now" (tests)
     * @return array|null    ['sent' => n, 'skipped' => reason|null]; null when another run holds the lock
     */
    public static function run($now = null, $source = 'web')
    {
        $dir = storage_path('cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($dir . '/scheduler.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            return null;
        }
        try {
            $res = self::runLocked($now === null ? time() : (int) $now);
            Settings::set('scheduler_last_run', now());
            if ($source === 'cli') {
                Settings::set('scheduler_last_cli', now());
            }
            // the daily clean-up of old logs rides on the same run (task or pages), real time only
            if ($now === null) {
                try {
                    \Omps\Engines\Retention::runIfDue();
                } catch (\Exception $e) {
                    \Omps\Core\Logger::error('retention: ' . $e->getMessage());
                }
            }
            return $res;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function runLocked($now)
    {
        self::$cal = WorkCalendar::fromSettings();
        self::$now = $now;
        self::$sent = 0;
        Notify::clearCache();
        if (!self::$cal->inWorkTime($now)) {
            return array('sent' => 0, 'skipped' => 'off_hours');
        }
        self::loadQuiet();
        $rules = RuleService::active();
        self::issues($rules);
        if (isset($rules['perf_daily'])) {
            self::perfDaily($rules['perf_daily']);
        }
        if (isset($rules['perf_period'])) {
            self::perfPeriod($rules['perf_period']);
        }
        if (isset($rules['review_pending'])) {
            self::reviews($rules['review_pending']);
        }
        if (isset($rules['license_expire']) && DB::select("SHOW TABLES LIKE 'device_licenses'")) {
            self::licenses($rules['license_expire']);
        }
        return array('sent' => self::$sent, 'skipped' => null);
    }

    private static function loadQuiet()
    {
        self::$quiet = array();
        $prefs = array();
        foreach (DB::table('user_prefs')->whereIn('pkey', array('quiet_on', 'quiet_from', 'quiet_to'))->get() as $p) {
            $prefs[(int) $p['user_id']][(string) $p['pkey']] = (string) $p['pvalue'];
        }
        foreach ($prefs as $uid => $p) {
            if (isset($p['quiet_on']) && $p['quiet_on'] === '1' && isset($p['quiet_from'], $p['quiet_to'])
                && WorkCalendar::validTime($p['quiet_from']) && WorkCalendar::validTime($p['quiet_to'])) {
                self::$quiet[$uid] = array(WorkCalendar::minutes($p['quiet_from']), WorkCalendar::minutes($p['quiet_to']));
            }
        }
    }

    /** Is the user in their quiet hours right now? (A window may cross midnight.) */
    public static function quietNow($userId)
    {
        if (!isset(self::$quiet[(int) $userId])) {
            return false;
        }
        list($from, $to) = self::$quiet[(int) $userId];
        $m = (int) date('G', self::$now) * 60 + (int) date('i', self::$now);
        return $from <= $to ? ($m >= $from && $m < $to) : ($m >= $from || $m < $to);
    }

    /**
     * Send the latest passed trigger point of an object to its recipients.
     * @param int|null $stopAt trigger points at or after this time do not count (e.g. the due time)
     */
    private static function fire(array $rule, $objKey, array $userIds, $base, array $triggers, $maxRepeat, $type, array $params, $link, $bizType, $bizId, $stopAt = null)
    {
        $list = ReminderCalc::all($triggers, $base, self::$cal, self::$now - self::LOOKBACK, self::$now, $maxRepeat);
        $last = null;
        foreach ($list as $s) {
            if ($stopAt === null || $s[0] < $stopAt) {
                $last = $s;
            }
        }
        if ($last === null) {
            return;
        }
        $to = array();
        foreach ($userIds as $u) {
            if (!self::quietNow($u)) {
                $to[] = (int) $u;
            }
        }
        if (!$to) {
            return;
        }
        $key = $rule['code'] . ':' . $objKey . ':T' . $last[1] . ':' . date('YmdHi', $last[0]);
        self::$sent += Notify::send($to, $type, $params, $link, $bizType, $bizId, null, $rule['level'], $key);
    }

    private static function ts($dt)
    {
        return (int) strtotime((string) $dt);
    }

    private static function userName($id)
    {
        $u = Notify::user((int) $id);
        return $u ? $u['name'] : (string) DB::value('SELECT display_name FROM users WHERE id = ?', array((int) $id));
    }

    // ---------- issued items ----------

    /** Interval reminders chosen by the sender (更多选项): every workday 09:00, twice a day, every 2 work hours. */
    public static function intervalTriggers($freq, WorkCalendar $cal)
    {
        if ($freq === 'day') {
            return array(array('mode' => 'wday', 'time_of_day' => '09:00'));
        }
        if ($freq === 'half') {
            return array(array('mode' => 'wday', 'time_of_day' => '09:00'), array('mode' => 'wday', 'time_of_day' => '14:00'));
        }
        $out = array();
        for ($m = $cal->start + 120; $m < $cal->end; $m += 120) {
            $out[] = array('mode' => 'wday', 'time_of_day' => sprintf('%02d:%02d', intdiv($m, 60), $m % 60));
        }
        return $out;
    }

    private static function openRecipients()
    {
        $since = date('Y-m-d H:i:s', self::$now - 86400 * 90);
        return DB::select("SELECT ir.id AS rid, ir.user_id, ir.read_at, ir.done_at, i.id, i.title, i.kind, i.need_reply, i.due_at, i.remind_freq, i.sender_id, i.created_at, i.overdue_told
            FROM issue_recipients ir JOIN issues i ON i.id = ir.issue_id
            WHERE i.status = 'active' AND i.created_at >= ? AND ir.done_at IS NULL AND (i.need_reply = 1 OR ir.read_at IS NULL)
            ORDER BY i.id, ir.id", array($since));
    }

    private static function issues(array $rules)
    {
        $byIssue = array();
        foreach (self::openRecipients() as $r) {
            $id = (int) $r['id'];
            $link = '/affairs/issues?id=' . $id;
            $params = array('title' => (string) $r['title'], 'sender' => self::userName($r['sender_id']), 'due' => $r['due_at'] === null ? '' : substr((string) $r['due_at'], 0, 16));
            $uid = array((int) $r['user_id']);
            $created = self::ts($r['created_at']);
            $due = $r['due_at'] === null ? null : self::ts($r['due_at']);
            $order = (int) $r['need_reply'] === 1;
            if ($order && $due !== null && $due < self::$now) {
                $byIssue[$id][] = $r;
                if (isset($rules['issue_overdue'])) {
                    $days = max(1, (int) ceil((self::$now - $due) / 86400));
                    self::fire($rules['issue_overdue'], 'issue#' . $id, $uid, $due, $rules['issue_overdue']['triggers'], $rules['issue_overdue']['max_repeat'],
                        'remind_issue_overdue', array_merge($params, array('days' => $days)), $link, 'issue', $id);
                }
            }
            if ((string) $r['remind_freq'] !== 'auto') {
                self::fire(array('code' => 'issue_interval', 'level' => 'warn'), 'issue#' . $id, $uid, $created, self::intervalTriggers((string) $r['remind_freq'], self::$cal), 0,
                    'remind_issue_interval', $params, $link, 'issue', $id);
                continue;
            }
            if ($r['read_at'] === null && isset($rules['issue_unread'])) {
                self::fire($rules['issue_unread'], 'issue#' . $id, $uid, $created, $rules['issue_unread']['triggers'], 0, 'remind_issue_unread', $params, $link, 'issue', $id);
            }
            if ($order && $due !== null && $due > self::$now && isset($rules['issue_due'])) {
                self::fire($rules['issue_due'], 'issue#' . $id, $uid, $due, $rules['issue_due']['triggers'], 0, 'remind_issue_due', $params, $link, 'issue', $id, $due);
            }
        }
        // first time an order is overdue: tell the sender who has not finished
        if (isset($rules['issue_overdue']) && $rules['issue_overdue']['escalate']) {
            foreach ($byIssue as $id => $rows) {
                if ((int) $rows[0]['overdue_told'] === 1) {
                    continue;
                }
                $names = array();
                foreach ($rows as $r) {
                    $names[] = self::userName($r['user_id']);
                }
                self::$sent += Notify::send(array((int) $rows[0]['sender_id']), 'issue_overdue_list', array('title' => (string) $rows[0]['title'], 'n' => count($names),
                    'names' => array_slice($names, 0, 10)), '/affairs/issues?tab=sent&id=' . $id, 'issue', $id, null, 'warn',
                    'issue_overdue_list:issue#' . $id);
                DB::table('issues')->where('id', $id)->update(array('overdue_told' => 1));
            }
        }
    }

    /** How often a recipient was reminded and when the next reminder is due (shown on the item). */
    public static function recipientInfo(array $issue, array $rec)
    {
        $count = DB::table('notifications')->where('biz_type', 'issue')->where('biz_id', (int) $issue['id'])->where('user_id', (int) $rec['user_id'])
            ->whereIn('type', self::ISSUE_REMINDERS)->count();
        $next = null;
        $open = (string) $issue['status'] === 'active' && $rec['done_at'] === null && ((int) $issue['need_reply'] === 1 || $rec['read_at'] === null);
        if ($open) {
            $cal = WorkCalendar::fromSettings();
            $now = time();
            $until = WorkCalendar::addDays($now, 45);
            $rules = RuleService::active();
            $cands = array();
            $created = self::ts($issue['created_at']);
            $due = $issue['due_at'] === null ? null : self::ts($issue['due_at']);
            $order = (int) $issue['need_reply'] === 1;
            $add = function ($triggers, $base, $max, $stopAt = null) use (&$cands, $cal, $now, $until) {
                foreach (ReminderCalc::all($triggers, $base, $cal, $now + 1, $until, $max) as $s) {
                    if ($stopAt === null || $s[0] < $stopAt) {
                        $cands[] = $s[0];
                    }
                }
            };
            if ((string) $issue['remind_freq'] !== 'auto') {
                $add(self::intervalTriggers((string) $issue['remind_freq'], $cal), $created, 0);
            } else {
                if ($rec['read_at'] === null && isset($rules['issue_unread'])) {
                    $add($rules['issue_unread']['triggers'], $created, 0);
                }
                if ($order && $due !== null && $due > $now && isset($rules['issue_due'])) {
                    $add($rules['issue_due']['triggers'], $due, 0, $due);
                }
            }
            if ($order && $due !== null && isset($rules['issue_overdue'])) {
                // overdue repeats already used count against the cap
                $all = ReminderCalc::all($rules['issue_overdue']['triggers'], $due, $cal, $due, $until, $rules['issue_overdue']['max_repeat']);
                foreach ($all as $s) {
                    if ($s[0] > $now) {
                        $cands[] = $s[0];
                    }
                }
            }
            if ($cands) {
                $next = date('Y-m-d H:i', min($cands));
            }
        }
        return array('remind_count' => $count, 'next_remind' => $next);
    }

    // ---------- results ----------

    /** People with an account assigned to the plans, without a submitted or approved result: [plan_id => [person_id => user_id]]. */
    private static function missingResults(array $planIds)
    {
        $out = array();
        if (!$planIds) {
            return $out;
        }
        foreach (array_chunk($planIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::select("SELECT a.plan_id, a.person_id, r.status FROM plan_assignments a
                LEFT JOIN performance_reports r ON r.plan_id = a.plan_id AND r.person_id = a.person_id
                WHERE a.plan_id IN ($marks)", $chunk);
            foreach ($rows as $r) {
                if ($r['status'] !== null && in_array((string) $r['status'], array('submitted', 'approved'), true)) {
                    continue;
                }
                $uid = Notify::userOfPerson((int) $r['person_id']);
                if ($uid !== null) {
                    $out[(int) $r['plan_id']][(int) $r['person_id']] = $uid;
                }
            }
        }
        return $out;
    }

    private static function perfDaily(array $rule)
    {
        $today = date('Y-m-d', self::$now);
        $plans = DB::select("SELECT id, title FROM plans WHERE deleted_at IS NULL AND status = 'active' AND level = 'day' AND start_date <= ? AND end_date >= ?", array($today, $today));
        $byId = array();
        foreach ($plans as $p) {
            $byId[(int) $p['id']] = $p;
        }
        $missing = self::missingResults(array_keys($byId));
        // nobody is reminded of a daily report on a day of leave (审核与统计 → 请假登记)
        $ids = array();
        foreach ($missing as $people) {
            foreach ($people as $person => $_) {
                $ids[$person] = $person;
            }
        }
        $off = \Omps\Modules\Performance\Services\LeaveService::days(array_values($ids), $today, $today);
        foreach ($missing as $pid => $people) {
            $people = array_diff_key($people, $off);
            if (!$people) {
                continue;
            }
            self::fire($rule, 'plan#' . $pid, array_values($people), null, $rule['triggers'], 0, 'remind_perf_daily', array('title' => (string) $byId[$pid]['title']),
                '/plans/mine?plan=' . $pid, 'plan', $pid);
        }
    }

    private static function perfPeriod(array $rule)
    {
        $today = date('Y-m-d', self::$now);
        $limit = date('Y-m-d', self::$now + 86400 * 21);
        $plans = DB::select("SELECT id, title, end_date FROM plans WHERE deleted_at IS NULL AND status = 'active' AND level IN ('annual','quarter','month','week') AND end_date >= ? AND end_date <= ?",
            array($today, $limit));
        $byId = array();
        foreach ($plans as $p) {
            $byId[(int) $p['id']] = $p;
        }
        foreach (self::missingResults(array_keys($byId)) as $pid => $people) {
            $end = WorkCalendar::at(self::ts($byId[$pid]['end_date']), self::$cal->end);
            self::fire($rule, 'plan#' . $pid, array_values($people), $end, $rule['triggers'], 0, 'remind_perf_period',
                array('title' => (string) $byId[$pid]['title'], 'end' => (string) $byId[$pid]['end_date']), '/plans/mine?plan=' . $pid, 'plan', $pid, $end + 60);
        }
    }

    /** Device licences expiring within 60 days → device admins (unit heads when there are none). */
    private static function licenses(array $rule)
    {
        $today = date('Y-m-d', self::$now);
        $limit = date('Y-m-d', self::$now + 86400 * 60);
        $rows = DB::select("SELECT l.id, l.name, l.expires_on, d.id AS device_id, d.asset_no, d.name AS device_name FROM device_licenses l JOIN devices d ON d.id = l.device_id
            WHERE d.deleted_at IS NULL AND d.run_status <> 'scrapped' AND l.expires_on >= ? AND l.expires_on <= ?", array($today, $limit));
        if (!$rows) {
            return;
        }
        $to = Notify::deviceAdmins();
        foreach ($rows as $l) {
            $end = WorkCalendar::at(self::ts($l['expires_on']), self::$cal->end);
            self::fire($rule, 'license#' . (int) $l['id'] . ':' . $l['expires_on'], $to, $end, $rule['triggers'], 0, 'remind_license',
                array('asset' => (string) $l['asset_no'], 'device' => (string) $l['device_name'], 'name' => (string) $l['name'], 'date' => (string) $l['expires_on']),
                '/device/archive?id=' . (int) $l['device_id'], 'device_license', (int) $l['id'], $end + 60);
        }
    }

    /** Submissions waiting for review: results and profile changes. */
    private static function reviews(array $rule)
    {
        $since = date('Y-m-d H:i:s', self::$now - 86400 * 30);
        $rows = DB::select("SELECT r.id, r.person_id, r.submitted_at, p.title, pp.name, pp.dept_id FROM performance_reports r JOIN plans p ON p.id = r.plan_id
            JOIN personnel_profiles pp ON pp.id = r.person_id WHERE r.status = 'submitted' AND r.submitted_at >= ? AND p.deleted_at IS NULL AND pp.deleted_at IS NULL", array($since));
        foreach ($rows as $r) {
            $to = Notify::reviewersOf('perf.audit', array('id' => (int) $r['person_id'], 'dept_id' => $r['dept_id']));
            self::fire($rule, 'perf#' . (int) $r['id'], $to, self::ts($r['submitted_at']), $rule['triggers'], 0, 'remind_review',
                array('name' => (string) $r['name'], 'title' => (string) $r['title'], 'at' => substr((string) $r['submitted_at'], 0, 16)), '/performance/review?focus=' . (int) $r['id'], 'performance', (int) $r['id']);
        }
        $rows = DB::select("SELECT c.id, c.person_id, c.submitted_at, pp.name, pp.dept_id FROM profile_change_requests c JOIN personnel_profiles pp ON pp.id = c.person_id
            WHERE c.status = 'pending' AND c.submitted_at >= ? AND pp.deleted_at IS NULL", array($since));
        foreach ($rows as $r) {
            $to = Notify::reviewersOf('staff.audit', array('id' => (int) $r['person_id'], 'dept_id' => $r['dept_id']));
            self::fire($rule, 'change#' . (int) $r['id'], $to, self::ts($r['submitted_at']), $rule['triggers'], 0, 'remind_change',
                array('name' => (string) $r['name'], 'at' => substr((string) $r['submitted_at'], 0, 16)), '/org/staff?tab=review&id=' . (int) $r['id'], 'change_request', (int) $r['id']);
        }
    }
}
