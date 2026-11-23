<?php
namespace Omps\Modules\Plan\Services;

/**
 * Time label of a plan in the current language (same rules as OMPS.plan.label in plan.js,
 * used where the server writes text itself: report downloads).
 * $p = [level, start, end, tl_mode, tl_text].
 */
class PlanLabel
{
    public static function label(array $p, $today = null)
    {
        $today = $today === null ? date('Y-m-d') : $today;
        $mode = isset($p['tl_mode']) ? (string) $p['tl_mode'] : 'auto';
        if ($mode === 'text' && isset($p['tl_text']) && (string) $p['tl_text'] !== '') {
            return (string) $p['tl_text'];
        }
        if ($mode !== 'auto' && $mode !== 'text') {
            foreach (self::shortcuts((string) $p['start'], (string) $p['end']) as $o) {
                if ($o[0] === $mode) {
                    return $o[1];
                }
            }
        }
        return self::auto((string) $p['level'], (string) $p['start'], (string) $p['end'], $today);
    }

    private static function lastDay($y, $m)
    {
        return (int) date('t', mktime(0, 0, 0, (int) $m, 1, (int) $y));
    }

    private static function exact($level, $s, $e)
    {
        $y = (int) substr($s, 0, 4);
        $m = (int) substr($s, 5, 2);
        switch ($level) {
            case 'long':
                return substr($s, 5) === '01-01' && substr($e, 5) === '12-31';
            case 'annual':
                return $s === $y . '-01-01' && $e === $y . '-12-31';
            case 'quarter':
                return in_array($m, array(1, 4, 7, 10), true) && substr($s, 8) === '01' && $e === sprintf('%04d-%02d-%02d', $y, $m + 2, self::lastDay($y, $m + 2));
            case 'month':
                return substr($s, 8) === '01' && $e === sprintf('%04d-%02d-%02d', $y, $m, self::lastDay($y, $m));
            case 'week':
                return (int) date('N', strtotime($s)) === 1 && (int) round((strtotime($e) - strtotime($s)) / 86400) === 6;
        }
        return $s === $e;
    }

    public static function auto($level, $s, $e, $today = null)
    {
        $today = $today === null ? date('Y-m-d') : $today;
        if (self::exact($level, $s, $e)) {
            switch ($level) {
                case 'long':
                    return substr($s, 0, 4) . '–' . substr($e, 0, 4);
                case 'annual':
                    return __('plan.tl.year', array('y' => substr($s, 0, 4)));
                case 'quarter':
                    return __('plan.tl.q' . (int) ceil((int) substr($s, 5, 2) / 3));
                case 'month':
                    return __('plan.tl.m', array('m' => (int) substr($s, 5, 2)));
                case 'week':
                    return __('plan.tl.w', array('w' => (int) date('W', strtotime($s))));
            }
            return $s === $today ? __('plan.tl.today') : substr($s, 5);
        }
        $y = substr($today, 0, 4);
        // whole months of one year: "1–5 月"
        $ms = (int) substr($s, 5, 2);
        $me = (int) substr($e, 5, 2);
        if (substr($s, 0, 4) === substr($e, 0, 4) && substr($s, 8) === '01' && $e === sprintf('%s-%02d-%02d', substr($e, 0, 4), $me, self::lastDay(substr($e, 0, 4), $me)) && $ms < $me) {
            $m = __('plan.tl.months', array('a' => $ms, 'b' => $me));
            return substr($s, 0, 4) === $y ? $m : __('plan.tl.year', array('y' => substr($s, 0, 4))) . ' ' . $m;
        }
        if (substr($s, 0, 4) === $y && substr($e, 0, 4) === $y) {
            return substr($s, 5) . ' – ' . substr($e, 5);
        }
        return $s . ' – ' . $e;
    }

    private static function shortcuts($s, $e)
    {
        if ($s === '' || $e === '' || $e < $s) {
            return array();
        }
        $ys = substr($s, 0, 4);
        $ye = substr($e, 0, 4);
        $ms = (int) substr($s, 5, 2);
        $me = (int) substr($e, 5, 2);
        if ($ys !== $ye) {
            return array(array('span', $ys . '–' . $ye));
        }
        $out = array(array('year', __('plan.tl.year', array('y' => $ys))));
        if ((int) ceil($ms / 6) === (int) ceil($me / 6)) {
            $out[] = array('half', __($ms <= 6 ? 'plan.tl.h1' : 'plan.tl.h2'));
        }
        if ((int) ceil($ms / 3) === (int) ceil($me / 3)) {
            $out[] = array('q', __('plan.tl.q' . (int) ceil($ms / 3)));
        }
        if ($ms === $me) {
            $out[] = array('m', __('plan.tl.m', array('m' => $ms)));
        }
        $ts = strtotime($s);
        $te = strtotime($e);
        if (date('o', $ts) === date('o', $te) && date('W', $ts) === date('W', $te)) {
            $out[] = array('w', __('plan.tl.w', array('w' => (int) date('W', $ts))));
        }
        return $out;
    }
}
