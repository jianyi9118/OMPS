<?php
namespace Omps\Modules\Stats\Services;

use Omps\Core\Lang;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\CustomFields;
use Omps\Engines\Permission;

/**
 * Controlled statistics (design §15 受控统计). A widget is a definition — entity, dimension,
 * measure, an optional status filter — never SQL. Rows are read with the viewer's data scope
 * applied and grouped in PHP, so every page only counts what the viewer may see:
 *  - personnel: role scope (unit / department / self);
 *  - devices: device admins and whole-unit roles see all, department scope sees its department;
 *  - plans: the plans visible to the viewer in 计划与业绩.
 */
class StatEngine
{
    const ENTITIES = array('personnel', 'device', 'plan');
    const CHARTS = array('bar', 'pie', 'line', 'table', 'number');
    const DIMS = array(
        'personnel' => array('dept', 'gender', 'status', 'age_band', 'hire_year', 'education'),
        'device' => array('category', 'run_status', 'dept', 'purchase_year', 'brand'),
        'plan' => array('level', 'status', 'dept', 'end_year', 'priority'),
    );
    const MEASURES = array(
        'personnel' => array('count', 'avg_age'),
        'device' => array('count', 'sum_price', 'avg_price'),
        'plan' => array('count', 'avg_progress'),
    );
    /** Dimensions whose values have a natural order (shown in order, not by size). */
    const ORDERED = array('age_band', 'hire_year', 'purchase_year', 'end_year', 'level');
    const AGE_BANDS = array('lt30', '30_39', '40_49', '50_59', 'ge60', 'unknown');
    const EDU = array('postgraduate', 'bachelor', 'junior_college', 'high_school');
    const MAX_GROUPS = 12;

    /** Dimensions and measures for the designer, custom fields included. */
    public static function options()
    {
        $out = array();
        foreach (self::ENTITIES as $e) {
            $dims = array();
            foreach (self::DIMS[$e] as $d) {
                $dims[] = array('key' => $d, 'label' => __('stat.dim.' . $d));
            }
            $measures = array();
            foreach (self::MEASURES[$e] as $m) {
                $measures[] = array('key' => $m, 'label' => __('stat.measure.' . $m));
            }
            foreach (CustomFields::defs($e) as $f) {
                if (!$f['statable']) {
                    continue;
                }
                if (in_array($f['type'], array('select', 'bool'), true)) {
                    $dims[] = array('key' => 'cf:' . $f['key'], 'label' => $f['label']);
                } elseif ($f['type'] === 'date') {
                    $dims[] = array('key' => 'cfy:' . $f['key'], 'label' => __('stat.year_of', array('field' => $f['label'])));
                } else {
                    $measures[] = array('key' => 'sum:' . $f['key'], 'label' => __('stat.sum_of', array('field' => $f['label'])));
                    $measures[] = array('key' => 'avg:' . $f['key'], 'label' => __('stat.avg_of', array('field' => $f['label'])));
                }
            }
            $out[$e] = array('label' => __('stat.entity.' . $e), 'dims' => $dims, 'measures' => $measures);
        }
        return $out;
    }

    /** Validate a widget definition; returns the clean definition. */
    public static function check(array $def, $chart)
    {
        $opts = self::options();
        $e = isset($def['entity']) ? (string) $def['entity'] : '';
        if (!isset($opts[$e])) {
            throw ValidationException::field('entity', 'stat.err.entity');
        }
        $dims = array_column($opts[$e]['dims'], 'key');
        $measures = array_column($opts[$e]['measures'], 'key');
        $dim = isset($def['dim']) ? (string) $def['dim'] : '';
        $measure = isset($def['measure']) ? (string) $def['measure'] : 'count';
        if ($chart !== 'number' && !in_array($dim, $dims, true)) {
            throw ValidationException::field('dim', 'stat.err.dim');
        }
        if (!in_array($measure, $measures, true)) {
            throw ValidationException::field('measure', 'stat.err.measure');
        }
        if (!in_array($chart, self::CHARTS, true)) {
            throw ValidationException::field('chart', 'stat.err.chart');
        }
        $scope = isset($def['only']) && in_array($def['only'], array('active', 'all'), true) ? $def['only'] : 'active';
        return array('entity' => $e, 'dim' => $chart === 'number' ? '' : $dim, 'measure' => $measure, 'only' => $scope);
    }

    // ---------- reading rows within the viewer's scope ----------

    private static function personnelRows($user, $only)
    {
        $q = DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()
            ->select(array('p.id', 'p.gender', 'p.status', 'p.birth_date', 'p.hire_date', 'p.dept_id', 'd.name as dept_name'));
        Permission::scopePersonnel($q, $user, 'p');
        if ($only === 'active') {
            $q->where('p.status', 'active');
        }
        $rows = $q->get();
        // highest education level per person
        $ids = array_column($rows, 'id');
        $edu = array();
        if ($ids) {
            $rank = array_flip(array_reverse(self::EDU));
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::table('personnel_education')->whereIn('person_id', $chunk)->select(array('person_id', 'degree_level'))->get() as $r) {
                    $lv = (string) $r['degree_level'];
                    if (!isset($rank[$lv])) {
                        continue;
                    }
                    $pid = (int) $r['person_id'];
                    if (!isset($edu[$pid]) || $rank[$lv] > $rank[$edu[$pid]]) {
                        $edu[$pid] = $lv;
                    }
                }
            }
        }
        $today = new \DateTime(date('Y-m-d'));
        foreach ($rows as &$r) {
            $age = null;
            if ($r['birth_date'] !== null) {
                $age = (int) (new \DateTime((string) $r['birth_date']))->diff($today)->y;
            }
            $r['_age'] = $age;
            $r['_edu'] = isset($edu[(int) $r['id']]) ? $edu[(int) $r['id']] : null;
        }
        unset($r);
        return $rows;
    }

    private static function deviceRows($user, $only)
    {
        if (!class_exists('Omps\Modules\Device\Services\DeviceService') || !DB::select("SHOW TABLES LIKE 'devices'")) {
            return array();
        }
        $q = DB::table('devices d')->leftJoin('departments dp', 'dp.id', '=', 'd.dept_id')->active()
            ->select(array('d.id', 'd.category', 'd.run_status', 'd.brand', 'd.price', 'd.purchase_date', 'd.dept_id', 'dp.name as dept_name'));
        \Omps\Modules\Device\Services\DeviceService::scopeQuery($q, $user, 'd');
        if ($only === 'active') {
            $q->where('d.run_status', '<>', 'scrapped');
        }
        return $q->get();
    }

    private static function planRows($user, $only)
    {
        $svc = 'Omps\Modules\Plan\Services\PlanService';
        if (!class_exists($svc)) {
            return array();
        }
        $plans = $svc::loadAll();
        $vis = $svc::visibleIds($user, $plans);
        $progress = array();
        if (class_exists('Omps\Modules\Performance\Services\PerformanceService')) {
            $perf = 'Omps\Modules\Performance\Services\PerformanceService';
            $progress = $perf::progressMap($plans, $perf::byPlans(array_keys($plans)));
        }
        $names = array();
        foreach (DB::table('departments')->select(array('id', 'name'))->get() as $d) {
            $names[(int) $d['id']] = (string) $d['name'];
        }
        $out = array();
        foreach ($plans as $id => $p) {
            if (!isset($vis[$id]) || ($only === 'active' && (string) $p['status'] !== 'active')) {
                continue;
            }
            $out[] = array('id' => (int) $id, 'level' => (string) $p['level'], 'status' => (string) $p['status'], 'priority' => $p['priority'],
                'end_date' => (string) $p['end_date'], 'dept_id' => $p['dept_id'] === null ? null : (int) $p['dept_id'],
                'dept_name' => $p['dept_id'] !== null && isset($names[(int) $p['dept_id']]) ? $names[(int) $p['dept_id']] : null,
                '_progress' => isset($progress[$id]) ? (float) $progress[$id] : 0.0);
        }
        return $out;
    }

    public static function rows($entity, $user, $only = 'active')
    {
        if ($entity === 'personnel') {
            return self::personnelRows($user, $only);
        }
        if ($entity === 'device') {
            return self::deviceRows($user, $only);
        }
        return self::planRows($user, $only);
    }

    // ---------- grouping ----------

    private static function group($entity, $dim, array $r, array $cf)
    {
        switch ($dim) {
            case 'dept':
                return $r['dept_id'] === null ? array('none', __('stat.no_dept')) : array('d' . $r['dept_id'], (string) $r['dept_name']);
            case 'gender':
                return array($r['gender'], __('gender.' . $r['gender']));
            case 'status':
                return $entity === 'plan' ? array($r['status'], __('stat.plan_status.' . $r['status'])) : array($r['status'], __('staff.status.' . $r['status']));
            case 'age_band':
                $a = $r['_age'];
                $k = $a === null ? 'unknown' : ($a < 30 ? 'lt30' : ($a < 40 ? '30_39' : ($a < 50 ? '40_49' : ($a < 60 ? '50_59' : 'ge60'))));
                return array($k, __('stat.age.' . $k));
            case 'hire_year':
                return $r['hire_date'] === null ? array('~', __('stat.unknown')) : array(substr((string) $r['hire_date'], 0, 4), substr((string) $r['hire_date'], 0, 4));
            case 'education':
                return $r['_edu'] === null ? array('~', __('stat.unknown')) : array($r['_edu'], __('dict.degree_level.' . $r['_edu']));
            case 'category':
                return array($r['category'], __('dict.device_category.' . $r['category']));
            case 'run_status':
                return array($r['run_status'], __('device.status.' . $r['run_status']));
            case 'purchase_year':
                return $r['purchase_date'] === null ? array('~', __('stat.unknown')) : array(substr((string) $r['purchase_date'], 0, 4), substr((string) $r['purchase_date'], 0, 4));
            case 'brand':
                $b = trim((string) $r['brand']);
                return $b === '' ? array('~', __('stat.unknown')) : array('b:' . mb_strtolower($b), $b);
            case 'level':
                return array(sprintf('%02d', array_search($r['level'], array('long', 'annual', 'quarter', 'month', 'week', 'day'), true)), __('plan.level.' . $r['level']));
            case 'end_year':
                return array(substr($r['end_date'], 0, 4), substr($r['end_date'], 0, 4));
            case 'priority':
                return $r['priority'] === null || $r['priority'] === '' ? array('~', __('stat.unknown')) : array($r['priority'], __('plan.priority.' . $r['priority']));
        }
        // custom field: cf:key (select / bool) or cfy:key (year of a date)
        $p = strpos($dim, ':');
        $key = substr($dim, $p + 1);
        $def = isset($cf['defs'][$key]) ? $cf['defs'][$key] : null;
        $v = isset($cf['values'][(int) $r['id']][$key]) ? $cf['values'][(int) $r['id']][$key] : null;
        if ($def === null || $v === null || $v === '') {
            return array('~', __('stat.unknown'));
        }
        if (substr($dim, 0, 4) === 'cfy:') {
            return array(substr((string) $v, 0, 4), substr((string) $v, 0, 4));
        }
        return array('v:' . (is_bool($v) ? (int) $v : $v), CustomFields::text($def, $v));
    }

    private static function measureValue($entity, $measure, array $r, array $cf)
    {
        if ($measure === 'count') {
            return 1;
        }
        if ($measure === 'avg_age') {
            return $r['_age'];
        }
        if ($measure === 'sum_price' || $measure === 'avg_price') {
            return $r['price'] === null ? null : (float) $r['price'];
        }
        if ($measure === 'avg_progress') {
            return $r['_progress'];
        }
        $key = substr($measure, 4);
        return isset($cf['values'][(int) $r['id']][$key]) && $cf['values'][(int) $r['id']][$key] !== null ? (float) $cf['values'][(int) $r['id']][$key] : null;
    }

    /**
     * Run a definition for a viewer: ['groups' => [[key, label, value]], 'total' => value, 'n' => rows].
     * Averages ignore empty values; groups beyond 12 fold into "其他".
     */
    public static function run(array $def, $user)
    {
        $rows = self::rows($def['entity'], $user, $def['only']);
        $cf = array('defs' => array(), 'values' => array());
        $needCf = strpos($def['dim'], ':') !== false || strpos($def['measure'], ':') !== false;
        if ($needCf) {
            foreach (CustomFields::defs($def['entity']) as $d) {
                $cf['defs'][$d['key']] = $d;
            }
            $cf['values'] = CustomFields::values($def['entity'], array_column($rows, 'id'));
        }
        $avg = in_array($def['measure'], array('avg_age', 'avg_price', 'avg_progress'), true) || substr($def['measure'], 0, 4) === 'avg:';
        $acc = array();
        $all = array(0.0, 0);
        foreach ($rows as $r) {
            $v = self::measureValue($def['entity'], $def['measure'], $r, $cf);
            if ($v === null) {
                if ($def['measure'] !== 'count') {
                    continue;
                }
            }
            $all[0] += (float) $v;
            $all[1]++;
            if ($def['dim'] === '') {
                continue;
            }
            list($k, $label) = self::group($def['entity'], $def['dim'], $r, $cf);
            if (!isset($acc[$k])) {
                $acc[$k] = array($k, $label, 0.0, 0);
            }
            $acc[$k][2] += (float) $v;
            $acc[$k][3]++;
        }
        $groups = array();
        foreach ($acc as $a) {
            $groups[] = array('key' => $a[0], 'label' => $a[1], 'value' => self::round($avg ? ($a[3] ? $a[2] / $a[3] : 0) : $a[2]), 'n' => $a[3]);
        }
        $ordered = in_array($def['dim'], self::ORDERED, true) || substr($def['dim'], 0, 4) === 'cfy:';
        usort($groups, function ($x, $y) use ($ordered) {
            if ($x['key'] === '~' || $y['key'] === '~') {
                return $x['key'] === '~' ? 1 : -1; // unknown last
            }
            if ($ordered) {
                return strcmp($x['key'], $y['key']);
            }
            return $x['value'] === $y['value'] ? strcmp($x['label'], $y['label']) : ($x['value'] < $y['value'] ? 1 : -1);
        });
        if ($def['dim'] === 'age_band') {
            $pos = array_flip(self::AGE_BANDS);
            usort($groups, function ($x, $y) use ($pos) {
                return $pos[$x['key']] - $pos[$y['key']];
            });
        }
        if (count($groups) > self::MAX_GROUPS && !$ordered && !$avg) {
            $rest = array_slice($groups, self::MAX_GROUPS - 1);
            $groups = array_slice($groups, 0, self::MAX_GROUPS - 1);
            $groups[] = array('key' => '~other', 'label' => __('stat.other'), 'value' => self::round(array_sum(array_column($rest, 'value'))), 'n' => array_sum(array_column($rest, 'n')));
        }
        return array('groups' => $groups, 'total' => self::round($avg ? ($all[1] ? $all[0] / $all[1] : 0) : $all[0]), 'n' => $all[1], 'avg' => $avg);
    }

    private static function round($v)
    {
        return round((float) $v, 2);
    }

    /** Title of a widget in the current language. */
    public static function title(array $def, $chart)
    {
        $opts = self::options();
        $e = $opts[$def['entity']];
        $m = '';
        foreach ($e['measures'] as $x) {
            if ($x['key'] === $def['measure']) {
                $m = $x['label'];
            }
        }
        if ($chart === 'number' || $def['dim'] === '') {
            return $e['label'] . ' · ' . $m;
        }
        $d = '';
        foreach ($e['dims'] as $x) {
            if ($x['key'] === $def['dim']) {
                $d = $x['label'];
            }
        }
        return __('stat.title', array('entity' => $e['label'], 'dim' => $d, 'measure' => $m));
    }

    /** Page title from translations (namespace stat). */
    public static function pageTitle($id)
    {
        return Lang::db('stat', 'page.' . (int) $id);
    }
}
