<?php
namespace Omps\Modules\Device\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\Settings;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Notify;
use Omps\Engines\Permission;

/**
 * Collector clients and hardware checks (design §14).
 *
 *  - A device admin creates a one-time registration code for a device (valid 7 days). The client,
 *    installed with the user's knowledge, exchanges it for a device token. Codes and tokens are only
 *    stored as SHA-256 hashes.
 *  - The client reports at start-up and once a day. Only the items in the collection scope (采集范围)
 *    are accepted; anything else in a report is dropped by the server as well. Keyboard, screen,
 *    files and browsing are never collected.
 *  - A report identical to the previous one only adds a usage record. A changed one is compared with
 *    the registered parts: the first report of a device without parts becomes its parts list;
 *    differences become an anomaly (异常记录) and device admins and unit heads are told.
 *  - Confirming an anomaly updates the parts (with history); ignoring it keeps the parts as they are.
 */
class CollectorService
{
    const ITEMS = array('computer_name', 'os', 'cpu', 'memory', 'disk', 'board', 'monitor', 'nic', 'keyboard', 'mouse');
    /** Ticked until a device admin saves the scope; keyboard and mouse are opt-in. */
    const DEFAULT_ITEMS = array('computer_name', 'os', 'cpu', 'memory', 'disk', 'board', 'monitor', 'nic');
    const PART_ITEMS = array('cpu', 'memory', 'disk', 'board', 'monitor', 'nic', 'keyboard', 'mouse');
    const CLIENT_FILES = array('install.cmd', 'omps-collector.ps1', 'uninstall.cmd', 'readme.txt');
    /** Clients older than this do not collect keyboards and mice: the list marks them for an update. */
    const CLIENT_VERSION = '1.1';

    /** Items the clients may collect (system setting, DEFAULT_ITEMS until saved). */
    public static function scope()
    {
        $v = Settings::get('collect_scope', null);
        if (!is_array($v)) {
            return self::DEFAULT_ITEMS;
        }
        return array_values(array_intersect(self::ITEMS, $v));
    }

    public static function setScope(Request $req, array $in)
    {
        if (!Permission::can($req->user, 'device.edit')) {
            throw HttpException::forbidden();
        }
        $items = array();
        foreach (isset($in['items']) && is_array($in['items']) ? $in['items'] : array() as $x) {
            if (in_array($x, self::ITEMS, true)) {
                $items[] = $x;
            }
        }
        $items = array_values(array_intersect(self::ITEMS, $items));
        $before = self::scope();
        Settings::set('collect_scope', $items, $req->userId());
        Audit::change($req, 'device', 'collect_scope_changed', 'settings', null, null, array('items' => implode(',', $before)), array('items' => implode(',', $items)));
        return array('items' => $items);
    }

    private static function presentCollector(array $c)
    {
        return array('id' => (int) $c['id'], 'device_id' => (int) $c['device_id'], 'status' => (string) $c['status'], 'computer_name' => str_or_empty($c['computer_name']),
            'os' => str_or_empty($c['os']), 'version' => str_or_empty($c['client_version']), 'registered_at' => str_or_empty($c['registered_at']),
            'last_seen' => str_or_empty($c['last_seen']), 'last_ip' => str_or_empty($c['last_ip']), 'enroll_expires' => str_or_empty($c['enroll_expires']),
            'outdated' => (string) $c['status'] === 'active' && (string) $c['client_version'] !== '' && version_compare((string) $c['client_version'], self::CLIENT_VERSION, '<'),
            'asset_no' => isset($c['asset_no']) ? (string) $c['asset_no'] : '', 'device' => isset($c['device_name']) ? (string) $c['device_name'] : '');
    }

    public static function forDevice($deviceId)
    {
        $out = array();
        foreach (DB::table('device_collectors')->where('device_id', (int) $deviceId)->where('status', '<>', 'revoked')->orderBy('id', 'DESC')->get() as $c) {
            $out[] = self::presentCollector($c);
        }
        return $out;
    }

    private static function need(Request $req, $action)
    {
        if (!Permission::can($req->user, 'device.' . $action)) {
            throw HttpException::forbidden();
        }
    }

    /** Keyword fields of the client list (the browser offers the same keys). */
    const SEARCH_CLIENTS = array('asset_no' => 'd.asset_no', 'device' => 'd.name', 'computer' => 'c.computer_name', 'os' => 'c.os', 'version' => 'c.client_version', 'ip' => 'c.last_ip');

    /** $f = list filters (period on the last report time). */
    public static function index(Request $req, $status, array $f, $page, $size)
    {
        self::need($req, 'view');
        $q = DB::table('device_collectors c')->join('devices d', 'd.id', '=', 'c.device_id')->whereNull('d.deleted_at')
            ->select(array('c.*', 'd.asset_no', 'd.name as device_name'));
        DeviceService::scopeQuery($q, $req->user, 'd');
        $q->where('c.status', in_array($status, array('pending', 'active', 'revoked'), true) ? $status : 'active');
        $q->whereFilters($f, 'c.last_seen', self::SEARCH_CLIENTS);
        $res = $q->orderBy('d.asset_no')->orderBy('c.id', 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'presentCollector'), $res['rows']);
        return $res;
    }

    private static function normCode($code)
    {
        return strtoupper(preg_replace('/[\s\-]+/', '', (string) $code));
    }

    /** One-time registration code for a device; a code still unused for the same device is replaced. */
    public static function enroll(Request $req, $deviceId)
    {
        $d = DeviceService::find($req, $deviceId, 'edit');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $expires = date('Y-m-d H:i:s', time() + 7 * 86400);
        $uid = $req->userId();
        DB::transaction(function () use ($d, $code, $expires, $uid) {
            DB::table('device_collectors')->where('device_id', (int) $d['id'])->where('status', 'pending')->update(array('status' => 'revoked', 'enroll_hash' => null));
            DB::table('device_collectors')->insert(array('device_id' => (int) $d['id'], 'enroll_hash' => hash('sha256', $code), 'enroll_expires' => $expires,
                'status' => 'pending', 'created_at' => now(), 'created_by' => $uid));
        });
        Audit::op($req->user, 'device', 'collector_code_created', 'device', (int) $d['id'], $d['asset_no'] . ' ' . $d['name'], array('expires' => $expires), $req->ip());
        return array('code' => substr($code, 0, 5) . '-' . substr($code, 5), 'expires' => substr($expires, 0, 16), 'server' => '');
    }

    public static function revoke(Request $req, $id)
    {
        $c = DB::table('device_collectors')->find((int) $id);
        if (!$c) {
            throw HttpException::notFound();
        }
        $d = DeviceService::find($req, (int) $c['device_id'], 'edit');
        DB::table('device_collectors')->where('id', (int) $c['id'])->update(array('status' => 'revoked', 'enroll_hash' => null));
        Audit::op($req->user, 'device', 'collector_revoked', 'device', (int) $d['id'], $d['asset_no'] . ' ' . $d['name'], array('computer' => $c['computer_name']), $req->ip());
        return array('ok' => true);
    }

    // ---------- client API (no session; a code or a device token) ----------

    private static function throttle(Request $req)
    {
        $since = date('Y-m-d H:i:s', time() - 900);
        if ((int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = '#collector' AND created_at >= ?", array($req->ip(), $since)) >= 10) {
            throw new HttpException(429, 'too_many', 'collector.err.too_many');
        }
    }

    private static function clip($s, $n)
    {
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $s));
        return $s === '' ? null : mb_substr($s, 0, $n);
    }

    public static function register(Request $req)
    {
        self::throttle($req);
        $code = self::normCode($req->str('code'));
        $c = $code === '' ? null : DB::table('device_collectors')->where('enroll_hash', hash('sha256', $code))->where('status', 'pending')->first();
        $d = $c ? DB::table('devices')->active()->where('id', (int) $c['device_id'])->first() : null;
        if (!$c || !$d || (string) $c['enroll_expires'] < now()) {
            DB::table('login_attempts')->insert(array('ip' => $req->ip(), 'username' => '#collector', 'created_at' => now()));
            throw new HttpException(403, 'bad_code', 'collector.err.code');
        }
        $token = bin2hex(random_bytes(32));
        $computer = self::clip($req->str('computer_name'), 100);
        DB::transaction(function () use ($c, $d, $token, $computer, $req) {
            DB::table('device_collectors')->where('device_id', (int) $d['id'])->where('status', 'active')->update(array('status' => 'revoked'));
            DB::table('device_collectors')->where('id', (int) $c['id'])->update(array('status' => 'active', 'enroll_hash' => null, 'token_hash' => hash('sha256', $token),
                'registered_at' => now(), 'last_seen' => now(), 'last_ip' => $req->ip(), 'computer_name' => $computer, 'os' => self::clip($req->str('os'), 150),
                'client_version' => self::clip($req->str('version'), 20)));
            DB::table('device_usage_logs')->insert(array('device_id' => (int) $d['id'], 'collector_id' => (int) $c['id'], 'event' => 'register', 'ip' => $req->ip(),
                'computer_name' => $computer, 'created_at' => now()));
        });
        Audit::log(null, 'device', 'collector_registered', 'device', (int) $d['id'], $d['asset_no'] . ' ' . $d['name'], null, array('computer' => $computer), $req->ip());
        return array('token' => $token, 'scope' => self::scope(), 'device' => array('asset_no' => (string) $d['asset_no'], 'name' => (string) $d['name']));
    }

    /** Keep only items in scope, in a fixed shape; strings clipped. */
    public static function normalize(array $data, array $scope)
    {
        $out = array();
        foreach ($scope as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            if ($k === 'computer_name' || $k === 'os') {
                $out[$k] = self::clip(is_scalar($data[$k]) ? $data[$k] : '', $k === 'os' ? 150 : 100);
                continue;
            }
            $list = $data[$k];
            if (is_array($list) && $list && !isset($list[0])) {
                $list = array($list);
            }
            $items = array();
            foreach (is_array($list) ? array_slice($list, 0, 32) : array() as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $x = array('model' => self::clip(isset($it['model']) ? $it['model'] : '', 150), 'serial_no' => self::clip(isset($it['serial_no']) ? $it['serial_no'] : '', 100),
                    'spec' => self::clip(isset($it['spec']) ? $it['spec'] : '', 100));
                if ($x['model'] !== null || $x['serial_no'] !== null) {
                    $items[] = $x;
                }
            }
            usort($items, function ($a, $b) {
                return strcmp(implode('|', $a), implode('|', $b));
            });
            $out[$k] = $items;
        }
        return $out;
    }

    private static function key($ctype, $model, $serial, $spec)
    {
        $serial = strtoupper(trim((string) $serial));
        if ($serial !== '' && !in_array($serial, array('0', 'NONE', 'N/A', 'DEFAULT STRING', 'TO BE FILLED BY O.E.M.'), true)) {
            return $ctype . '|S|' . $serial;
        }
        return $ctype . '|M|' . strtoupper(trim((string) $model)) . '|' . strtoupper(trim((string) $spec));
    }

    public static function report(Request $req)
    {
        $token = $req->header('x-collector-token');
        if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', $req->header('authorization'), $m)) {
            $token = $m[1];
        }
        $c = $token === '' ? null : DB::table('device_collectors')->where('token_hash', hash('sha256', (string) $token))->where('status', 'active')->first();
        $d = $c ? DB::table('devices')->active()->where('id', (int) $c['device_id'])->first() : null;
        if (!$c || !$d) {
            throw new HttpException(401, 'bad_token', 'collector.err.token');
        }
        $event = in_array($req->str('event'), array('boot', 'daily', 'manual'), true) ? $req->str('event') : 'daily';
        $scope = self::scope();
        $data = self::normalize(is_array($req->input('data')) ? $req->input('data') : array(), $scope);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $json);
        $last = DB::table('device_hw_snapshots')->where('device_id', (int) $d['id'])->orderBy('id', 'DESC')->first();
        $changed = !$last || (string) $last['data_hash'] !== $hash;
        // the recorded parts were edited since the last comparison: compare again even if the hardware is the same
        $partsEdited = $last && (string) DB::value('SELECT MAX(happened_at) FROM device_component_history WHERE device_id = ?', array((int) $d['id'])) > (string) $last['collected_at'];
        $compare = $changed || $partsEdited;
        $computer = isset($data['computer_name']) ? $data['computer_name'] : $c['computer_name'];
        $anomaly = null;
        $prev = $last ? json_decode((string) $last['data'], true) : null;
        DB::transaction(function () use ($c, $d, $req, $event, $data, $json, $hash, $changed, $compare, $computer, $scope, $prev, &$anomaly) {
            DB::table('device_collectors')->where('id', (int) $c['id'])->update(array('last_seen' => now(), 'last_ip' => $req->ip(), 'computer_name' => $computer,
                'os' => isset($data['os']) ? $data['os'] : $c['os'], 'client_version' => self::clip($req->str('version'), 20)));
            DB::table('device_usage_logs')->insert(array('device_id' => (int) $d['id'], 'collector_id' => (int) $c['id'], 'event' => $event, 'ip' => $req->ip(),
                'computer_name' => $computer, 'changed' => $changed ? 1 : 0, 'created_at' => now()));
            if (!$compare) {
                return;
            }
            $sid = DB::table('device_hw_snapshots')->insert(array('collector_id' => (int) $c['id'], 'device_id' => (int) $d['id'], 'collected_at' => now(), 'data' => $json, 'data_hash' => $hash));
            $anomaly = self::compare($d, $sid, $data, $scope, is_array($prev) ? $prev : null);
            // parts history written by this comparison must not count as a later manual edit
            DB::table('device_hw_snapshots')->where('id', (int) $sid)->update(array('collected_at' => now()));
        });
        if ($anomaly !== null) {
            Notify::send(array_merge(Notify::deviceAdmins(), Notify::unitHeads()), 'device_anomaly',
                array('asset' => (string) $d['asset_no'], 'device' => (string) $d['name'], 'added' => $anomaly[1], 'removed' => $anomaly[2]),
                '/device/monitor?tab=anomaly&id=' . $anomaly[0], 'device_anomaly', $anomaly[0], null, 'warn');
        }
        return array('ok' => true, 'changed' => $changed, 'scope' => $scope);
    }

    /**
     * Compare a new snapshot with the parts in use; returns [anomaly id, added, removed] or null.
     * $prev = the previous snapshot: a part type it did not contain (newly added to the scope, or a
     * client updated to collect it) is registered as found instead of being reported as a change.
     */
    private static function compare(array $d, $snapshotId, array $data, array $scope, $prev = null)
    {
        $types = array_values(array_intersect(self::PART_ITEMS, $scope));
        $regs = $types ? DB::table('device_components')->where('device_id', (int) $d['id'])->whereNull('removed_on')->whereIn('ctype', $types)->get() : array();
        $hasRegs = array();
        foreach ($regs as $r) {
            $hasRegs[(string) $r['ctype']] = true;
        }
        $seen = array();
        foreach ($types as $t) {
            // first report of the device, or the first one that carries this type: the parts found become its parts
            $first = !$regs || ($prev !== null && !array_key_exists($t, $prev) && array_key_exists($t, $data) && empty($hasRegs[$t]));
            foreach (isset($data[$t]) ? $data[$t] : array() as $it) {
                if ($first) {
                    DeviceService::installPart((int) $d['id'], $t, $it['model'], $it['serial_no'], $it['spec'], date('Y-m-d'), 'collector', null, null);
                } else {
                    $seen[] = array('ctype' => $t, 'model' => $it['model'], 'serial_no' => $it['serial_no'], 'spec' => $it['spec']);
                }
            }
        }
        if (!$regs) {
            return null;
        }
        $pool = array();
        foreach ($regs as $r) {
            $pool[self::key($r['ctype'], $r['model'], $r['serial_no'], $r['spec'])][] = $r;
        }
        $added = array();
        foreach ($seen as $s) {
            $k = self::key($s['ctype'], $s['model'], $s['serial_no'], $s['spec']);
            if (!empty($pool[$k])) {
                array_shift($pool[$k]);
            } else {
                $added[] = $s;
            }
        }
        $removed = array();
        foreach ($pool as $left) {
            foreach ($left as $r) {
                $removed[] = array('id' => (int) $r['id'], 'ctype' => (string) $r['ctype'], 'model' => str_or_empty($r['model']), 'serial_no' => str_or_empty($r['serial_no']),
                    'spec' => str_or_empty($r['spec']));
            }
        }
        if (!$added && !$removed) {
            return null;
        }
        // an older unhandled record of the same device is replaced by this one
        DB::table('device_anomalies')->where('device_id', (int) $d['id'])->where('status', 'pending')->update(array('status' => 'ignored', 'note' => '#superseded', 'handled_at' => now()));
        $aid = DB::table('device_anomalies')->insert(array('device_id' => (int) $d['id'], 'snapshot_id' => (int) $snapshotId,
            'diff' => json_encode(array('added' => $added, 'removed' => $removed), JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'created_at' => now()));
        return array($aid, count($added), count($removed));
    }

    // ---------- anomalies and usage records ----------

    private static function presentAnomaly(array $a)
    {
        $diff = json_decode((string) $a['diff'], true);
        return array('id' => (int) $a['id'], 'device_id' => (int) $a['device_id'], 'asset_no' => (string) $a['asset_no'], 'device' => (string) $a['device_name'],
            'status' => (string) $a['status'], 'added' => isset($diff['added']) ? $diff['added'] : array(), 'removed' => isset($diff['removed']) ? $diff['removed'] : array(),
            'at' => (string) $a['created_at'], 'handled_at' => str_or_empty($a['handled_at']), 'handler' => isset($a['handler']) ? str_or_empty($a['handler']) : '',
            'note' => str_or_empty($a['note']));
    }

    private static function anomalyBase(Request $req)
    {
        $q = DB::table('device_anomalies a')->join('devices d', 'd.id', '=', 'a.device_id')->leftJoin('users u', 'u.id', '=', 'a.handled_by')->whereNull('d.deleted_at')
            ->select(array('a.*', 'd.asset_no', 'd.name as device_name', 'u.display_name as handler'));
        DeviceService::scopeQuery($q, $req->user, 'd');
        return $q;
    }

    /** Keyword fields of the anomaly list; 'changes' looks in the parts found (type, model, serial, spec). */
    const SEARCH_ANOMALIES = array('asset_no' => 'd.asset_no', 'device' => 'd.name', 'changes' => 'a.diff', 'handler' => 'u.display_name', 'note' => 'a.note');

    /** $f = list filters (period on the time the change was found). */
    public static function anomalies(Request $req, $status, array $f, $page, $size)
    {
        self::need($req, 'view');
        $q = self::anomalyBase($req);
        if ($status === 'done') {
            $q->whereIn('a.status', array('confirmed', 'ignored'));
        } else {
            $q->where('a.status', 'pending');
        }
        $q->whereFilters($f, 'a.created_at', self::SEARCH_ANOMALIES);
        $res = $q->orderBy('a.id', 'DESC')->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'presentAnomaly'), $res['rows']);
        $res['pending'] = self::anomalyBase($req)->where('a.status', 'pending')->count();
        return $res;
    }

    private static function anomaly(Request $req, $id)
    {
        self::need($req, 'edit');
        $a = self::anomalyBase($req)->where('a.id', (int) $id)->first();
        if (!$a) {
            throw HttpException::notFound();
        }
        if ((string) $a['status'] !== 'pending') {
            throw HttpException::conflict('device.err.anomaly_done');
        }
        return $a;
    }

    /** Confirm: the parts list follows the hardware found (removed / installed / replaced, with history). */
    public static function confirm(Request $req, $id, array $in)
    {
        $a = self::anomaly($req, $id);
        $note = isset($in['note']) ? self::clip($in['note'], 200) : null;
        $diff = json_decode((string) $a['diff'], true);
        $uid = $req->userId();
        DB::transaction(function () use ($a, $diff, $uid, $note) {
            $removedByType = array();
            foreach ($diff['removed'] as $r) {
                $c = DB::table('device_components')->where('id', (int) $r['id'])->whereNull('removed_on')->first();
                if ($c) {
                    $removedByType[(string) $c['ctype']][] = $c;
                }
            }
            foreach ($diff['added'] as $s) {
                $old = !empty($removedByType[$s['ctype']]) ? array_shift($removedByType[$s['ctype']]) : null;
                if ($old) {
                    DeviceService::removePart($old, date('Y-m-d'), $uid, null, null, (int) $a['id'], false);
                }
                DeviceService::installPart((int) $a['device_id'], $s['ctype'], $s['model'], $s['serial_no'], $s['spec'], date('Y-m-d'), 'collector', $uid, $note,
                    $old ? (int) $old['id'] : null, null, (int) $a['id']);
            }
            foreach ($removedByType as $left) {
                foreach ($left as $c) {
                    DeviceService::removePart($c, date('Y-m-d'), $uid, $note, null, (int) $a['id']);
                }
            }
            DB::table('device_anomalies')->where('id', (int) $a['id'])->update(array('status' => 'confirmed', 'handled_by' => $uid, 'handled_at' => now(), 'note' => $note));
        });
        Notify::markRead($uid, 'device_anomaly', (int) $a['id']);
        Audit::op($req->user, 'device', 'device_anomaly_confirmed', 'device', (int) $a['device_id'], $a['asset_no'] . ' ' . $a['device_name'],
            array('added' => count($diff['added']), 'removed' => count($diff['removed'])), $req->ip());
        return array('ok' => true);
    }

    public static function ignore(Request $req, $id, array $in)
    {
        $a = self::anomaly($req, $id);
        $note = isset($in['note']) ? self::clip($in['note'], 200) : null;
        DB::table('device_anomalies')->where('id', (int) $a['id'])->update(array('status' => 'ignored', 'handled_by' => $req->userId(), 'handled_at' => now(), 'note' => $note));
        Notify::markRead($req->userId(), 'device_anomaly', (int) $a['id']);
        Audit::op($req->user, 'device', 'device_anomaly_ignored', 'device', (int) $a['device_id'], $a['asset_no'] . ' ' . $a['device_name'], array('note' => $note), $req->ip());
        return array('ok' => true);
    }

    /** Keyword fields of the usage records (the browser offers the same keys). */
    const SEARCH_USAGE = array('asset_no' => 'd.asset_no', 'device' => 'd.name', 'computer' => 'l.computer_name', 'ip' => 'l.ip');

    /** $f = list filters (period on the record time). */
    public static function usage(Request $req, array $f, $deviceId, $page, $size)
    {
        self::need($req, 'view');
        $q = DB::table('device_usage_logs l')->join('devices d', 'd.id', '=', 'l.device_id')->whereNull('d.deleted_at')
            ->select(array('l.*', 'd.asset_no', 'd.name as device_name'));
        DeviceService::scopeQuery($q, $req->user, 'd');
        if ($deviceId) {
            $q->where('l.device_id', (int) $deviceId);
        }
        $q->whereFilters($f, 'l.created_at', self::SEARCH_USAGE);
        $res = $q->orderBy('l.id', 'DESC')->paginate($page, $size);
        $rows = array();
        foreach ($res['rows'] as $l) {
            $rows[] = array('id' => (int) $l['id'], 'device_id' => (int) $l['device_id'], 'asset_no' => (string) $l['asset_no'], 'device' => (string) $l['device_name'],
                'event' => (string) $l['event'], 'ip' => str_or_empty($l['ip']), 'computer_name' => str_or_empty($l['computer_name']), 'changed' => (int) $l['changed'] === 1,
                'at' => (string) $l['created_at']);
        }
        $res['rows'] = $rows;
        return $res;
    }

    /** The client files (collector/ folder, outside the web root) for device editors to hand out. */
    public static function clientFile(Request $req, $name)
    {
        self::need($req, 'edit');
        if (!in_array($name, self::CLIENT_FILES, true)) {
            throw HttpException::notFound();
        }
        $path = OMPS_ROOT . '/collector/' . $name;
        if (!is_file($path)) {
            throw HttpException::notFound();
        }
        return Response::file($path, $name, substr($name, -4) === '.txt' ? 'text/plain; charset=utf-8' : 'application/octet-stream', false);
    }
}
