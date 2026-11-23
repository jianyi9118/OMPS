<?php
namespace Omps\Modules\Stats\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\ValidationException;
use Omps\Core\Xlsx;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\CustomFields;
use Omps\Engines\Permission;

/**
 * 数据统计 pages (design §15, §17): 人员统计 · 部门统计 · 自定义统计, plus the page designer used in
 * 系统管理 → 自定义 → 统计页面设计. Everything is limited to the viewer's data scope.
 */
class StatService
{
    /** A widget result ready for the page. */
    private static function widget(array $def, $chart, $user, $title = null)
    {
        return array('chart' => $chart, 'def' => $def, 'title' => $title !== null ? $title : StatEngine::title($def, $chart), 'result' => StatEngine::run($def, $user));
    }

    private static function def($entity, $dim, $measure = 'count', $only = 'active')
    {
        return array('entity' => $entity, 'dim' => $dim, 'measure' => $measure, 'only' => $only);
    }

    // ---------- 人员统计 ----------

    public static function people(Request $req)
    {
        $u = $req->user;
        $rows = StatEngine::rows('personnel', $u, 'active');
        $ages = array_filter(array_column($rows, '_age'), function ($a) {
            return $a !== null;
        });
        $year = date('Y');
        $kpis = array(
            array('k' => 'stat.kpi.people', 'v' => count($rows)),
            array('k' => 'stat.kpi.male', 'v' => count(array_filter($rows, function ($r) {
                return $r['gender'] === 'male';
            }))),
            array('k' => 'stat.kpi.female', 'v' => count(array_filter($rows, function ($r) {
                return $r['gender'] === 'female';
            }))),
            array('k' => 'stat.kpi.avg_age', 'v' => $ages ? round(array_sum($ages) / count($ages), 1) : null),
            array('k' => 'stat.kpi.hired_year', 'v' => count(array_filter($rows, function ($r) use ($year) {
                return $r['hire_date'] !== null && substr((string) $r['hire_date'], 0, 4) === $year;
            }))),
        );
        $widgets = array(
            self::widget(self::def('personnel', 'dept'), 'bar', $u),
            self::widget(self::def('personnel', 'gender'), 'pie', $u),
            self::widget(self::def('personnel', 'age_band'), 'bar', $u),
            self::widget(self::def('personnel', 'education'), 'bar', $u),
            self::widget(self::def('personnel', 'hire_year'), 'line', $u),
        );
        foreach (CustomFields::defs('personnel') as $f) {
            if ($f['statable'] && in_array($f['type'], array('select', 'bool'), true)) {
                $widgets[] = self::widget(self::def('personnel', 'cf:' . $f['key']), $f['type'] === 'bool' ? 'pie' : 'bar', $u);
            }
        }
        return array('kpis' => $kpis, 'widgets' => $widgets, 'scope' => Permission::scope($u));
    }

    // ---------- 部门统计 ----------

    public static function depts(Request $req)
    {
        $u = $req->user;
        $allowed = Permission::deptIds($u);
        $depts = DB::table('departments')->active()->orderBy('path')->orderBy('sort')->get();
        $people = array();
        foreach (StatEngine::rows('personnel', $u, 'active') as $p) {
            $k = $p['dept_id'] === null ? 0 : (int) $p['dept_id'];
            $people[$k] = isset($people[$k]) ? $people[$k] + 1 : 1;
        }
        $plans = array();
        $today = time();
        foreach (StatEngine::rows('plan', $u, 'active') as $p) {
            if ($p['dept_id'] === null) {
                continue;
            }
            $k = (int) $p['dept_id'];
            if (!isset($plans[$k])) {
                $plans[$k] = array(0, 0.0, 0, 0);
            }
            $plans[$k][0]++;
            $plans[$k][1] += $p['_progress'];
            $plans[$k][3] += self::lagging($p, $today) ? 1 : 0;
        }
        // start dates for the lag check come from the plan table
        $devices = array();
        foreach (StatEngine::rows('device', $u, 'active') as $d) {
            $k = $d['dept_id'] === null ? 0 : (int) $d['dept_id'];
            if (!isset($devices[$k])) {
                $devices[$k] = array(0, 0);
            }
            $devices[$k][0]++;
            $devices[$k][1] += in_array($d['run_status'], array('fault', 'repairing'), true) ? 1 : 0;
        }
        $results = array();
        $month = date('Y-m-01 00:00:00');
        $q = DB::table('performance_reports r')->join('personnel_profiles p', 'p.id', '=', 'r.person_id')->whereNull('p.deleted_at')
            ->whereNotNull('r.submitted_at')->where('r.submitted_at', '>=', $month)->select(array('p.dept_id'));
        Permission::scopePersonnel($q, $u, 'p');
        foreach ($q->get() as $r) {
            $k = $r['dept_id'] === null ? 0 : (int) $r['dept_id'];
            $results[$k] = isset($results[$k]) ? $results[$k] + 1 : 1;
        }
        $rows = array();
        $leaders = array();
        foreach (DB::select('SELECT d.id, p.name FROM departments d JOIN personnel_profiles p ON p.id = d.leader_person_id WHERE d.deleted_at IS NULL') as $l) {
            $leaders[(int) $l['id']] = (string) $l['name'];
        }
        foreach ($depts as $d) {
            $id = (int) $d['id'];
            if ($allowed !== null && !in_array($id, $allowed, true)) {
                continue;
            }
            $pl = isset($plans[$id]) ? $plans[$id] : array(0, 0.0, 0, 0);
            $dv = isset($devices[$id]) ? $devices[$id] : array(0, 0);
            $rows[] = array('id' => $id, 'name' => (string) $d['name'], 'depth' => max(0, substr_count((string) $d['path'], '/') - 1), 'leader' => isset($leaders[$id]) ? $leaders[$id] : '',
                'people' => isset($people[$id]) ? $people[$id] : 0, 'plans' => $pl[0], 'progress' => $pl[0] ? round($pl[1] / $pl[0], 1) : null, 'lagging' => $pl[3],
                'results' => isset($results[$id]) ? $results[$id] : 0, 'devices' => $dv[0], 'repair' => $dv[1]);
        }
        $chart = array('groups' => array(), 'total' => 0, 'n' => 0, 'avg' => false);
        foreach ($rows as $r) {
            $chart['groups'][] = array('key' => 'd' . $r['id'], 'label' => $r['name'], 'value' => $r['people'], 'n' => $r['people']);
            $chart['total'] += $r['people'];
            $chart['n'] += $r['people'];
        }
        usort($chart['groups'], function ($a, $b) {
            return $b['value'] - $a['value'];
        });
        return array('rows' => $rows, 'chart' => $chart, 'devices' => class_exists('Omps\Modules\Device\Services\DeviceService'));
    }

    /** Lagging plans: the shared rule of the plan module (design §17). */
    private static function lagging(array $p, $now)
    {
        static $rows = null;
        if ($rows === null) {
            $rows = array();
            foreach (DB::select("SELECT id, start_date, status FROM plans WHERE deleted_at IS NULL") as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        if (!isset($rows[$p['id']])) {
            return false;
        }
        $r = $rows[$p['id']];
        return \Omps\Modules\Plan\Services\PlanService::lagging($p['level'], $r['status'], $r['start_date'], $p['end_date'], $p['_progress'], $now);
    }

    // ---------- custom pages (viewing) ----------

    public static function pages(Request $req)
    {
        $out = array();
        foreach (DB::table('stat_pages')->where('enabled', 1)->orderBy('sort')->orderBy('id')->get() as $p) {
            $out[] = array('id' => (int) $p['id'], 'title' => StatEngine::pageTitle($p['id']));
        }
        return array('pages' => $out);
    }

    public static function page(Request $req, $id, $all = false)
    {
        $p = DB::table('stat_pages')->find((int) $id);
        if (!$p || (!$all && (int) $p['enabled'] !== 1)) {
            throw HttpException::notFound();
        }
        $widgets = array();
        foreach (DB::table('stat_widgets')->where('page_id', (int) $p['id'])->orderBy('sort')->orderBy('id')->get() as $w) {
            $def = json_decode((string) $w['def'], true);
            try {
                $def = StatEngine::check(is_array($def) ? $def : array(), (string) $w['chart']);
                $widgets[] = self::widget($def, (string) $w['chart'], $req->user);
            } catch (ValidationException $e) {
                // a custom field used by this widget was removed or disabled
                $widgets[] = array('chart' => (string) $w['chart'], 'def' => $def, 'title' => __('stat.widget_broken'), 'result' => null);
            }
        }
        return array('id' => (int) $p['id'], 'title' => StatEngine::pageTitle($p['id']), 'widgets' => $widgets);
    }

    public static function preview(Request $req, array $in)
    {
        $chart = isset($in['chart']) ? (string) $in['chart'] : 'bar';
        $def = StatEngine::check(isset($in['def']) && is_array($in['def']) ? $in['def'] : array(), $chart);
        return self::widget($def, $chart, $req->user);
    }

    // ---------- export (Excel: one block per chart) ----------

    public static function export(Request $req, $which, $id)
    {
        if (!Permission::can($req->user, 'stat.export')) {
            throw HttpException::forbidden();
        }
        $sheet = array();
        if ($which === 'depts') {
            $d = self::depts($req);
            $title = __('menu.stat_depts');
            $header = array(__('stat.col.dept'), __('stat.col.leader'), __('stat.col.people'), __('stat.col.plans'), __('stat.col.progress'), __('stat.col.lagging'), __('stat.col.results'));
            if ($d['devices']) {
                $header[] = __('stat.col.devices');
                $header[] = __('stat.col.repair');
            }
            foreach ($d['rows'] as $r) {
                $line = array(str_repeat('  ', $r['depth']) . $r['name'], $r['leader'], $r['people'], $r['plans'], $r['progress'] === null ? '' : $r['progress'], $r['lagging'], $r['results']);
                if ($d['devices']) {
                    $line[] = $r['devices'];
                    $line[] = $r['repair'];
                }
                $sheet[] = $line;
            }
        } else {
            if ($which === 'people') {
                $data = self::people($req);
                $title = __('menu.stat_people');
                foreach ($data['kpis'] as $k) {
                    $sheet[] = array(__($k['k']), $k['v'] === null ? '' : $k['v']);
                }
                $sheet[] = array('', '');
            } else {
                $data = self::page($req, $id);
                $title = $data['title'];
            }
            foreach ($data['widgets'] as $w) {
                if ($w['result'] === null) {
                    continue;
                }
                $sheet[] = array($w['title'], '');
                foreach ($w['result']['groups'] as $g) {
                    $sheet[] = array($g['label'], $g['value']);
                }
                if (!$w['result']['groups']) {
                    $sheet[] = array(__('stat.total'), $w['result']['total']);
                }
                $sheet[] = array('', '');
            }
            $header = array(__('stat.col.item'), __('stat.col.value'));
        }
        Audit::op($req->user, 'stat', 'stat_exported', 'stat', null, $title, null, $req->ip());
        return new Response(Xlsx::write($header, $sheet, mb_substr($title, 0, 30)), 200, array('Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="export.xlsx"; filename*=UTF-8\'\'' . rawurlencode($title . '-' . date('Ymd') . '.xlsx')));
    }

    // ---------- designer (super admin) ----------

    private static function names($id)
    {
        $out = array();
        foreach (DB::table('translations')->where('namespace', 'stat')->where('tkey', 'page.' . (int) $id)->get() as $t) {
            $out[(string) $t['lang']] = (string) $t['text'];
        }
        return $out;
    }

    public static function designList()
    {
        $out = array();
        foreach (DB::table('stat_pages')->orderBy('sort')->orderBy('id')->get() as $p) {
            $out[] = array('id' => (int) $p['id'], 'title' => StatEngine::pageTitle($p['id']), 'names' => self::names($p['id']), 'enabled' => (int) $p['enabled'] === 1,
                'widgets' => (int) DB::value('SELECT COUNT(*) FROM stat_widgets WHERE page_id = ?', array((int) $p['id'])));
        }
        $langs = array();
        foreach (Lang::enabledCodes() as $c) {
            $langs[] = array('code' => $c, 'name' => \Omps\Modules\System\Services\LanguageService::name($c));
        }
        return array('pages' => $out, 'languages' => $langs, 'options' => StatEngine::options(), 'charts' => StatEngine::CHARTS);
    }

    public static function designShow($id)
    {
        $p = DB::table('stat_pages')->find((int) $id);
        if (!$p) {
            throw HttpException::notFound();
        }
        $widgets = array();
        foreach (DB::table('stat_widgets')->where('page_id', (int) $p['id'])->orderBy('sort')->orderBy('id')->get() as $w) {
            $widgets[] = array('chart' => (string) $w['chart'], 'def' => json_decode((string) $w['def'], true));
        }
        return array('id' => (int) $p['id'], 'names' => self::names($p['id']), 'enabled' => (int) $p['enabled'] === 1, 'widgets' => $widgets);
    }

    /** Save a page: names in every enabled language, widgets replaced as a whole. */
    public static function designSave(Request $req, $id, array $in)
    {
        $errors = array();
        $names = array();
        foreach (Lang::enabledCodes() as $code) {
            $v = isset($in['names'][$code]) ? trim((string) $in['names'][$code]) : '';
            if ($v === '' || mb_strlen($v) > 60) {
                $errors['name_' . $code] = __('stat.err.name', array('lang' => \Omps\Modules\System\Services\LanguageService::name($code)));
            }
            $names[$code] = $v;
        }
        $widgets = array();
        $list = isset($in['widgets']) && is_array($in['widgets']) ? array_values($in['widgets']) : array();
        if (count($list) > 20) {
            $errors['widgets'] = __('stat.err.too_many');
        }
        foreach ($list as $i => $w) {
            try {
                $chart = isset($w['chart']) ? (string) $w['chart'] : '';
                $widgets[] = array($chart, StatEngine::check(isset($w['def']) && is_array($w['def']) ? $w['def'] : array(), $chart));
            } catch (ValidationException $e) {
                $errors['widgets'] = __('stat.err.widget', array('n' => $i + 1));
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $enabled = !isset($in['enabled']) || !empty($in['enabled']) ? 1 : 0;
        $uid = $req->userId();
        $existing = $id ? DB::table('stat_pages')->find((int) $id) : null;
        if ($id && !$existing) {
            throw HttpException::notFound();
        }
        $pid = DB::transaction(function () use ($existing, $names, $widgets, $enabled, $uid) {
            if ($existing) {
                $pid = (int) $existing['id'];
                DB::table('stat_pages')->where('id', $pid)->update(array('enabled' => $enabled, 'updated_at' => now(), 'updated_by' => $uid));
                DB::table('stat_widgets')->where('page_id', $pid)->delete();
            } else {
                $pid = DB::table('stat_pages')->insert(array('enabled' => $enabled, 'sort' => (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM stat_pages'),
                    'created_at' => now(), 'created_by' => $uid));
            }
            foreach ($names as $lang => $text) {
                DB::table('translations')->where('namespace', 'stat')->where('tkey', 'page.' . $pid)->where('lang', $lang)->delete();
                DB::table('translations')->insert(array('namespace' => 'stat', 'tkey' => 'page.' . $pid, 'lang' => $lang, 'text' => $text, 'updated_at' => now(), 'updated_by' => $uid));
            }
            foreach ($widgets as $i => $w) {
                DB::table('stat_widgets')->insert(array('page_id' => $pid, 'chart' => $w[0], 'def' => json_encode($w[1]), 'sort' => ($i + 1) * 10));
            }
            return $pid;
        });
        Lang::clearCache();
        Audit::change($req, 'custom', $existing ? 'stat_page_updated' : 'stat_page_created', 'stat_page', $pid, implode(' / ', $names), null, array('widgets' => count($widgets), 'enabled' => $enabled));
        return array('id' => $pid);
    }

    public static function designDelete(Request $req, $id)
    {
        $p = DB::table('stat_pages')->find((int) $id);
        if (!$p) {
            throw HttpException::notFound();
        }
        $title = StatEngine::pageTitle($p['id']);
        DB::transaction(function () use ($p) {
            DB::table('stat_pages')->where('id', (int) $p['id'])->delete();
            DB::table('translations')->where('namespace', 'stat')->where('tkey', 'page.' . (int) $p['id'])->delete();
        });
        Lang::clearCache();
        Audit::change($req, 'custom', 'stat_page_deleted', 'stat_page', (int) $p['id'], $title, null, null);
        return array('ok' => true);
    }
}
