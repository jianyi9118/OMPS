<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Engines\WorkCalendar;

/**
 * Turns structured triggers (design §13.3) into concrete times. Nothing is parsed from text.
 *
 * Modes:  before  N d|wd|h before the base (d / wd with a clock time)     — due-based rules
 *         onday   on the base day at a clock time                          — due-based rules
 *         after   after the base: repeat wday|day every (work)day, or once on day N, at a clock time — overdue
 *         now     when the event happens                                   — event-based rules
 *         evt     N min|h|wh|d|wd after the event (d / wd with a clock time)
 *         wday    every workday at a clock time                            — fixed cycles
 *         week    every week on weekday W at a clock time
 *         mlast   on the last workday of every month at a clock time
 *         mday    on day N (1–28) of every month at a clock time
 * Workday rule: "before" times falling on a non-workday move to the previous workday, all others
 * to the next one; times outside work hours move into work hours the same way.
 */
class ReminderCalc
{
    const MODES = array('before', 'onday', 'after', 'now', 'evt', 'wday', 'week', 'mlast', 'mday');
    const UNITS = array('min', 'h', 'wh', 'd', 'wd');
    const REPEATS = array('wday', 'day', 'once');

    /**
     * Times of one trigger inside [$from, $until] (sorted, unique).
     * @param array $t       trigger row: mode, amount, unit, time_of_day, weekday, month_day, repeat_mode
     * @param int|null $base base time (due time or event time); not used by fixed cycles
     * @param int $maxRepeat cap for repeating overdue triggers (0 = no cap)
     */
    public static function slots(array $t, $base, WorkCalendar $cal, $from, $until, $maxRepeat = 0)
    {
        $mode = (string) $t['mode'];
        $n = (int) (isset($t['amount']) ? $t['amount'] : 0);
        $unit = isset($t['unit']) ? (string) $t['unit'] : '';
        $time = isset($t['time_of_day']) && $t['time_of_day'] !== null ? (string) $t['time_of_day'] : '';
        $out = array();
        switch ($mode) {
            case 'before':
                if ($unit === 'h') {
                    $out[] = $cal->alignBefore($base - $n * 3600);
                } elseif ($unit === 'wd') {
                    $out[] = $cal->alignBefore(WorkCalendar::at($cal->addWorkdays($base, -$n), $time));
                } else {
                    $out[] = $cal->alignBefore($cal->prevWorkday(WorkCalendar::at(WorkCalendar::addDays($base, -$n), $time)));
                }
                break;
            case 'onday':
                $out[] = $cal->alignBefore($cal->prevWorkday(WorkCalendar::at($base, $time)));
                break;
            case 'after':
                $out = self::repeatAfter($t, $base, $cal, $until, $maxRepeat);
                break;
            case 'now':
                $out[] = $cal->alignAfter($base);
                break;
            case 'evt':
                if ($unit === 'min') {
                    $out[] = $cal->alignAfter($base + $n * 60);
                } elseif ($unit === 'h') {
                    $out[] = $cal->alignAfter($base + $n * 3600);
                } elseif ($unit === 'wh') {
                    $out[] = $cal->addWorkHours($base, $n);
                } elseif ($unit === 'wd') {
                    $out[] = $cal->alignAfter(WorkCalendar::at($cal->addWorkdays($base, $n), $time));
                } else {
                    $out[] = $cal->alignAfter($cal->nextWorkday(WorkCalendar::at(WorkCalendar::addDays($base, $n), $time)));
                }
                break;
            default:
                $out = self::cycle($t, $cal, $from, $until);
        }
        $res = array();
        foreach ($out as $ts) {
            if ($ts >= $from && $ts <= $until) {
                $res[$ts] = $ts;
            }
        }
        ksort($res);
        return array_values($res);
    }

    /** Overdue repeats: the first time after the base, then every (work)day; or once on day N. */
    private static function repeatAfter(array $t, $base, WorkCalendar $cal, $until, $maxRepeat)
    {
        $time = (string) $t['time_of_day'];
        $rep = isset($t['repeat_mode']) ? (string) $t['repeat_mode'] : 'wday';
        if ($rep === 'once') {
            return array($cal->alignAfter($cal->nextWorkday(WorkCalendar::at(WorkCalendar::addDays($base, max(1, (int) $t['amount'])), $time))));
        }
        $out = array();
        $d = WorkCalendar::day($base);
        $cap = $maxRepeat > 0 ? $maxRepeat : 400;
        $guard = 0;
        while (count($out) < $cap && $guard++ < 800) {
            $ts = WorkCalendar::at($d, $time);
            if ($ts > $base) {
                // "every day" still only reminds on workdays: a weekend slot moves to Monday
                $ts = $cal->alignAfter($rep === 'day' ? $cal->nextWorkday($ts) : $ts);
                if (($rep === 'day' || $cal->isWorkday(WorkCalendar::at($d, 0))) && !in_array($ts, $out, true)) {
                    $out[] = $ts;
                }
            }
            if ($ts > $until) {
                break;
            }
            $d = WorkCalendar::addDays($d, 1);
        }
        return $out;
    }

    /** Fixed cycles within the window. */
    private static function cycle(array $t, WorkCalendar $cal, $from, $until)
    {
        $mode = (string) $t['mode'];
        $time = (string) $t['time_of_day'];
        $out = array();
        $d = WorkCalendar::day($from);
        $guard = 0;
        while ($d <= $until && $guard++ < 800) {
            $ts = null;
            if ($mode === 'wday') {
                $ts = $cal->isWorkday($d) ? WorkCalendar::at($d, $time) : null;
            } elseif ($mode === 'week') {
                if ((int) date('N', $d) === (int) $t['weekday']) {
                    $ts = $cal->alignAfter($cal->nextWorkday(WorkCalendar::at($d, $time)));
                }
            } elseif ($mode === 'mlast') {
                $last = (int) mktime(0, 0, 0, (int) date('n', $d) + 1, 0, (int) date('Y', $d));
                if ($d === $last) {
                    $ts = $cal->alignBefore($cal->prevWorkday(WorkCalendar::at($d, $time)));
                }
            } elseif ($mode === 'mday') {
                if ((int) date('j', $d) === (int) $t['month_day']) {
                    $ts = $cal->alignAfter($cal->nextWorkday(WorkCalendar::at($d, $time)));
                }
            }
            if ($ts !== null) {
                $out[] = $ts;
            }
            $d = WorkCalendar::addDays($d, 1);
        }
        return $out;
    }

    /** All times of several triggers in the window, sorted: [[ts, trigger index], ...]. */
    public static function all(array $triggers, $base, WorkCalendar $cal, $from, $until, $maxRepeat = 0)
    {
        $out = array();
        foreach ($triggers as $i => $t) {
            foreach (self::slots($t, $base, $cal, $from, $until, $maxRepeat) as $ts) {
                $out[] = array($ts, $i);
            }
        }
        usort($out, function ($a, $b) {
            return $a[0] === $b[0] ? $a[1] - $b[1] : ($a[0] < $b[0] ? -1 : 1);
        });
        return $out;
    }
}
