<?php
namespace Omps\Modules\System\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\WorkCalendar;

/**
 * Holidays and make-up workdays of the unit (系统设置 → 节假日与调休, super admin). A holiday is a day
 * off even when it falls on a workday; a make-up workday (调休上班) is a workday even on a weekend.
 * The work calendar applies them to reminders and to the execution statistics at once.
 */
class HolidayService
{
    const KINDS = array('off', 'work');
    const MAX_DAYS = 60;

    private static function present(array $r)
    {
        $days = (int) round((strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400) + 1;
        return array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'], 'days' => $days);
    }

    /** Entries touching the year, and the years that have entries. */
    public static function index($year)
    {
        $year = (int) $year ?: (int) date('Y');
        $rows = DB::select('SELECT * FROM holidays WHERE start_date <= ? AND end_date >= ? ORDER BY start_date, kind', array($year . '-12-31', $year . '-01-01'));
        $years = array();
        foreach (DB::select('SELECT DISTINCT YEAR(start_date) AS y FROM holidays ORDER BY y') as $y) {
            $years[] = (int) $y['y'];
        }
        // the workdays of the year after holidays and make-up days, for the summary line
        $cal = WorkCalendar::fromSettings();
        $n = 0;
        for ($ts = mktime(12, 0, 0, 1, 1, $year); (int) date('Y', $ts) === $year; $ts = WorkCalendar::addDays($ts, 1)) {
            if ($cal->isWorkday($ts)) {
                $n++;
            }
        }
        return array('year' => $year, 'years' => $years, 'rows' => array_map(array(__CLASS__, 'present'), $rows), 'workdays' => $n);
    }

    private static function validate(array $in)
    {
        $v = Validator::check($in, array('name' => 'required|string|max:50', 'kind' => 'required|string', 'start' => 'required|date', 'end' => 'required|date'),
            array('name' => 'holiday.field.name', 'kind' => 'holiday.field.kind', 'start' => 'holiday.field.start', 'end' => 'holiday.field.end'));
        if (!in_array($v['kind'], self::KINDS, true)) {
            throw ValidationException::field('kind', 'holiday.err.kind');
        }
        if ($v['end'] < $v['start']) {
            throw ValidationException::field('end', 'holiday.err.order');
        }
        if ((strtotime($v['end']) - strtotime($v['start'])) / 86400 + 1 > self::MAX_DAYS) {
            throw ValidationException::field('end', 'holiday.err.too_long', array('n' => self::MAX_DAYS));
        }
        return $v;
    }

    private static function overlap(array $v, $exceptId)
    {
        $q = 'SELECT name FROM holidays WHERE kind = ? AND start_date <= ? AND end_date >= ?' . ($exceptId ? ' AND id <> ?' : '');
        $p = array($v['kind'], $v['end'], $v['start']);
        if ($exceptId) {
            $p[] = (int) $exceptId;
        }
        $hit = DB::selectOne($q, $p);
        if ($hit) {
            throw ValidationException::field('start', 'holiday.err.overlap', array('name' => (string) $hit['name']));
        }
    }

    public static function store(Request $req, array $in)
    {
        $v = self::validate($in);
        self::overlap($v, null);
        $id = DB::table('holidays')->insert(array('org_id' => (int) config('app.org_id', 1), 'name' => $v['name'], 'kind' => $v['kind'], 'start_date' => $v['start'],
            'end_date' => $v['end'], 'created_at' => now(), 'created_by' => $req->userId()));
        Audit::op($req->user, 'settings', 'holiday_added', 'holiday', $id, $v['name'], array('kind' => $v['kind'], 'start' => $v['start'], 'end' => $v['end']), $req->ip());
        return array('id' => $id);
    }

    public static function update(Request $req, $id, array $in)
    {
        $r = DB::table('holidays')->find((int) $id);
        if (!$r) {
            throw HttpException::notFound();
        }
        $v = self::validate($in);
        self::overlap($v, (int) $id);
        DB::table('holidays')->where('id', (int) $id)->update(array('name' => $v['name'], 'kind' => $v['kind'], 'start_date' => $v['start'], 'end_date' => $v['end'],
            'updated_at' => now(), 'updated_by' => $req->userId()));
        list($b, $a) = Audit::diff(array('name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date']),
            array('name' => $v['name'], 'kind' => $v['kind'], 'start' => $v['start'], 'end' => $v['end']));
        if ($a) {
            Audit::change($req, 'settings', 'holiday_changed', 'holiday', (int) $id, $v['name'], $b, $a);
        }
        return array('ok' => true);
    }

    public static function destroy(Request $req, $id)
    {
        $r = DB::table('holidays')->find((int) $id);
        if (!$r) {
            throw HttpException::notFound();
        }
        DB::table('holidays')->where('id', (int) $id)->delete();
        Audit::op($req->user, 'settings', 'holiday_deleted', 'holiday', (int) $id, (string) $r['name'],
            array('kind' => (string) $r['kind'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date']), $req->ip());
        return array('ok' => true);
    }
}
