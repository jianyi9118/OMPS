<?php
namespace Omps\Engines;

use Omps\Core\Settings;

/**
 * Workdays and work hours (design §13.3): reminders are only sent in work time. Defaults:
 * Monday–Friday, 08:30–17:30, changeable in system settings. All values are Unix timestamps in the
 * application time zone.
 */
class WorkCalendar
{
    /** @var int[] ISO weekdays 1 (Mon) – 7 (Sun) */
    public $days;
    /** @var int minutes after midnight */
    public $start;
    /** @var int */
    public $end;

    public function __construct(array $days, $start, $end)
    {
        $this->days = array_values(array_unique(array_map('intval', $days)));
        if (!$this->days) {
            $this->days = array(1, 2, 3, 4, 5);
        }
        $this->start = self::minutes($start, 510);
        $this->end = self::minutes($end, 1050);
        if ($this->end <= $this->start) {
            $this->start = 510;
            $this->end = 1050;
        }
    }

    public static function fromSettings()
    {
        $days = array();
        foreach (explode(',', (string) Settings::get('work_days', '1,2,3,4,5')) as $d) {
            if (ctype_digit(trim($d)) && (int) $d >= 1 && (int) $d <= 7) {
                $days[] = (int) $d;
            }
        }
        return new self($days, (string) Settings::get('work_start', '08:30'), (string) Settings::get('work_end', '17:30'));
    }

    /** "HH:MM" → minutes after midnight ($default when invalid). */
    public static function minutes($hm, $default = 0)
    {
        if (!is_string($hm) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hm, $m)) {
            return $default;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function validTime($hm)
    {
        return is_string($hm) && (bool) preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hm);
    }

    /** Midnight of the day of $ts. */
    public static function day($ts)
    {
        return (int) mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
    }

    /** Same day as $ts at "HH:MM" (or at $minutes when an int is given). */
    public static function at($ts, $hm)
    {
        $min = is_int($hm) ? $hm : self::minutes($hm, 540);
        return (int) mktime(intdiv($min, 60), $min % 60, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
    }

    /** Add calendar days keeping the clock time (safe across DST changes). */
    public static function addDays($ts, $n)
    {
        return (int) mktime((int) date('G', $ts), (int) date('i', $ts), (int) date('s', $ts), (int) date('n', $ts), (int) date('j', $ts) + (int) $n, (int) date('Y', $ts));
    }

    public function isWorkday($ts)
    {
        return in_array((int) date('N', $ts), $this->days, true);
    }

    public function inWorkTime($ts)
    {
        if (!$this->isWorkday($ts)) {
            return false;
        }
        $m = (int) date('G', $ts) * 60 + (int) date('i', $ts);
        return $m >= $this->start && $m < $this->end;
    }

    /** The same clock time on the nearest workday on or before / after $ts. */
    public function prevWorkday($ts)
    {
        for ($i = 0; $i < 14 && !$this->isWorkday($ts); $i++) {
            $ts = self::addDays($ts, -1);
        }
        return $ts;
    }

    public function nextWorkday($ts)
    {
        for ($i = 0; $i < 14 && !$this->isWorkday($ts); $i++) {
            $ts = self::addDays($ts, 1);
        }
        return $ts;
    }

    /** Move by n workdays (n may be negative), keeping the clock time. */
    public function addWorkdays($ts, $n)
    {
        $step = $n < 0 ? -1 : 1;
        $left = abs((int) $n);
        $guard = 0;
        while ($left > 0 && $guard++ < 400) {
            $ts = self::addDays($ts, $step);
            if ($this->isWorkday($ts)) {
                $left--;
            }
        }
        return $ts;
    }

    /** $ts when in work time, otherwise the next start of work. */
    public function alignAfter($ts)
    {
        if ($this->inWorkTime($ts)) {
            return $ts;
        }
        $m = (int) date('G', $ts) * 60 + (int) date('i', $ts);
        if ($this->isWorkday($ts) && $m < $this->start) {
            return self::at($ts, $this->start);
        }
        return self::at($this->nextWorkday(self::addDays(self::day($ts), 1)), $this->start);
    }

    /** $ts when in work time, otherwise the last moment of work before it ("before" reminders move earlier). */
    public function alignBefore($ts)
    {
        if ($this->inWorkTime($ts)) {
            return $ts;
        }
        $m = (int) date('G', $ts) * 60 + (int) date('i', $ts);
        if ($this->isWorkday($ts) && $m >= $this->end) {
            return self::at($ts, $this->end) - 60;
        }
        return self::at($this->prevWorkday(self::addDays(self::day($ts), -1)), $this->end) - 60;
    }

    /** Add work hours: only time inside work hours counts. */
    public function addWorkHours($ts, $hours)
    {
        $need = (int) round($hours * 3600);
        $cur = $this->alignAfter($ts);
        $guard = 0;
        while ($guard++ < 400) {
            $endToday = self::at($cur, $this->end);
            $left = $endToday - $cur;
            if ($left >= $need) {
                return $cur + $need;
            }
            $need -= $left;
            $cur = self::at($this->nextWorkday(self::addDays(self::day($cur), 1)), $this->start);
        }
        return $cur;
    }
}
