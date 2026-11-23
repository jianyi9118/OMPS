<?php
namespace Omps\Modules\Device\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\ValidationException;
use Omps\Core\Xlsx;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;
use Omps\Modules\Personnel\Services\ImportExport;

/**
 * Device import (Excel .xlsx or CSV: check first, then import) and export.
 * Headers may be in any enabled language; category by code or name in any language; department by
 * name or path "技术部/软件组"; holder by name or staff number.
 */
class DeviceImport
{
    const COLUMNS = array('asset_no', 'name', 'category', 'brand', 'model', 'serial_no', 'dept', 'holder', 'location', 'purchase_date', 'received_date', 'price', 'warranty_until', 'remark');
    const REQUIRED = array('asset_no', 'name', 'category');

    private static function label($c)
    {
        return 'device.field.' . $c;
    }

    private static function need(Request $req, $action)
    {
        if (!Permission::can($req->user, 'device.' . $action)) {
            throw HttpException::forbidden();
        }
    }

    private static function xlsx($bytes, $name)
    {
        return new Response($bytes, 200, array('Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="export.xlsx"; filename*=UTF-8\'\'' . rawurlencode($name)));
    }

    public static function template(Request $req)
    {
        self::need($req, 'add');
        $header = array();
        foreach (self::COLUMNS as $c) {
            $header[] = __(self::label($c)) . (in_array($c, self::REQUIRED, true) ? '*' : '');
        }
        $sample = array(array('PC-0001', __('device.sample_name'), __('dict.device_category.pc'), 'Lenovo', 'M720t', 'PF1ABCDE', __('import.sample_dept'), '', __('device.sample_location'),
            '2024-03-01', '2024-03-05', '4500', '2027-03-01', ''));
        return self::xlsx(Xlsx::write($header, $sample, __('menu.dev_archive')), __('device.template_file') . '.xlsx');
    }

    private static function mapHeader(array $header)
    {
        $names = array();
        foreach (Lang::enabledCodes() as $code) {
            foreach (self::COLUMNS as $c) {
                $names[mb_strtolower(Lang::get(self::label($c), array(), $code))] = $c;
            }
        }
        foreach (self::COLUMNS as $c) {
            $names[$c] = $c;
        }
        $map = array();
        foreach ($header as $i => $h) {
            $k = mb_strtolower(trim(str_replace(array('*', "\u{FF0A}"), '', (string) $h)));
            if (isset($names[$k])) {
                $map[$i] = $names[$k];
            }
        }
        return $map;
    }

    private static function category($text)
    {
        $t = mb_strtolower(trim((string) $text));
        foreach (DeviceService::CATEGORIES as $code) {
            if ($t === $code) {
                return $code;
            }
            foreach (Lang::enabledCodes() as $lc) {
                if ($t === mb_strtolower(Lang::get('dict.device_category.' . $code, array(), $lc))) {
                    return $code;
                }
            }
        }
        return null;
    }

    private static function date($v)
    {
        $v = trim((string) $v);
        if (preg_match('/^(\d{4})[\/.\-\x{5E74}](\d{1,2})[\/.\-\x{6708}](\d{1,2})\x{65E5}?$/u', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        return $v;
    }

    private static function indexes()
    {
        $names = array();
        $byName = array();
        $byPath = array();
        $all = DB::table('departments')->active()->get();
        foreach ($all as $d) {
            $names[(int) $d['id']] = (string) $d['name'];
        }
        foreach ($all as $d) {
            $byName[(string) $d['name']][] = (int) $d['id'];
            $parts = array();
            foreach (array_filter(explode('/', trim((string) $d['path'], '/'))) as $pid) {
                $parts[] = isset($names[(int) $pid]) ? $names[(int) $pid] : '';
            }
            $parts[] = (string) $d['name'];
            $byPath[implode('/', $parts)] = (int) $d['id'];
        }
        $people = array();
        $byNo = array();
        foreach (DB::table('personnel_profiles')->active()->where('status', 'active')->get() as $p) {
            $people[(string) $p['name']][] = array((int) $p['id'], int_or_null($p['dept_id']));
            $byNo[(string) $p['staff_no']] = array((int) $p['id'], int_or_null($p['dept_id']));
        }
        return array($byName, $byPath, $people, $byNo);
    }

    public static function check(Request $req, $path, $ext)
    {
        self::need($req, 'add');
        try {
            $table = Xlsx::readTable($path, $ext);
        } catch (\Exception $e) {
            throw ValidationException::field('file', 'import.err.unreadable');
        }
        if (count($table) < 2) {
            throw ValidationException::field('file', 'import.err.empty');
        }
        $map = self::mapHeader($table[0]);
        foreach (self::REQUIRED as $r) {
            if (!in_array($r, $map, true)) {
                throw ValidationException::field('file', 'import.err.missing_column', array('column' => __(self::label($r))));
            }
        }
        if (count($table) - 1 > 5000) {
            throw ValidationException::field('file', 'import.err.too_many', array('max' => 5000));
        }
        list($byName, $byPath, $people, $byNo) = self::indexes();
        $seen = array();
        $out = array();
        $ok = 0;
        foreach (array_slice($table, 1) as $i => $line) {
            $vals = array();
            foreach ($map as $ci => $key) {
                $vals[$key] = isset($line[$ci]) ? trim((string) $line[$ci]) : '';
            }
            if (implode('', $vals) === '') {
                continue;
            }
            $errors = array();
            $in = $vals;
            unset($in['dept'], $in['holder']);
            $cat = self::category(isset($vals['category']) ? $vals['category'] : '');
            if ($cat === null) {
                $errors[] = __('device.err.category');
            } else {
                $in['category'] = $cat;
            }
            foreach (array('purchase_date', 'received_date', 'warranty_until') as $k) {
                if (isset($in[$k])) {
                    $in[$k] = self::date($in[$k]);
                }
            }
            $dname = isset($vals['dept']) ? trim($vals['dept'], '/ ') : '';
            if ($dname !== '') {
                if (isset($byPath[$dname])) {
                    $in['dept_id'] = $byPath[$dname];
                } elseif (isset($byName[$dname]) && count($byName[$dname]) === 1) {
                    $in['dept_id'] = $byName[$dname][0];
                } elseif (isset($byName[$dname])) {
                    $errors[] = __('import.err.dept_ambiguous', array('name' => $dname));
                } else {
                    $errors[] = __('import.err.dept_unknown', array('name' => $dname));
                }
            }
            $h = isset($vals['holder']) ? $vals['holder'] : '';
            if ($h !== '') {
                if (isset($byNo[$h])) {
                    $p = $byNo[$h];
                } elseif (isset($people[$h]) && count($people[$h]) === 1) {
                    $p = $people[$h][0];
                } else {
                    $p = null;
                    $errors[] = __(isset($people[$h]) ? 'device.err.holder_ambiguous' : 'device.err.holder_unknown', array('name' => $h));
                }
                if ($p) {
                    $in['holder_person_id'] = $p[0];
                    if (empty($in['dept_id']) && $p[1] !== null) {
                        $in['dept_id'] = $p[1];
                    }
                }
            }
            $clean = null;
            try {
                if ($cat !== null) {
                    list($clean) = DeviceService::clean($in);
                }
            } catch (ValidationException $e) {
                foreach ($e->errors as $m) {
                    $errors[] = $m;
                }
            }
            $no = isset($vals['asset_no']) ? $vals['asset_no'] : '';
            if ($no !== '') {
                if (isset($seen[$no])) {
                    $errors[] = __('device.err.asset_taken');
                }
                $seen[$no] = true;
            }
            if (!$errors) {
                $ok++;
            }
            $out[] = array('line' => $i + 2, 'values' => $vals, 'errors' => array_values(array_unique($errors)), 'clean' => $clean);
        }
        return array('rows' => $out, 'ok' => $ok, 'bad' => count($out) - $ok, 'columns' => array_values(array_unique($map)));
    }

    public static function upload(Request $req)
    {
        self::need($req, 'add');
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        list($token, $path, $ext) = ImportExport::stash($list[0]);
        $res = self::check($req, $path, $ext);
        foreach ($res['rows'] as $i => $r) {
            unset($res['rows'][$i]['clean']);
        }
        $res['token'] = $token;
        return $res;
    }

    public static function run(Request $req, $token)
    {
        list($path, $ext) = ImportExport::stashed($token);
        $res = self::check($req, $path, $ext);
        $uid = $req->userId();
        $created = 0;
        DB::transaction(function () use ($res, $uid, &$created) {
            foreach ($res['rows'] as $r) {
                if ($r['errors'] || !$r['clean']) {
                    continue;
                }
                $c = $r['clean'];
                $id = DB::table('devices')->insert(array_merge($c, array('org_id' => (int) config('app.org_id', 1), 'run_status' => 'normal', 'created_at' => now(), 'created_by' => $uid)));
                if ($c['holder_person_id'] !== null || $c['dept_id'] !== null) {
                    DeviceService::changeHolder($id, $c['holder_person_id'], $c['dept_id'], $c['received_date'] !== null ? $c['received_date'] : date('Y-m-d'), null, $uid);
                }
                $created++;
            }
        });
        @unlink($path);
        Audit::change($req, 'device', 'device_imported', 'device', null, null, null, array('created' => $created, 'skipped' => $res['bad']));
        return array('created' => $created, 'skipped' => $res['bad']);
    }

    public static function export(Request $req, array $f)
    {
        self::need($req, 'export');
        $res = DeviceService::search($req, array_merge($f, array('all_custom' => 1)), 1, 100000);
        $header = array();
        foreach (array_merge(self::COLUMNS, array('run_status')) as $c) {
            $header[] = __(self::label($c));
        }
        $cfDefs = \Omps\Engines\CustomFields::defs('device');
        foreach ($cfDefs as $cd) {
            $header[] = $cd['label'];
        }
        $rows = array();
        foreach ($res['rows'] as $d) {
            $rows[] = array($d['asset_no'], $d['name'], __('dict.device_category.' . $d['category']), $d['brand'], $d['model'], $d['serial_no'], $d['dept'], $d['holder'], $d['location'],
                $d['purchase_date'], $d['received_date'], $d['price'], $d['warranty_until'], $d['remark'], __('device.status.' . $d['run_status']));
            foreach ($cfDefs as $cd) {
                $cv = isset($d['custom']) ? (array) $d['custom'] : array();
                $rows[count($rows) - 1][] = \Omps\Engines\CustomFields::text($cd, isset($cv[$cd['key']]) ? $cv[$cd['key']] : null);
            }
        }
        Audit::change($req, 'device', 'device_exported', 'device', null, null, null, array('rows' => count($rows)));
        return self::xlsx(Xlsx::write($header, $rows, __('menu.dev_archive')), __('menu.dev_archive') . '-' . date('Ymd') . '.xlsx');
    }
}
