<?php
namespace Omps\Modules\Device\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;
use Omps\Engines\Permission;

/**
 * Device records (design §7.5): basic data, holder history, parts with history, licences, files.
 *
 *  - Device admins (兼任设备管理员), whole-unit roles and the super admin see every device. A custom
 *    role with device.view sees its department's devices (dept scope) or the devices it holds (self).
 *  - Replacing a part closes the old row (removed_on) and adds a new one with a history row; nothing
 *    is overwritten.
 *  - Running status fault / repairing is set by the repair flow; the form only sets normal / disabled /
 *    scrapped.
 */
class DeviceService
{
    const CATEGORIES = array('pc', 'laptop', 'server', 'printer', 'scanner', 'network', 'monitor', 'projector', 'phone', 'other');
    const STATUSES = array('normal', 'fault', 'repairing', 'disabled', 'scrapped');
    const MANUAL_STATUSES = array('normal', 'disabled', 'scrapped');
    const CTYPES = array('cpu', 'memory', 'disk', 'board', 'monitor', 'nic', 'gpu', 'other');

    /** Sees all devices? */
    public static function wide($user)
    {
        $s = Permission::scope($user);
        return Permission::isSuper($user) || !empty($user['is_device_admin']) || $s === 'unit' || $s === 'all';
    }

    public static function scopeQuery($q, $user, $alias = 'd')
    {
        if (self::wide($user)) {
            return $q;
        }
        if (Permission::scope($user) === 'dept') {
            return $q->whereIn($alias . '.dept_id', Permission::deptIds($user));
        }
        return $q->where($alias . '.holder_person_id', $user['person_id'] === null ? 0 : (int) $user['person_id']);
    }

    private static function need(Request $req, $action)
    {
        if (!Permission::can($req->user, 'device.' . $action)) {
            Audit::denied($req, 'permission', array('perm' => 'device.' . $action));
            throw HttpException::forbidden();
        }
    }

    /** A device the user may see, else 404 (logged). */
    public static function find(Request $req, $id, $action = 'view')
    {
        self::need($req, $action);
        $q = DB::table('devices d')->active()->where('d.id', (int) $id);
        self::scopeQuery($q, $req->user, 'd');
        $d = $q->first();
        if (!$d) {
            Audit::denied($req, 'device', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        return $d;
    }

    private static function base()
    {
        return DB::table('devices d')->leftJoin('departments dp', 'dp.id', '=', 'd.dept_id')->leftJoin('personnel_profiles hp', 'hp.id', '=', 'd.holder_person_id')
            ->active()->select(array('d.*', 'dp.name as dept_name', 'hp.name as holder_name'));
    }

    public static function present(array $d)
    {
        return array(
            'id' => (int) $d['id'], 'asset_no' => (string) $d['asset_no'], 'name' => (string) $d['name'], 'category' => (string) $d['category'],
            'brand' => str_or_empty($d['brand']), 'model' => str_or_empty($d['model']), 'serial_no' => str_or_empty($d['serial_no']),
            'dept_id' => int_or_null($d['dept_id']), 'dept' => isset($d['dept_name']) ? str_or_empty($d['dept_name']) : '',
            'holder_person_id' => int_or_null($d['holder_person_id']), 'holder' => isset($d['holder_name']) ? str_or_empty($d['holder_name']) : '',
            'location' => str_or_empty($d['location']), 'purchase_date' => str_or_empty($d['purchase_date']), 'received_date' => str_or_empty($d['received_date']),
            'price' => $d['price'] === null ? '' : (string) $d['price'], 'warranty_until' => str_or_empty($d['warranty_until']),
            'run_status' => (string) $d['run_status'], 'remark' => str_or_empty($d['remark']), 'version' => (int) $d['version'],
        );
    }

    public static function caps($user)
    {
        return array('view' => Permission::can($user, 'device.view'), 'add' => Permission::can($user, 'device.add'), 'edit' => Permission::can($user, 'device.edit'),
            'del' => Permission::can($user, 'device.del'), 'export' => Permission::can($user, 'device.export'), 'wide' => self::wide($user));
    }

    public static function meta(Request $req)
    {
        self::need($req, 'view');
        $depts = array();
        foreach (DB::table('departments')->active()->orderBy('path')->orderBy('sort')->get() as $d) {
            $depts[] = array('id' => (int) $d['id'], 'name' => (string) $d['name'], 'depth' => max(0, substr_count((string) $d['path'], '/') - 1));
        }
        $people = array();
        foreach (DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()->where('p.status', 'active')
            ->select(array('p.id', 'p.name', 'p.staff_no', 'd.name as dept_name'))->orderBy('p.name')->get() as $p) {
            $people[] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'staff_no' => (string) $p['staff_no'], 'dept' => str_or_empty($p['dept_name']));
        }
        return array('categories' => self::CATEGORIES, 'statuses' => self::STATUSES, 'manual_statuses' => self::MANUAL_STATUSES, 'ctypes' => self::CTYPES,
            'depts' => $depts, 'people' => $people, 'caps' => self::caps($req->user), 'scope_items' => CollectorService::scope(), 'custom' => \Omps\Engines\CustomFields::defs('device'));
    }

    public static function search(Request $req, array $f, $page, $size)
    {
        self::need($req, 'view');
        $q = self::base();
        self::scopeQuery($q, $req->user, 'd');
        if (!empty($f['kw'])) {
            $q->whereLikeAnyOrIn(array('d.asset_no', 'd.name', 'd.model', 'd.serial_no', 'd.location', 'hp.name'), $f['kw'], 'd.id', \Omps\Engines\CustomFields::matchIds('device', $f['kw']));
        }
        if (!empty($f['category'])) {
            $q->where('d.category', $f['category']);
        }
        if (!empty($f['status'])) {
            $q->where('d.run_status', $f['status']);
        }
        if (!empty($f['dept_id'])) {
            $q->whereIn('d.dept_id', Permission::subtree((int) $f['dept_id']));
        }
        $res = $q->orderBy('d.asset_no')->paginate($page, $size);
        $ids = array();
        foreach ($res['rows'] as $r) {
            $ids[] = (int) $r['id'];
        }
        $seen = array();
        if ($ids) {
            foreach (DB::table('device_collectors')->whereIn('device_id', $ids)->where('status', 'active')->get() as $c) {
                $k = (int) $c['device_id'];
                if (!isset($seen[$k]) || (string) $c['last_seen'] > $seen[$k]) {
                    $seen[$k] = str_or_empty($c['last_seen']);
                }
            }
        }
        $rows = array();
        $res['custom_cols'] = \Omps\Engines\CustomFields::listDefs('device');
        $cfAll = isset($f['all_custom']) ? \Omps\Engines\CustomFields::defs('device') : $res['custom_cols'];
        $cfVals = $cfAll ? \Omps\Engines\CustomFields::values('device', $ids) : array();
        foreach ($res['rows'] as $r) {
            $x = self::present($r);
            $x['last_seen'] = isset($seen[$x['id']]) ? $seen[$x['id']] : null;
            if ($cfAll) {
                $x['custom'] = isset($cfVals[$x['id']]) ? $cfVals[$x['id']] : array();
            }
            $rows[] = $x;
        }
        $res['rows'] = $rows;
        return $res;
    }

    /** Full record: parts (in use and removed), history, licences, holders, faults with repairs, files, collectors. */
    public static function detail(Request $req, $id)
    {
        $d = self::find($req, $id);
        $row = self::base()->where('d.id', (int) $d['id'])->first();
        $out = self::present($row);
        $comps = array();
        foreach (DB::table('device_components')->where('device_id', (int) $d['id'])->orderBy('removed_on')->orderBy('ctype')->orderBy('id')->get() as $c) {
            $comps[] = array('id' => (int) $c['id'], 'ctype' => (string) $c['ctype'], 'model' => str_or_empty($c['model']), 'serial_no' => str_or_empty($c['serial_no']),
                'spec' => str_or_empty($c['spec']), 'installed_on' => str_or_empty($c['installed_on']), 'removed_on' => str_or_empty($c['removed_on']), 'source' => (string) $c['source']);
        }
        $hist = array();
        foreach (DB::table('device_component_history h')->join('device_components c', 'c.id', '=', 'h.component_id')->leftJoin('device_components o', 'o.id', '=', 'h.replaced_component_id')
            ->leftJoin('users u', 'u.id', '=', 'h.created_by')->where('h.device_id', (int) $d['id'])
            ->select(array('h.*', 'c.ctype', 'c.model', 'c.serial_no', 'o.model as old_model', 'o.serial_no as old_serial', 'u.display_name as by_name'))
            ->orderBy('h.happened_at', 'DESC')->orderBy('h.id', 'DESC')->limit(200)->get() as $h) {
            $hist[] = array('action' => (string) $h['action'], 'ctype' => (string) $h['ctype'], 'model' => str_or_empty($h['model']), 'serial_no' => str_or_empty($h['serial_no']),
                'old_model' => str_or_empty($h['old_model']), 'old_serial' => str_or_empty($h['old_serial']), 'at' => (string) $h['happened_at'], 'note' => str_or_empty($h['note']),
                'by' => str_or_empty($h['by_name']), 'repair_id' => int_or_null($h['repair_id']), 'anomaly_id' => int_or_null($h['anomaly_id']));
        }
        $lic = array();
        foreach (DB::table('device_licenses')->where('device_id', (int) $d['id'])->orderBy('expires_on')->orderBy('id')->get() as $l) {
            $lic[] = array('id' => (int) $l['id'], 'name' => (string) $l['name'], 'license_no' => str_or_empty($l['license_no']), 'seats' => $l['seats'] === null ? null : (int) $l['seats'],
                'expires_on' => str_or_empty($l['expires_on']), 'remark' => str_or_empty($l['remark']));
        }
        $holders = array();
        foreach (DB::table('device_holders h')->leftJoin('personnel_profiles p', 'p.id', '=', 'h.person_id')->leftJoin('departments dp', 'dp.id', '=', 'h.dept_id')
            ->where('h.device_id', (int) $d['id'])->select(array('h.*', 'p.name as person_name', 'dp.name as dept_name'))->orderBy('h.start_date', 'DESC')->orderBy('h.id', 'DESC')->get() as $h) {
            $holders[] = array('person' => str_or_empty($h['person_name']), 'dept' => str_or_empty($h['dept_name']), 'start' => (string) $h['start_date'], 'end' => str_or_empty($h['end_date']),
                'note' => str_or_empty($h['note']));
        }
        $out['components'] = $comps;
        $out['history'] = $hist;
        $out['licenses'] = $lic;
        $out['holders'] = $holders;
        $out['faults'] = RepairService::forDevice((int) $d['id']);
        $out['files'] = Files::forRecord('device', (int) $d['id']);
        $out['collectors'] = CollectorService::forDevice((int) $d['id']);
        $out['scope_items'] = CollectorService::scope();
        $out['caps'] = self::caps($req->user);
        $out['custom'] = \Omps\Engines\CustomFields::block('device', (int) $d['id']);
        return $out;
    }

    private static function rules()
    {
        return array(array(
            'asset_no' => 'required|string|max:50', 'name' => 'required|string|max:100', 'category' => 'required|in:' . implode(',', self::CATEGORIES),
            'brand' => 'nullable|string|max:50', 'model' => 'nullable|string|max:100', 'serial_no' => 'nullable|string|max:100', 'dept_id' => 'nullable|int',
            'holder_person_id' => 'nullable|int', 'location' => 'nullable|string|max:100', 'purchase_date' => 'nullable|date', 'received_date' => 'nullable|date',
            'price' => array('nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'), 'warranty_until' => 'nullable|date', 'run_status' => 'nullable|in:' . implode(',', self::MANUAL_STATUSES),
            'remark' => 'nullable|string|max:500', 'version' => 'nullable|int',
        ), array(
            'asset_no' => 'device.field.asset_no', 'name' => 'device.field.name', 'category' => 'device.field.category', 'brand' => 'device.field.brand', 'model' => 'device.field.model',
            'serial_no' => 'device.field.serial_no', 'dept_id' => 'device.field.dept', 'holder_person_id' => 'device.field.holder', 'location' => 'device.field.location',
            'purchase_date' => 'device.field.purchase_date', 'received_date' => 'device.field.received_date', 'price' => 'device.field.price',
            'warranty_until' => 'device.field.warranty_until', 'run_status' => 'device.field.run_status', 'remark' => 'device.field.remark',
        ));
    }

    /** Validated columns (shared with the import). */
    public static function clean(array $in, $exceptId = null)
    {
        if (isset($in['price']) && (is_int($in['price']) || is_float($in['price']))) {
            $in['price'] = (string) $in['price'];
        }
        list($rules, $labels) = self::rules();
        $v = Validator::check($in, $rules, $labels);
        $v['asset_no'] = trim((string) $v['asset_no']);
        $dup = DB::table('devices')->where('asset_no', $v['asset_no']);
        if ($exceptId !== null) {
            $dup->where('id', '<>', (int) $exceptId);
        }
        if ($dup->exists()) {
            throw ValidationException::field('asset_no', 'device.err.asset_taken');
        }
        if (!empty($v['dept_id']) && !DB::table('departments')->active()->where('id', (int) $v['dept_id'])->exists()) {
            throw ValidationException::field('dept_id', 'device.err.dept');
        }
        if (!empty($v['holder_person_id']) && !DB::table('personnel_profiles')->active()->where('id', (int) $v['holder_person_id'])->exists()) {
            throw ValidationException::field('holder_person_id', 'device.err.holder');
        }
        $cols = array();
        foreach (array('asset_no', 'name', 'category', 'brand', 'model', 'serial_no', 'location', 'purchase_date', 'received_date', 'price', 'warranty_until', 'remark') as $k) {
            $cols[$k] = isset($v[$k]) && $v[$k] !== '' ? $v[$k] : null;
        }
        $cols['dept_id'] = !empty($v['dept_id']) ? (int) $v['dept_id'] : null;
        $cols['holder_person_id'] = !empty($v['holder_person_id']) ? (int) $v['holder_person_id'] : null;
        if ($cols['holder_person_id'] !== null && $cols['dept_id'] === null) {
            // the department follows the holder when none is chosen
            $cols['dept_id'] = int_or_null(DB::value('SELECT dept_id FROM personnel_profiles WHERE id = ?', array($cols['holder_person_id'])));
        }
        return array($cols, isset($v['run_status']) && $v['run_status'] !== '' ? $v['run_status'] : null, isset($v['version']) ? $v['version'] : null);
    }

    /** Close the open holder row and open a new one when the holder changes. */
    public static function changeHolder($deviceId, $personId, $deptId, $date, $note, $uid)
    {
        DB::table('device_holders')->where('device_id', (int) $deviceId)->whereNull('end_date')->update(array('end_date' => $date));
        if ($personId !== null || $deptId !== null) {
            DB::table('device_holders')->insert(array('device_id' => (int) $deviceId, 'person_id' => $personId, 'dept_id' => $deptId, 'start_date' => $date,
                'note' => $note, 'created_at' => now(), 'created_by' => $uid));
        }
    }

    public static function create(Request $req, array $in)
    {
        self::need($req, 'add');
        list($cols, $status) = self::clean($in);
        $cfValues = \Omps\Engines\CustomFields::validate('device', isset($in['custom']) ? $in['custom'] : array());
        $uid = $req->userId();
        $id = DB::transaction(function () use ($cols, $status, $uid, $cfValues) {
            $id = DB::table('devices')->insert(array_merge($cols, array('org_id' => (int) config('app.org_id', 1), 'run_status' => $status !== null ? $status : 'normal',
                'created_at' => now(), 'created_by' => $uid)));
            if ($cols['holder_person_id'] !== null || $cols['dept_id'] !== null) {
                self::changeHolder($id, $cols['holder_person_id'], $cols['dept_id'], $cols['received_date'] !== null ? $cols['received_date'] : date('Y-m-d'), null, $uid);
            }
            \Omps\Engines\CustomFields::save('device', $id, $cfValues);
            return $id;
        });
        Audit::change($req, 'device', 'device_created', 'device', $id, $cols['asset_no'] . ' ' . $cols['name'], null, array_filter($cols, function ($x) {
            return $x !== null;
        }));
        return array('id' => $id);
    }

    public static function update(Request $req, $id, array $in)
    {
        $d = self::find($req, $id, 'edit');
        list($cols, $status, $version) = self::clean($in, (int) $d['id']);
        if ($version !== null && (int) $version !== (int) $d['version']) {
            throw HttpException::conflict('error.stale');
        }
        if ($status !== null && in_array((string) $d['run_status'], array('fault', 'repairing'), true) && $status === 'normal') {
            $status = null; // the repair flow decides when a faulty device is normal again
        }
        if ($status !== null) {
            $cols['run_status'] = $status;
        }
        $uid = $req->userId();
        $cfValues = isset($in['custom']) && is_array($in['custom']) ? \Omps\Engines\CustomFields::validate('device', $in['custom'], true) : array();
        $cfBefore = $cfValues ? \Omps\Engines\CustomFields::valuesOf('device', (int) $d['id']) : array();
        $holderChanged = int_or_null($d['holder_person_id']) !== $cols['holder_person_id'] || int_or_null($d['dept_id']) !== $cols['dept_id'];
        DB::transaction(function () use ($d, $cols, $uid, $holderChanged, $cfValues) {
            \Omps\Engines\CustomFields::save('device', (int) $d['id'], $cfValues);
            if (DB::table('devices')->where('id', (int) $d['id'])->where('version', (int) $d['version'])->increment('version', 1, array_merge($cols, array('updated_at' => now(), 'updated_by' => $uid))) === 0) {
                throw HttpException::conflict('error.stale');
            }
            if ($holderChanged) {
                self::changeHolder((int) $d['id'], $cols['holder_person_id'], $cols['dept_id'], date('Y-m-d'), null, $uid);
            }
        });
        $before = array_intersect_key($d, $cols);
        list($b, $a) = Audit::diff($before, $cols);
        list($cb, $ca) = \Omps\Engines\CustomFields::diff('device', $cfBefore, $cfValues);
        $b = array_merge($b, $cb);
        $a = array_merge($a, $ca);
        if ($a) {
            Audit::change($req, 'device', 'device_updated', 'device', (int) $d['id'], $cols['asset_no'] . ' ' . $cols['name'], $b, $a);
        }
        return array('ok' => true);
    }

    /** Change the holder with a date and a note (领用 / 归还 / 转交). */
    public static function holder(Request $req, $id, array $in)
    {
        $d = self::find($req, $id, 'edit');
        $v = Validator::check($in, array('person_id' => 'nullable|int', 'dept_id' => 'nullable|int', 'date' => 'required|date', 'note' => 'nullable|string|max:200'),
            array('person_id' => 'device.field.holder', 'dept_id' => 'device.field.dept', 'date' => 'device.field.date', 'note' => 'device.field.note'));
        $pid = !empty($v['person_id']) ? (int) $v['person_id'] : null;
        $dept = !empty($v['dept_id']) ? (int) $v['dept_id'] : null;
        if ($pid !== null) {
            $p = DB::table('personnel_profiles')->active()->where('id', $pid)->first();
            if (!$p) {
                throw ValidationException::field('person_id', 'device.err.holder');
            }
            if ($dept === null) {
                $dept = int_or_null($p['dept_id']);
            }
        }
        $uid = $req->userId();
        DB::transaction(function () use ($d, $pid, $dept, $v, $uid) {
            DB::table('devices')->where('id', (int) $d['id'])->increment('version', 1, array('holder_person_id' => $pid, 'dept_id' => $dept, 'updated_at' => now(), 'updated_by' => $uid));
            self::changeHolder((int) $d['id'], $pid, $dept, $v['date'], isset($v['note']) && $v['note'] !== '' ? $v['note'] : null, $uid);
        });
        Audit::change($req, 'device', 'device_holder_changed', 'device', (int) $d['id'], $d['asset_no'] . ' ' . $d['name'],
            array('holder_person_id' => int_or_null($d['holder_person_id']), 'dept_id' => int_or_null($d['dept_id'])), array('holder_person_id' => $pid, 'dept_id' => $dept));
        return array('ok' => true);
    }

    public static function delete(Request $req, $id)
    {
        $d = self::find($req, $id, 'del');
        $uid = $req->userId();
        DB::transaction(function () use ($d, $uid) {
            // keep the number free for a new device; the original number stays in the audit log
            DB::table('devices')->where('id', (int) $d['id'])->update(array('deleted_at' => now(), 'deleted_by' => $uid,
                'asset_no' => mb_substr((string) $d['asset_no'], 0, 38) . '~del' . (int) $d['id']));
            DB::table('device_collectors')->where('device_id', (int) $d['id'])->where('status', '<>', 'revoked')->update(array('status' => 'revoked', 'enroll_hash' => null));
            DB::table('device_holders')->where('device_id', (int) $d['id'])->whereNull('end_date')->update(array('end_date' => date('Y-m-d')));
        });
        Audit::change($req, 'device', 'device_deleted', 'device', (int) $d['id'], $d['asset_no'] . ' ' . $d['name'], array('asset_no' => $d['asset_no']), null);
        return array('ok' => true);
    }

    // ---------- parts ----------

    private static function partFields(array $in, $needType)
    {
        $rules = array('model' => 'nullable|string|max:150', 'serial_no' => 'nullable|string|max:100', 'spec' => 'nullable|string|max:100', 'date' => 'nullable|date',
            'note' => 'nullable|string|max:200');
        if ($needType) {
            $rules['ctype'] = 'required|in:' . implode(',', self::CTYPES);
        }
        $v = Validator::check($in, $rules, array('ctype' => 'device.part.ctype', 'model' => 'device.part.model', 'serial_no' => 'device.part.serial_no',
            'spec' => 'device.part.spec', 'date' => 'device.field.date', 'note' => 'device.field.note'));
        if ((!isset($v['model']) || $v['model'] === '') && (!isset($v['serial_no']) || $v['serial_no'] === '')) {
            throw ValidationException::field('model', 'device.err.part_empty');
        }
        foreach (array('model', 'serial_no', 'spec', 'note') as $k) {
            $v[$k] = isset($v[$k]) && $v[$k] !== '' ? $v[$k] : null;
        }
        $v['date'] = !empty($v['date']) ? $v['date'] : date('Y-m-d');
        return $v;
    }

    /** Insert a part with its history row. */
    public static function installPart($deviceId, $ctype, $model, $serial, $spec, $date, $source, $uid, $note = null, $replaced = null, $repairId = null, $anomalyId = null)
    {
        $cid = DB::table('device_components')->insert(array('device_id' => (int) $deviceId, 'ctype' => $ctype, 'model' => $model, 'serial_no' => $serial, 'spec' => $spec,
            'installed_on' => $date, 'source' => $source, 'created_at' => now(), 'created_by' => $uid));
        DB::table('device_component_history')->insert(array('device_id' => (int) $deviceId, 'component_id' => $cid, 'action' => $replaced === null ? 'install' : 'replace',
            'replaced_component_id' => $replaced, 'repair_id' => $repairId, 'anomaly_id' => $anomalyId, 'note' => $note, 'happened_at' => now(), 'created_by' => $uid));
        return $cid;
    }

    public static function removePart(array $c, $date, $uid, $note = null, $repairId = null, $anomalyId = null, $withHistory = true)
    {
        DB::table('device_components')->where('id', (int) $c['id'])->whereNull('removed_on')->update(array('removed_on' => $date));
        if ($withHistory) {
            DB::table('device_component_history')->insert(array('device_id' => (int) $c['device_id'], 'component_id' => (int) $c['id'], 'action' => 'remove',
                'repair_id' => $repairId, 'anomaly_id' => $anomalyId, 'note' => $note, 'happened_at' => now(), 'created_by' => $uid));
        }
    }

    private static function part(array $d, $cid)
    {
        $c = DB::table('device_components')->where('id', (int) $cid)->where('device_id', (int) $d['id'])->first();
        if (!$c) {
            throw HttpException::notFound();
        }
        if ($c['removed_on'] !== null) {
            throw HttpException::conflict('device.err.part_removed');
        }
        return $c;
    }

    public static function addPart(Request $req, $id, array $in)
    {
        $d = self::find($req, $id, 'edit');
        $v = self::partFields($in, true);
        $uid = $req->userId();
        $cid = DB::transaction(function () use ($d, $v, $uid) {
            return self::installPart((int) $d['id'], $v['ctype'], $v['model'], $v['serial_no'], $v['spec'], $v['date'], 'manual', $uid, $v['note']);
        });
        Audit::op($req->user, 'device', 'device_part_added', 'device', (int) $d['id'], $d['asset_no'], array('ctype' => $v['ctype'], 'model' => $v['model'], 'serial_no' => $v['serial_no']), $req->ip());
        return array('id' => $cid);
    }

    public static function replacePart(Request $req, $id, $cid, array $in)
    {
        $d = self::find($req, $id, 'edit');
        $c = self::part($d, $cid);
        $v = self::partFields($in, false);
        $uid = $req->userId();
        $new = DB::transaction(function () use ($d, $c, $v, $uid) {
            self::removePart($c, $v['date'], $uid, null, null, null, false);
            return self::installPart((int) $d['id'], (string) $c['ctype'], $v['model'], $v['serial_no'], $v['spec'], $v['date'], 'manual', $uid, $v['note'], (int) $c['id']);
        });
        Audit::op($req->user, 'device', 'device_part_replaced', 'device', (int) $d['id'], $d['asset_no'],
            array('ctype' => $c['ctype'], 'old' => trim($c['model'] . ' ' . $c['serial_no']), 'new' => trim($v['model'] . ' ' . $v['serial_no'])), $req->ip());
        return array('id' => $new);
    }

    public static function dropPart(Request $req, $id, $cid, array $in)
    {
        $d = self::find($req, $id, 'edit');
        $c = self::part($d, $cid);
        $v = Validator::check($in, array('date' => 'nullable|date', 'note' => 'nullable|string|max:200'), array('date' => 'device.field.date', 'note' => 'device.field.note'));
        $uid = $req->userId();
        DB::transaction(function () use ($c, $v, $uid) {
            self::removePart($c, !empty($v['date']) ? $v['date'] : date('Y-m-d'), $uid, !empty($v['note']) ? $v['note'] : null);
        });
        Audit::op($req->user, 'device', 'device_part_removed', 'device', (int) $d['id'], $d['asset_no'], array('ctype' => $c['ctype'], 'model' => $c['model'], 'serial_no' => $c['serial_no']), $req->ip());
        return array('ok' => true);
    }

    // ---------- licences ----------

    private static function licenseFields(array $in)
    {
        $v = Validator::check($in, array('name' => 'required|string|max:100', 'license_no' => 'nullable|string|max:200', 'seats' => 'nullable|int|min:1|max:100000',
            'expires_on' => 'nullable|date', 'remark' => 'nullable|string|max:200'),
            array('name' => 'device.lic.name', 'license_no' => 'device.lic.license_no', 'seats' => 'device.lic.seats', 'expires_on' => 'device.lic.expires_on', 'remark' => 'device.field.remark'));
        return array('name' => $v['name'], 'license_no' => !empty($v['license_no']) ? $v['license_no'] : null, 'seats' => !empty($v['seats']) ? (int) $v['seats'] : null,
            'expires_on' => !empty($v['expires_on']) ? $v['expires_on'] : null, 'remark' => !empty($v['remark']) ? $v['remark'] : null);
    }

    public static function addLicense(Request $req, $id, array $in)
    {
        $d = self::find($req, $id, 'edit');
        $v = self::licenseFields($in);
        $lid = DB::table('device_licenses')->insert(array_merge($v, array('device_id' => (int) $d['id'], 'created_at' => now(), 'created_by' => $req->userId())));
        Audit::op($req->user, 'device', 'device_license_saved', 'device', (int) $d['id'], $d['asset_no'], array('name' => $v['name'], 'expires_on' => $v['expires_on']), $req->ip());
        return array('id' => $lid);
    }

    private static function license(Request $req, $lid)
    {
        $l = DB::table('device_licenses')->find((int) $lid);
        if (!$l) {
            throw HttpException::notFound();
        }
        return array($l, self::find($req, (int) $l['device_id'], 'edit'));
    }

    public static function updateLicense(Request $req, $lid, array $in)
    {
        list($l, $d) = self::license($req, $lid);
        $v = self::licenseFields($in);
        DB::table('device_licenses')->where('id', (int) $l['id'])->update(array_merge($v, array('updated_at' => now())));
        // a new expiry date gets its reminders again
        if ((string) $l['expires_on'] !== (string) $v['expires_on']) {
            \Omps\Engines\Notify::retract('device_license', (int) $l['id'], array('remind_license'));
        }
        Audit::op($req->user, 'device', 'device_license_saved', 'device', (int) $d['id'], $d['asset_no'], array('name' => $v['name'], 'expires_on' => $v['expires_on']), $req->ip());
        return array('ok' => true);
    }

    public static function deleteLicense(Request $req, $lid)
    {
        list($l, $d) = self::license($req, $lid);
        DB::table('device_licenses')->where('id', (int) $l['id'])->delete();
        Audit::op($req->user, 'device', 'device_license_deleted', 'device', (int) $d['id'], $d['asset_no'], array('name' => $l['name']), $req->ip());
        return array('ok' => true);
    }

    // ---------- files ----------

    public static function folders(array $d, $section, $uploader)
    {
        return array(Files::folder('file.folder.devices'), trim($d['asset_no'] . ' ' . $d['name']), Files::folder('file.folder.' . $section), (string) $uploader);
    }

    public static function upload(Request $req, $id)
    {
        $d = self::find($req, $id, 'edit');
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        $out = array();
        foreach ($list as $up) {
            $out[] = Files::store($req, $up, 'device', (int) $d['id'], 'doc', self::folders($d, 'device_record', $req->user['name']), $req->user['person_id']);
        }
        return array('files' => Files::forRecord('device', (int) $d['id']), 'stored' => $out);
    }

    public static function fileAccess(Request $req, $deviceId, $write)
    {
        self::find($req, $deviceId, $write ? 'edit' : 'view');
    }
}
