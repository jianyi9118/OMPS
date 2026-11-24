<?php
namespace Omps\Modules\Personnel\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Core\Xlsx;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;
use Omps\Modules\Organization\Services\StaffService;

/**
 * Staff import (Excel .xlsx or CSV, check first, then import) and export (.xlsx, ID numbers masked).
 * Headers can be in any enabled language; departments are matched by name or path "技术部/软件组".
 */
class ImportExport
{
    const COLUMNS = array('staff_no', 'name', 'gender', 'dept', 'position', 'phone', 'hire_date', 'birth_date', 'ethnicity', 'id_type', 'id_number', 'email');
    const REQUIRED = array('name', 'gender', 'dept');

    private static function labelKey($col)
    {
        return 'staff.field.' . $col;
    }

    public static function template(Request $req)
    {
        self::needImport($req);
        $header = array();
        foreach (self::COLUMNS as $c) {
            $header[] = __(self::labelKey($c)) . (in_array($c, self::REQUIRED, true) ? '*' : '');
        }
        $sample = array(array('', __('import.sample_name'), __('gender.male'), __('import.sample_dept'), __('import.sample_position'), '13800000000', '2024-03-01', '1995-06-18', '', __('dict.id_type.resident_id'), '', ''));
        return self::xlsxResponse(Xlsx::write($header, $sample, __('menu.staff')), __('import.template_file') . '.xlsx');
    }

    private static function xlsxResponse($bytes, $name)
    {
        $r = new Response($bytes, 200, array(
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="export.xlsx"; filename*=UTF-8\'\'' . rawurlencode($name),
        ));
        return $r;
    }

    private static function needImport(Request $req)
    {
        $caps = StaffService::caps($req->user);
        if (!$caps['add']) {
            throw HttpException::forbidden();
        }
    }

    /** Map header texts (any language) to column keys. */
    private static function mapHeader(array $header)
    {
        $names = array();
        foreach (Lang::enabledCodes() as $code) {
            foreach (self::COLUMNS as $c) {
                $names[mb_strtolower(Lang::get(self::labelKey($c), array(), $code))] = $c;
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

    /** A dictionary value given as code or as its text in any enabled language. */
    private static function dictValue($dict, $text)
    {
        $t = mb_strtolower(trim((string) $text));
        if ($t === '') {
            return '';
        }
        $base = require OMPS_ROOT . '/database/seeds/base.php';
        $alias = array('gender' => array('m' => 'male', 'f' => 'female'));
        if (isset($alias[$dict][$t])) {
            return $alias[$dict][$t];
        }
        foreach ($base['dictionaries'][$dict] as $code) {
            if ($t === $code) {
                return $code;
            }
            foreach (Lang::enabledCodes() as $lc) {
                if ($t === mb_strtolower(Lang::get('dict.' . $dict . '.' . $code, array(), $lc))) {
                    return $code;
                }
            }
        }
        return null;
    }

    private static function normDate($v)
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '';
        }
        // 2024-03-01, 2024/3/1, 2024.3.1 and the Chinese form (year / month / day characters)
        if (preg_match('/^(\d{4})[\/.\-\x{5E74}](\d{1,2})[\/.\-\x{6708}](\d{1,2})\x{65E5}?$/u', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        return $v;
    }

    /** Departments in scope by name and by path. */
    private static function deptIndex(Request $req)
    {
        $byName = array();
        $byPath = array();
        $all = DB::table('departments')->active()->get();
        $names = array();
        foreach ($all as $d) {
            $names[(int) $d['id']] = (string) $d['name'];
        }
        $allowed = Permission::deptIds($req->user);
        foreach ($all as $d) {
            $id = (int) $d['id'];
            if ($allowed !== null && !in_array($id, $allowed, true)) {
                continue;
            }
            $byName[(string) $d['name']][] = $id;
            $parts = array();
            foreach (array_filter(explode('/', trim((string) $d['path'], '/'))) as $pid) {
                $parts[] = isset($names[(int) $pid]) ? $names[(int) $pid] : '';
            }
            $parts[] = (string) $d['name'];
            $byPath[implode('/', $parts)] = $id;
        }
        return array($byName, $byPath);
    }

    /**
     * Check every row. @return array ['rows' => [[line, values, errors]], 'ok' => n, 'bad' => n]
     */
    public static function check(Request $req, $path, $ext)
    {
        self::needImport($req);
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
                throw ValidationException::field('file', 'import.err.missing_column', array('column' => __(self::labelKey($r))));
            }
        }
        if (count($table) - 1 > 2000) {
            throw ValidationException::field('file', 'import.err.too_many', array('max' => 2000));
        }
        list($byName, $byPath) = self::deptIndex($req);
        $seenNo = array();
        $seenId = array();
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
            $clean = array();
            $g = self::dictValue('gender', isset($vals['gender']) ? $vals['gender'] : '');
            if ($g === null || $g === '') {
                $errors[] = __('import.err.gender');
            } else {
                $clean['gender'] = $g;
            }
            $dname = isset($vals['dept']) ? trim($vals['dept'], '/ ') : '';
            if ($dname === '') {
                $errors[] = __('validation.required', array('field' => __('staff.field.dept')));
            } elseif (isset($byPath[$dname])) {
                $clean['dept_id'] = $byPath[$dname];
            } elseif (isset($byName[$dname]) && count($byName[$dname]) === 1) {
                $clean['dept_id'] = $byName[$dname][0];
            } elseif (isset($byName[$dname])) {
                $errors[] = __('import.err.dept_ambiguous', array('name' => $dname));
            } else {
                $errors[] = __('import.err.dept_unknown', array('name' => $dname));
            }
            if (isset($vals['id_type']) && $vals['id_type'] !== '') {
                $t = self::dictValue('id_type', $vals['id_type']);
                if ($t === null) {
                    $errors[] = __('validation.in', array('field' => __('staff.field.id_type')));
                } else {
                    $clean['id_type'] = $t;
                }
            }
            foreach (array('hire_date', 'birth_date') as $dk) {
                if (isset($vals[$dk])) {
                    $vals[$dk] = self::normDate($vals[$dk]);
                }
            }
            try {
                $v = Validator::check($vals, array('staff_no' => 'nullable|code', 'name' => 'required|string|max:50', 'position' => 'nullable|string|max:100',
                    'phone' => array('nullable', 'string', 'regex:/^[0-9+\- ]{5,30}$/'), 'hire_date' => 'nullable|date', 'birth_date' => 'nullable|date',
                    'ethnicity' => 'nullable|string|max:30', 'email' => 'nullable|email|max:100', 'id_number' => 'nullable|string|max:30'),
                    array('staff_no' => 'staff.field.staff_no', 'name' => 'staff.field.name', 'position' => 'staff.field.position', 'phone' => 'staff.field.phone',
                        'hire_date' => 'staff.field.hire_date', 'birth_date' => 'staff.field.birth_date', 'ethnicity' => 'staff.field.ethnicity', 'email' => 'staff.field.email', 'id_number' => 'staff.field.id_number'));
                $clean = array_merge($clean, $v);
            } catch (ValidationException $e) {
                foreach ($e->errors as $m) {
                    $errors[] = $m;
                }
            }
            if (!empty($clean['staff_no'])) {
                if (isset($seenNo[$clean['staff_no']]) || DB::table('personnel_profiles')->where('staff_no', $clean['staff_no'])->exists()) {
                    $errors[] = __('staff.err.no_taken');
                }
                $seenNo[$clean['staff_no']] = true;
            }
            if (!empty($clean['id_number'])) {
                try {
                    $parts = PersonnelService::idParts(isset($clean['id_type']) ? $clean['id_type'] : 'resident_id', $clean['id_number'], 0);
                    if (isset($seenId[$parts['id_number_hash']])) {
                        $errors[] = __('staff.err.id_taken');
                    }
                    $seenId[$parts['id_number_hash']] = true;
                } catch (ValidationException $e) {
                    foreach ($e->errors as $m) {
                        $errors[] = $m;
                    }
                }
            }
            if (!$errors) {
                $ok++;
            }
            $show = $vals;
            if (isset($show['id_number']) && $show['id_number'] !== '') {
                $show['id_number'] = \Omps\Core\Crypto::mask($show['id_number']);
            }
            $out[] = array('line' => $i + 2, 'values' => $show, 'errors' => $errors, 'clean' => $clean);
        }
        return array('rows' => $out, 'ok' => $ok, 'bad' => count($out) - $ok, 'columns' => array_values(array_unique($map)));
    }

    /** Keep the uploaded table in storage/temp and return a token for the import step. */
    public static function stash(array $up)
    {
        $ext = strtolower((string) pathinfo((string) $up['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('xlsx', 'csv'), true)) {
            throw ValidationException::field('file', 'import.err.type');
        }
        if ((int) $up['error'] !== UPLOAD_ERR_OK || (int) $up['size'] > 10 * 1048576) {
            throw ValidationException::field('file', 'file.err.upload_failed', array('name' => $up['name']));
        }
        $token = bin2hex(random_bytes(16));
        $dir = storage_path('temp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $target = $dir . '/import-' . $token . '.' . $ext;
        $ok = is_uploaded_file($up['tmp_name']) ? move_uploaded_file($up['tmp_name'], $target) : ((string) config('app.env') === 'testing' && copy($up['tmp_name'], $target));
        if (!$ok) {
            throw new \RuntimeException('Cannot keep the uploaded file');
        }
        return array($token, $target, $ext);
    }

    public static function stashed($token)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $token)) {
            throw HttpException::bad('import.err.expired');
        }
        foreach (array('xlsx', 'csv') as $ext) {
            $p = storage_path('temp') . '/import-' . $token . '.' . $ext;
            if (is_file($p) && filemtime($p) > time() - 3600) {
                return array($p, $ext);
            }
        }
        throw HttpException::bad('import.err.expired');
    }

    /** Import the rows that passed the check (rows with problems are skipped and listed). */
    public static function import(Request $req, $token)
    {
        list($path, $ext) = self::stashed($token);
        $res = self::check($req, $path, $ext);
        $created = 0;
        $uid = $req->userId();
        DB::transaction(function () use ($res, $uid, &$created) {
            $n = (int) DB::value('SELECT COUNT(*) FROM personnel_profiles') + 1;
            foreach ($res['rows'] as $r) {
                if ($r['errors']) {
                    continue;
                }
                $c = $r['clean'];
                $no = !empty($c['staff_no']) ? $c['staff_no'] : '';
                while ($no === '' || DB::table('personnel_profiles')->where('staff_no', $no)->exists()) {
                    $no = 'E' . str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
                }
                $cols = array('org_id' => (int) config('app.org_id', 1), 'staff_no' => $no, 'name' => $c['name'], 'gender' => $c['gender'], 'dept_id' => $c['dept_id'],
                    'position' => self::nz($c, 'position'), 'phone' => self::nz($c, 'phone'), 'hire_date' => self::nz($c, 'hire_date'), 'birth_date' => self::nz($c, 'birth_date'),
                    'ethnicity' => self::nz($c, 'ethnicity'), 'email' => self::nz($c, 'email'), 'id_type' => self::nz($c, 'id_type'),
                    'status' => 'active', 'created_at' => now(), 'created_by' => $uid);
                if (!empty($c['id_number'])) {
                    $cols = array_merge($cols, PersonnelService::idParts(isset($c['id_type']) ? $c['id_type'] : 'resident_id', $c['id_number'], 0));
                }
                $pid = DB::table('personnel_profiles')->insert($cols);
                DB::table('personnel_assignments')->insert(array('org_id' => (int) config('app.org_id', 1), 'person_id' => $pid, 'dept_id' => $c['dept_id'],
                    'position' => $cols['position'], 'change_type' => 'join', 'start_date' => $cols['hire_date'] !== null ? $cols['hire_date'] : date('Y-m-d'),
                    'note' => 'import', 'created_at' => now(), 'created_by' => $uid));
                $created++;
            }
        });
        @unlink($path);
        Audit::change($req, 'staff', 'staff_imported', 'person', null, null, null, array('created' => $created, 'skipped' => $res['bad']));
        return array('created' => $created, 'skipped' => $res['bad']);
    }

    private static function nz(array $c, $k)
    {
        return isset($c[$k]) && $c[$k] !== '' ? $c[$k] : null;
    }

    public static function export(Request $req, array $f)
    {
        if (!Permission::can($req->user, 'staff.export')) {
            throw HttpException::forbidden();
        }
        $q = DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()
            ->select(array('p.*', 'd.name as dept_name'));
        Permission::scopePersonnel($q, $req->user, 'p');
        if (!empty($f['dept_id'])) {
            $q->whereIn('p.dept_id', Permission::subtree((int) $f['dept_id']));
        }
        if (isset($f['status']) && $f['status'] !== '') {
            $q->where('p.status', $f['status']);
        }
        // the same filters as the staff list, so the file holds what the list shows
        \Omps\Modules\Organization\Services\StaffService::filter($q, $f);
        $cols = array_merge(self::COLUMNS, array('status'));
        $header = array();
        foreach ($cols as $c) {
            $header[] = __($c === 'status' ? 'staff.field.status' : self::labelKey($c));
        }
        $cfDefs = \Omps\Engines\CustomFields::defs('personnel');
        foreach ($cfDefs as $d) {
            $header[] = $d['label'];
        }
        $list = $q->orderBy('p.staff_no')->get();
        $cfVals = $cfDefs ? \Omps\Engines\CustomFields::values('personnel', array_column($list, 'id')) : array();
        $rows = array();
        foreach ($list as $r) {
            $rows[] = array($r['staff_no'], $r['name'], __('gender.' . $r['gender']), str_or_empty($r['dept_name']), str_or_empty($r['position']), str_or_empty($r['phone']),
                str_or_empty($r['hire_date']), str_or_empty($r['birth_date']), str_or_empty($r['ethnicity']),
                $r['id_type'] !== null && $r['id_type'] !== '' ? __('dict.id_type.' . $r['id_type']) : '', str_or_empty($r['id_number_mask']), str_or_empty($r['email']),
                __('staff.status.' . $r['status']));
            foreach ($cfDefs as $d) {
                $rows[count($rows) - 1][] = \Omps\Engines\CustomFields::text($d, isset($cfVals[(int) $r['id']][$d['key']]) ? $cfVals[(int) $r['id']][$d['key']] : null);
            }
        }
        Audit::change($req, 'staff', 'staff_exported', 'person', null, null, null, array('rows' => count($rows), 'filter' => $f));
        return self::xlsxResponse(Xlsx::write($header, $rows, __('menu.staff')), __('menu.staff') . '-' . date('Ymd') . '.xlsx');
    }
}
