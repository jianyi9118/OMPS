<?php
namespace Omps\Modules\Device\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;
use Omps\Engines\Notify;
use Omps\Engines\Permission;

/**
 * Faults and repairs (design §7.5, 故障与维修).
 *  - Anyone can report a fault (报修) from the workbench: their own devices, their department's, or
 *    any device found by number or name. Device admins are told.
 *  - A device admin assigns a repairer (an account, or an outside company by name), then records the
 *    result, the cost and any replaced parts (part history points at the repair).
 *  - The device is "fault" while a report is open, "repairing" while assigned, and "normal" again
 *    when no fault is left open.
 */
class RepairService
{
    const STATUSES = array('open', 'repairing', 'fixed', 'cancelled');

    private static function name($userId)
    {
        $r = DB::selectOne('SELECT u.display_name, p.name FROM users u LEFT JOIN personnel_profiles p ON p.id = u.person_id WHERE u.id = ?', array((int) $userId));
        return $r ? ($r['name'] !== null && $r['name'] !== '' ? (string) $r['name'] : (string) $r['display_name']) : '';
    }

    private static function presentFault(array $f)
    {
        $out = array('id' => (int) $f['id'], 'device_id' => (int) $f['device_id'], 'description' => (string) $f['description'], 'status' => (string) $f['status'],
            'reported_at' => (string) $f['reported_at'], 'reporter' => self::name((int) $f['reported_by']), 'closed_at' => str_or_empty($f['closed_at']),
            'cancel_reason' => str_or_empty($f['cancel_reason']), 'version' => (int) $f['version'], 'repair' => null);
        if (isset($f['asset_no'])) {
            $out['asset_no'] = (string) $f['asset_no'];
            $out['device'] = (string) $f['device_name'];
            $out['location'] = str_or_empty($f['location']);
        }
        $r = DB::table('device_repairs')->where('fault_id', (int) $f['id'])->first();
        if ($r) {
            $out['repair'] = array('id' => (int) $r['id'], 'repairer' => $r['repairer_user_id'] !== null ? self::name((int) $r['repairer_user_id']) : str_or_empty($r['repairer_name']),
                'repairer_user_id' => int_or_null($r['repairer_user_id']), 'started_at' => (string) $r['started_at'], 'fixed_at' => str_or_empty($r['fixed_at']),
                'cost' => $r['cost'] === null ? '' : (string) $r['cost'], 'result' => str_or_empty($r['result']));
        }
        return $out;
    }

    public static function forDevice($deviceId)
    {
        $out = array();
        foreach (DB::table('device_faults')->where('device_id', (int) $deviceId)->orderBy('id', 'DESC')->limit(100)->get() as $f) {
            $out[] = self::presentFault($f);
        }
        return $out;
    }

    // ---------- reporting (everyone) ----------

    /** Devices to choose from: held by me, my department's, or found by number / name (only what a report needs). */
    public static function options(Request $req, $kw)
    {
        $u = $req->user;
        $q = DB::table('devices d')->active()->whereNotIn('d.run_status', array('scrapped'))->select(array('d.id', 'd.asset_no', 'd.name', 'd.location', 'd.holder_person_id', 'd.dept_id'));
        if ($kw !== '') {
            $q->whereLikeAny(array('d.asset_no', 'd.name', 'd.location'), $kw);
        } else {
            $mine = $u['person_id'] === null ? 0 : (int) $u['person_id'];
            $q->whereRaw('(d.holder_person_id = ? OR d.dept_id = ?)', array($mine, $u['dept_id'] === null ? 0 : (int) $u['dept_id']));
        }
        $out = array();
        foreach ($q->orderBy('d.asset_no')->limit(30)->get() as $d) {
            $out[] = array('id' => (int) $d['id'], 'asset_no' => (string) $d['asset_no'], 'name' => (string) $d['name'], 'location' => str_or_empty($d['location']),
                'mine' => $u['person_id'] !== null && (int) $d['holder_person_id'] === (int) $u['person_id']);
        }
        $my = array();
        foreach (DB::table('device_faults f')->join('devices d', 'd.id', '=', 'f.device_id')->where('f.reported_by', $req->userId())
            ->select(array('f.*', 'd.asset_no', 'd.name as device_name', 'd.location'))->orderBy('f.id', 'DESC')->limit(10)->get() as $f) {
            $my[] = self::presentFault($f);
        }
        return array('devices' => $out, 'mine' => $my);
    }

    public static function report(Request $req, array $in)
    {
        $v = Validator::check($in, array('device_id' => 'required|int', 'description' => 'required|string|max:2000'),
            array('device_id' => 'device.fault.device', 'description' => 'device.fault.description'));
        $d = DB::table('devices')->active()->where('id', (int) $v['device_id'])->first();
        if (!$d || (string) $d['run_status'] === 'scrapped') {
            throw ValidationException::field('device_id', 'device.err.not_found');
        }
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        foreach ($list as $up) {
            Files::check($up);
        }
        $uid = $req->userId();
        $fid = DB::transaction(function () use ($d, $v, $uid) {
            $fid = DB::table('device_faults')->insert(array('org_id' => (int) config('app.org_id', 1), 'device_id' => (int) $d['id'], 'reported_by' => $uid,
                'reported_at' => now(), 'description' => trim((string) $v['description']), 'status' => 'open'));
            DB::table('devices')->where('id', (int) $d['id'])->where('run_status', 'normal')->update(array('run_status' => 'fault', 'updated_at' => now()));
            return $fid;
        });
        foreach ($list as $up) {
            Files::store($req, $up, 'device_fault', $fid, 'photo', DeviceService::folders($d, 'device_repair', $req->user['name']), $req->user['person_id']);
        }
        Notify::send(Notify::deviceAdmins(), 'device_fault_reported', array('name' => $req->user['name'], 'asset' => (string) $d['asset_no'], 'device' => (string) $d['name']),
            '/device/repair?id=' . $fid, 'device_fault', $fid, $uid, 'warn');
        Audit::op($req->user, 'device', 'device_fault_reported', 'device', (int) $d['id'], $d['asset_no'] . ' ' . $d['name'], null, $req->ip());
        return array('id' => $fid);
    }

    // ---------- handling (device admins) ----------

    private static function base(Request $req)
    {
        $q = DB::table('device_faults f')->join('devices d', 'd.id', '=', 'f.device_id')->whereNull('d.deleted_at')
            ->select(array('f.*', 'd.asset_no', 'd.name as device_name', 'd.location'));
        DeviceService::scopeQuery($q, $req->user, 'd');
        return $q;
    }

    public static function index(Request $req, $status, $kw, $page, $size)
    {
        if (!Permission::can($req->user, 'device.view')) {
            throw HttpException::forbidden();
        }
        $q = self::base($req);
        if ($status === 'active') {
            $q->whereIn('f.status', array('open', 'repairing'));
        } elseif (in_array($status, self::STATUSES, true)) {
            $q->where('f.status', $status);
        }
        if ($kw !== '') {
            $q->whereLikeAny(array('d.asset_no', 'd.name', 'f.description'), $kw);
        }
        $res = $q->orderBy('f.id', 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'presentFault'), $res['rows']);
        $res['open'] = self::base($req)->whereIn('f.status', array('open', 'repairing'))->count();
        $res['repairers'] = array();
        foreach (Notify::deviceAdmins() as $id) {
            $res['repairers'][] = array('id' => $id, 'name' => self::name($id));
        }
        return $res;
    }

    /** Fixed repairs (维修记录). */
    public static function repairs(Request $req, $kw, $page, $size)
    {
        if (!Permission::can($req->user, 'device.view')) {
            throw HttpException::forbidden();
        }
        $q = self::base($req)->where('f.status', 'fixed');
        if ($kw !== '') {
            $q->whereLikeAny(array('d.asset_no', 'd.name', 'f.description'), $kw);
        }
        $res = $q->orderBy('f.closed_at', 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'presentFault'), $res['rows']);
        $res['cost'] = (string) DB::value("SELECT COALESCE(SUM(r.cost), 0) FROM device_repairs r JOIN device_faults f ON f.id = r.fault_id WHERE f.status = 'fixed'");
        return $res;
    }

    private static function fault(Request $req, $id, $action = 'view')
    {
        if (!Permission::can($req->user, 'device.' . $action)) {
            throw HttpException::forbidden();
        }
        $f = self::base($req)->where('f.id', (int) $id)->first();
        if (!$f) {
            Audit::denied($req, 'device_fault', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        return $f;
    }

    public static function show(Request $req, $id)
    {
        $f = self::fault($req, $id);
        $out = self::presentFault($f);
        $out['files'] = Files::forRecord('device_fault', (int) $f['id']);
        $out['parts'] = array();
        foreach (DB::table('device_components')->where('device_id', (int) $f['device_id'])->whereNull('removed_on')->orderBy('ctype')->get() as $c) {
            $out['parts'][] = array('id' => (int) $c['id'], 'ctype' => (string) $c['ctype'], 'model' => str_or_empty($c['model']), 'serial_no' => str_or_empty($c['serial_no']));
        }
        Notify::markRead($req->userId(), 'device_fault', (int) $f['id']);
        return $out;
    }

    /** 派修: an account or an outside repairer by name. */
    public static function assign(Request $req, $id, array $in)
    {
        $f = self::fault($req, $id, 'edit');
        if ((string) $f['status'] !== 'open') {
            throw HttpException::conflict('device.err.fault_state');
        }
        $v = Validator::check($in, array('repairer_user_id' => 'nullable|int', 'repairer_name' => 'nullable|string|max:100'),
            array('repairer_user_id' => 'device.fault.repairer', 'repairer_name' => 'device.fault.repairer_name'));
        $rid = !empty($v['repairer_user_id']) ? (int) $v['repairer_user_id'] : null;
        $rname = !empty($v['repairer_name']) ? trim((string) $v['repairer_name']) : null;
        if ($rid === null && $rname === null) {
            throw ValidationException::field('repairer_user_id', 'device.err.repairer');
        }
        if ($rid !== null && Notify::user($rid) === null) {
            throw ValidationException::field('repairer_user_id', 'device.err.repairer');
        }
        $uid = $req->userId();
        DB::transaction(function () use ($f, $rid, $rname, $uid) {
            if (DB::table('device_faults')->where('id', (int) $f['id'])->where('status', 'open')->increment('version', 1, array('status' => 'repairing')) === 0) {
                throw HttpException::conflict('error.stale');
            }
            DB::table('device_repairs')->insert(array('fault_id' => (int) $f['id'], 'device_id' => (int) $f['device_id'], 'repairer_user_id' => $rid,
                'repairer_name' => $rid === null ? $rname : null, 'started_at' => now(), 'created_by' => $uid));
            DB::table('devices')->where('id', (int) $f['device_id'])->whereIn('run_status', array('normal', 'fault'))->update(array('run_status' => 'repairing', 'updated_at' => now()));
        });
        Notify::markRead($uid, 'device_fault', (int) $f['id']);
        if ($rid !== null) {
            Notify::send(array($rid), 'device_fault_assigned', array('name' => $req->user['name'], 'asset' => (string) $f['asset_no'], 'device' => (string) $f['device_name']),
                '/device/repair?id=' . (int) $f['id'], 'device_fault', (int) $f['id'], $uid, 'warn');
        }
        Audit::op($req->user, 'device', 'device_repair_assigned', 'device', (int) $f['device_id'], $f['asset_no'] . ' ' . $f['device_name'],
            array('repairer' => $rid !== null ? self::name($rid) : $rname), $req->ip());
        return array('ok' => true);
    }

    /** After the last open fault is closed the device is normal again. */
    private static function settleDevice($deviceId)
    {
        $open = DB::table('device_faults')->where('device_id', (int) $deviceId)->whereIn('status', array('open', 'repairing'))->get();
        $status = 'normal';
        foreach ($open as $o) {
            $status = (string) $o['status'] === 'repairing' ? 'repairing' : ($status === 'repairing' ? 'repairing' : 'fault');
        }
        DB::table('devices')->where('id', (int) $deviceId)->whereIn('run_status', array('fault', 'repairing'))->update(array('run_status' => $status, 'updated_at' => now()));
    }

    /** 完成维修: result, cost, replaced parts. */
    public static function finish(Request $req, $id, array $in)
    {
        $f = self::fault($req, $id, 'edit');
        if ((string) $f['status'] !== 'repairing') {
            throw HttpException::conflict('device.err.fault_state');
        }
        if (isset($in['cost']) && (is_int($in['cost']) || is_float($in['cost']))) {
            $in['cost'] = (string) $in['cost'];
        }
        $v = Validator::check($in, array('result' => 'required|string|max:2000', 'cost' => array('nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'), 'parts' => 'nullable|array'),
            array('result' => 'device.fault.result', 'cost' => 'device.fault.cost', 'parts' => 'device.fault.parts'));
        $parts = array();
        foreach (isset($in['parts']) && is_array($in['parts']) ? $in['parts'] : array() as $p) {
            if (!is_array($p) || empty($p['component_id'])) {
                continue;
            }
            $c = DB::table('device_components')->where('id', (int) $p['component_id'])->where('device_id', (int) $f['device_id'])->whereNull('removed_on')->first();
            $model = isset($p['model']) ? trim((string) $p['model']) : '';
            $serial = isset($p['serial_no']) ? trim((string) $p['serial_no']) : '';
            if (!$c || ($model === '' && $serial === '') || mb_strlen($model) > 150 || mb_strlen($serial) > 100) {
                throw ValidationException::field('parts', 'device.err.part_row');
            }
            $parts[] = array($c, $model === '' ? null : $model, $serial === '' ? null : $serial, isset($p['spec']) && trim((string) $p['spec']) !== '' ? mb_substr(trim((string) $p['spec']), 0, 100) : null);
        }
        $uid = $req->userId();
        $repair = DB::table('device_repairs')->where('fault_id', (int) $f['id'])->first();
        DB::transaction(function () use ($f, $v, $parts, $uid, $repair) {
            if (DB::table('device_faults')->where('id', (int) $f['id'])->where('status', 'repairing')->increment('version', 1, array('status' => 'fixed', 'closed_at' => now())) === 0) {
                throw HttpException::conflict('error.stale');
            }
            DB::table('device_repairs')->where('id', (int) $repair['id'])->update(array('fixed_at' => now(), 'result' => trim((string) $v['result']),
                'cost' => isset($v['cost']) && $v['cost'] !== '' ? $v['cost'] : null));
            foreach ($parts as $p) {
                DeviceService::removePart($p[0], date('Y-m-d'), $uid, null, null, null, false);
                DeviceService::installPart((int) $f['device_id'], (string) $p[0]['ctype'], $p[1], $p[2], $p[3], date('Y-m-d'), 'manual', $uid, null, (int) $p[0]['id'], (int) $repair['id']);
            }
            self::settleDevice((int) $f['device_id']);
        });
        Notify::markRead($uid, 'device_fault', (int) $f['id']);
        Notify::send(array((int) $f['reported_by']), 'device_fault_fixed', array('asset' => (string) $f['asset_no'], 'device' => (string) $f['device_name']),
            '/?repairs=1', 'device_fault', (int) $f['id'], $uid);
        Audit::op($req->user, 'device', 'device_repair_finished', 'device', (int) $f['device_id'], $f['asset_no'] . ' ' . $f['device_name'],
            array('cost' => isset($v['cost']) ? $v['cost'] : null, 'parts' => count($parts)), $req->ip());
        return array('ok' => true);
    }

    public static function cancel(Request $req, $id, array $in)
    {
        $f = self::fault($req, $id, 'edit');
        if (!in_array((string) $f['status'], array('open', 'repairing'), true)) {
            throw HttpException::conflict('device.err.fault_state');
        }
        $v = Validator::check($in, array('reason' => 'required|string|max:200'), array('reason' => 'device.fault.cancel_reason'));
        $uid = $req->userId();
        DB::transaction(function () use ($f, $v) {
            if (DB::table('device_faults')->where('id', (int) $f['id'])->whereIn('status', array('open', 'repairing'))->increment('version', 1, array('status' => 'cancelled',
                'closed_at' => now(), 'cancel_reason' => $v['reason'])) === 0) {
                throw HttpException::conflict('error.stale');
            }
            self::settleDevice((int) $f['device_id']);
        });
        Notify::markRead($uid, 'device_fault', (int) $f['id']);
        Notify::send(array((int) $f['reported_by']), 'device_fault_cancelled', array('asset' => (string) $f['asset_no'], 'device' => (string) $f['device_name'], 'reason' => (string) $v['reason']),
            '/?repairs=1', 'device_fault', (int) $f['id'], $uid);
        Audit::op($req->user, 'device', 'device_fault_cancelled', 'device', (int) $f['device_id'], $f['asset_no'] . ' ' . $f['device_name'], array('reason' => $v['reason']), $req->ip());
        return array('ok' => true);
    }

    /** Fault photos: the reporter, and whoever may see the device. Only device editors remove them. */
    public static function fileAccess(Request $req, $faultId, $write)
    {
        $f = DB::table('device_faults')->find((int) $faultId);
        if (!$f) {
            throw HttpException::notFound();
        }
        if (!$write && (int) $f['reported_by'] === $req->userId()) {
            return;
        }
        if (!Permission::can($req->user, 'device.' . ($write ? 'edit' : 'view'))) {
            // someone else's report: answer as if it did not exist
            Audit::denied($req, 'device_fault', array('id' => (int) $faultId));
            throw HttpException::notFound();
        }
        self::fault($req, (int) $faultId, $write ? 'edit' : 'view');
    }
}
