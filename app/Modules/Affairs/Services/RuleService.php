<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\WorkCalendar;

/**
 * Reminder rules (系统管理 → 提醒规则, design §13.3). The rule type fixes the base (due time,
 * overdue, event, fixed cycle), who is reminded and whether escalation exists; the unit head adjusts
 * the triggers, level, repeat cap and on/off. The list text is generated in the browser from the
 * structured triggers, so it follows the language.
 */
class RuleService
{
    /** base: due | overdue | event | cycle;  who: language key suffix rule.who.*;  esc: escalation target */
    const RULES = array(
        'issue_unread' => array('base' => 'event', 'who' => 'recipient', 'esc' => null),
        'issue_due' => array('base' => 'due', 'who' => 'recipient', 'esc' => null),
        'issue_overdue' => array('base' => 'overdue', 'who' => 'recipient', 'esc' => 'sender'),
        'perf_daily' => array('base' => 'cycle', 'who' => 'assignee', 'esc' => null),
        'perf_period' => array('base' => 'due', 'who' => 'assignee', 'esc' => null),
        'review_pending' => array('base' => 'event', 'who' => 'reviewer', 'esc' => null),
        'license_expire' => array('base' => 'due', 'who' => 'device_admin', 'esc' => null),
    );
    const BASE_MODES = array('due' => array('before', 'onday'), 'overdue' => array('after'), 'event' => array('now', 'evt'), 'cycle' => array('wday', 'week', 'mlast', 'mday'));
    const BASE_UNITS = array('due' => array('d', 'wd', 'h'), 'event' => array('min', 'h', 'wh', 'd', 'wd'));
    const LEVELS = array('info', 'warn', 'urgent');

    /** Enabled rules with triggers, by code (used by the scheduler). */
    public static function active()
    {
        $out = array();
        foreach (self::load() as $r) {
            if ($r['enabled']) {
                $out[$r['code']] = $r;
            }
        }
        return $out;
    }

    private static function presentTrigger(array $t)
    {
        return array('id' => (int) $t['id'], 'mode' => (string) $t['mode'], 'amount' => (int) $t['amount'], 'unit' => str_or_empty($t['unit']),
            'time_of_day' => str_or_empty($t['time_of_day']), 'weekday' => $t['weekday'] === null ? null : (int) $t['weekday'],
            'month_day' => $t['month_day'] === null ? null : (int) $t['month_day'], 'repeat_mode' => str_or_empty($t['repeat_mode']));
    }

    public static function load()
    {
        $triggers = array();
        foreach (DB::table('reminder_triggers')->orderBy('sort')->orderBy('id')->get() as $t) {
            $triggers[(int) $t['rule_id']][] = self::presentTrigger($t);
        }
        $out = array();
        foreach (DB::table('reminder_rules')->orderBy('sort')->orderBy('id')->get() as $r) {
            $code = (string) $r['code'];
            if (!isset(self::RULES[$code])) {
                continue;
            }
            $meta = self::RULES[$code];
            $out[] = array('id' => (int) $r['id'], 'code' => $code, 'base' => $meta['base'], 'who' => $meta['who'], 'esc_target' => $meta['esc'],
                'level' => (string) $r['level'], 'max_repeat' => (int) $r['max_repeat'], 'escalate' => (int) $r['escalate'] === 1, 'enabled' => (int) $r['enabled'] === 1,
                'triggers' => isset($triggers[(int) $r['id']]) ? $triggers[(int) $r['id']] : array(), 'updated_at' => str_or_empty($r['updated_at']));
        }
        return $out;
    }

    public static function index()
    {
        $cal = WorkCalendar::fromSettings();
        return array('rules' => self::load(), 'modes' => self::BASE_MODES, 'units' => self::BASE_UNITS, 'levels' => self::LEVELS,
            'work' => array('days' => $cal->days, 'start' => sprintf('%02d:%02d', intdiv($cal->start, 60), $cal->start % 60), 'end' => sprintf('%02d:%02d', intdiv($cal->end, 60), $cal->end % 60)));
    }

    /**
     * Check triggers (design §13.3): at least one; amounts above 0; day-based triggers need a clock time;
     * no duplicates; only modes and units the base allows. Returns normalized rows.
     */
    public static function checkTriggers($base, $list)
    {
        if (!is_array($list) || !$list) {
            throw ValidationException::field('triggers', 'rule.err.none');
        }
        if (count($list) > 12) {
            throw ValidationException::field('triggers', 'rule.err.too_many');
        }
        $modes = self::BASE_MODES[$base];
        $units = isset(self::BASE_UNITS[$base]) ? self::BASE_UNITS[$base] : array();
        $out = array();
        $seen = array();
        foreach (array_values($list) as $i => $t) {
            $no = $i + 1;
            if (!is_array($t)) {
                throw ValidationException::field('triggers', 'rule.err.bad', array('n' => $no));
            }
            $mode = isset($t['mode']) ? (string) $t['mode'] : '';
            if (!in_array($mode, $modes, true)) {
                throw ValidationException::field('triggers', 'rule.err.bad', array('n' => $no));
            }
            $row = array('mode' => $mode, 'amount' => 0, 'unit' => null, 'time_of_day' => null, 'weekday' => null, 'month_day' => null, 'repeat_mode' => null);
            $amount = isset($t['amount']) && (is_int($t['amount']) || (is_string($t['amount']) && ctype_digit($t['amount']))) ? (int) $t['amount'] : null;
            $time = isset($t['time_of_day']) ? trim((string) $t['time_of_day']) : '';
            $needTime = in_array($mode, array('onday', 'after', 'wday', 'week', 'mlast', 'mday'), true);
            if ($mode === 'before' || $mode === 'evt') {
                $unit = isset($t['unit']) ? (string) $t['unit'] : '';
                if (!in_array($unit, $units, true)) {
                    throw ValidationException::field('triggers', 'rule.err.bad', array('n' => $no));
                }
                if ($amount === null || $amount < 1 || $amount > 999) {
                    throw ValidationException::field('triggers', 'rule.err.amount', array('n' => $no));
                }
                $row['amount'] = $amount;
                $row['unit'] = $unit;
                $needTime = $unit === 'd' || $unit === 'wd';
            }
            if ($mode === 'after') {
                $rep = isset($t['repeat_mode']) ? (string) $t['repeat_mode'] : '';
                if (!in_array($rep, ReminderCalc::REPEATS, true)) {
                    throw ValidationException::field('triggers', 'rule.err.bad', array('n' => $no));
                }
                $row['repeat_mode'] = $rep;
                if ($rep === 'once') {
                    if ($amount === null || $amount < 1 || $amount > 999) {
                        throw ValidationException::field('triggers', 'rule.err.amount', array('n' => $no));
                    }
                    $row['amount'] = $amount;
                }
            }
            if ($mode === 'week') {
                $wd = isset($t['weekday']) ? (int) $t['weekday'] : 0;
                if ($wd < 1 || $wd > 7) {
                    throw ValidationException::field('triggers', 'rule.err.bad', array('n' => $no));
                }
                $row['weekday'] = $wd;
            }
            if ($mode === 'mday') {
                $md = isset($t['month_day']) ? (int) $t['month_day'] : 0;
                if ($md < 1 || $md > 28) {
                    throw ValidationException::field('triggers', 'rule.err.bad', array('n' => $no));
                }
                $row['month_day'] = $md;
            }
            if ($needTime) {
                if (!WorkCalendar::validTime($time)) {
                    throw ValidationException::field('triggers', 'rule.err.time', array('n' => $no));
                }
                $row['time_of_day'] = $time;
            }
            $sig = implode('|', array_map('strval', $row));
            if (isset($seen[$sig])) {
                throw ValidationException::field('triggers', 'rule.err.duplicate', array('n' => $no));
            }
            $seen[$sig] = true;
            $out[] = $row;
        }
        return $out;
    }

    private static function find($id)
    {
        $r = DB::table('reminder_rules')->find((int) $id);
        if (!$r || !isset(self::RULES[(string) $r['code']])) {
            throw HttpException::notFound();
        }
        return $r;
    }

    public static function update(Request $req, $id, array $in)
    {
        $r = self::find($id);
        $meta = self::RULES[(string) $r['code']];
        $triggers = self::checkTriggers($meta['base'], isset($in['triggers']) ? $in['triggers'] : null);
        $level = isset($in['level']) && in_array($in['level'], self::LEVELS, true) ? (string) $in['level'] : (string) $r['level'];
        $max = (int) $r['max_repeat'];
        if ($meta['base'] === 'overdue') {
            $max = isset($in['max_repeat']) ? (int) $in['max_repeat'] : $max;
            if ($max < 1 || $max > 30) {
                throw ValidationException::field('max_repeat', 'rule.err.max_repeat');
            }
        }
        $esc = $meta['esc'] !== null && !empty($in['escalate']) ? 1 : 0;
        $enabled = !isset($in['enabled']) || !empty($in['enabled']) ? 1 : 0;
        $before = array('enabled' => (int) $r['enabled'], 'level' => (string) $r['level'], 'max_repeat' => (int) $r['max_repeat'], 'escalate' => (int) $r['escalate'],
            'triggers' => self::signature(DB::table('reminder_triggers')->where('rule_id', (int) $r['id'])->orderBy('sort')->orderBy('id')->get()));
        $after = array('enabled' => $enabled, 'level' => $level, 'max_repeat' => $max, 'escalate' => $esc, 'triggers' => self::signature($triggers));
        $uid = $req->userId();
        DB::transaction(function () use ($r, $triggers, $level, $max, $esc, $enabled, $uid) {
            DB::table('reminder_rules')->where('id', (int) $r['id'])->update(array('level' => $level, 'max_repeat' => $max, 'escalate' => $esc, 'enabled' => $enabled,
                'updated_at' => now(), 'updated_by' => $uid));
            DB::table('reminder_triggers')->where('rule_id', (int) $r['id'])->delete();
            $sort = 0;
            foreach ($triggers as $t) {
                $sort += 10;
                DB::table('reminder_triggers')->insert(array_merge($t, array('rule_id' => (int) $r['id'], 'sort' => $sort)));
            }
        });
        list($b, $a) = Audit::diff($before, $after);
        if ($a) {
            Audit::change($req, 'notify', 'reminder_rule_updated', 'reminder_rule', (int) $r['id'], (string) $r['code'], $b, $a);
        }
        return array('ok' => true);
    }

    private static function signature(array $rows)
    {
        $out = array();
        foreach ($rows as $t) {
            $out[] = implode(' ', array_filter(array((string) $t['mode'], (int) $t['amount'] ? (string) (int) $t['amount'] : '', (string) $t['unit'], (string) $t['time_of_day'],
                $t['weekday'] !== null ? 'w' . (int) $t['weekday'] : '', $t['month_day'] !== null ? 'd' . (int) $t['month_day'] : '', (string) $t['repeat_mode']), 'strlen'));
        }
        return implode('; ', $out);
    }

    /**
     * "具体时间示例": concrete times of the triggers for a sample object. Due-based rules use a due
     * time on the 3rd workday from now at 17:00 (overdue rules: due yesterday 17:00); event-based
     * rules use "now"; cycles list the next times.
     */
    public static function preview($id, array $in)
    {
        $r = self::find($id);
        $meta = self::RULES[(string) $r['code']];
        $triggers = self::checkTriggers($meta['base'], isset($in['triggers']) ? $in['triggers'] : null);
        $max = isset($in['max_repeat']) ? max(1, min(30, (int) $in['max_repeat'])) : (int) $r['max_repeat'];
        $cal = WorkCalendar::fromSettings();
        $now = time();
        $base = null;
        if ($meta['base'] === 'due') {
            $base = WorkCalendar::at($cal->addWorkdays(WorkCalendar::day($now), 3), '17:00');
            $from = $now - 86400 * 40;
            $until = $base;
        } elseif ($meta['base'] === 'overdue') {
            $base = WorkCalendar::at($cal->addWorkdays(WorkCalendar::day($now), -1), '17:00');
            $from = $base;
            $until = WorkCalendar::addDays($base, 45);
        } elseif ($meta['base'] === 'event') {
            $base = $now;
            $from = $now;
            $until = WorkCalendar::addDays($now, 45);
        } else {
            $from = $now;
            $until = WorkCalendar::addDays($now, 62);
        }
        $out = array();
        foreach ($triggers as $i => $t) {
            $list = ReminderCalc::slots($t, $base, $cal, $from, $until, $meta['base'] === 'overdue' ? $max : 0);
            $times = array();
            foreach (array_slice($list, 0, 3) as $ts) {
                $times[] = array('at' => date('Y-m-d H:i', $ts), 'wd' => (int) date('w', $ts));
            }
            $out[] = array('index' => $i, 'times' => $times, 'more' => count($list) > 3 ? count($list) - 3 : 0);
        }
        return array('base' => $base === null ? '' : date('Y-m-d H:i', $base), 'base_wd' => $base === null ? null : (int) date('w', $base), 'kind' => $meta['base'], 'rows' => $out);
    }
}
