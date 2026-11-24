<?php
namespace Omps\Tests;

use Omps\Database\DB;
use Omps\Modules\Affairs\Services\Scheduler;
use Omps\Modules\Device\Services\CollectorService;

/**
 * Phase 6: devices. Records and scope, holders, parts with history, licences and their reminder,
 * fault reports and repairs, collector registration / reports / anomalies / scope, import and export.
 */
class DeviceTest extends TestCase
{
    /** @var array */
    private $f;

    public function setUp()
    {
        parent::setUp();
        $s = substr(md5(uniqid('', true)), 0, 4);
        $tech = $this->dept('信息科' . $s);
        $admin = $this->person('赵磊', $tech);
        $wang = $this->person('王晓', $tech);
        $chen = $this->person('陈静', $tech);
        $lead = $this->person('李建国', $tech);
        $boss = $this->person('张伟', null);
        $this->f = array('tech' => $tech, 'admin' => $admin, 'wang' => $wang, 'chen' => $chen, 's' => $s,
            'uAdmin' => $this->user('member', $admin, array('is_device_admin' => 1)), 'uWang' => $this->user('member', $wang), 'uChen' => $this->user('member', $chen),
            'uLead' => $this->user('dept_head', $lead), 'uUnit' => $this->user('unit_head', $boss));
    }

    private function asset($n)
    {
        return 'T' . $this->f['s'] . '-' . $n;
    }

    private function device($n, array $extra = array())
    {
        $r = $this->request('POST', '/api/v1/devices', array_merge(array('asset_no' => $this->asset($n), 'name' => '办公电脑 ' . $n, 'category' => 'pc'), $extra));
        $this->status(200, $r, 'create device ' . $n);
        return $this->data($r)['id'];
    }

    private function notes($userId, $type, $bizType, $bizId)
    {
        return DB::table('notifications')->where('user_id', (int) $userId)->where('type', $type)->where('biz_type', $bizType)->where('biz_id', (int) $bizId)->count();
    }

    private function tmp($name, $content)
    {
        $p = sys_get_temp_dir() . '/omps-' . uniqid() . '-' . $name;
        file_put_contents($p, $content);
        return array('path' => $p, 'name' => $name);
    }

    public function testRecordsHoldersAndScope()
    {
        $f = $this->f;
        foreach (array('uWang', 'uLead') as $who) {
            $this->login($f[$who]['username']);
            $this->status(403, $this->request('GET', '/api/v1/devices'), $who . ' has no device rights');
            $this->status(403, $this->request('GET', '/device/archive', null, array(), array('Accept' => 'text/html')));
        }
        // a member ticked as device admin manages all devices
        $this->login($f['uAdmin']['username']);
        $home = $this->request('GET', '/me/profile', null, array(), array('Accept' => 'text/html'));
        $this->contains('设备管理', $home->body);
        $this->status(200, $this->request('GET', '/device/archive', null, array(), array('Accept' => 'text/html')));
        $this->status(302, $this->request('GET', '/device', null, array(), array('Accept' => 'text/html')), 'old link redirects');
        $this->status(422, $this->request('POST', '/api/v1/devices', array('asset_no' => '', 'name' => 'x', 'category' => 'pc')));
        $this->status(422, $this->request('POST', '/api/v1/devices', array('asset_no' => $this->asset(0), 'name' => 'x', 'category' => 'car')));
        $id = $this->device(1, array('holder_person_id' => $f['wang'], 'price' => '4500.50', 'received_date' => '2026-03-05'));
        $this->status(422, $this->request('POST', '/api/v1/devices', array('asset_no' => $this->asset(1), 'name' => 'x', 'category' => 'pc')), 'asset number unique');
        $d = $this->data($this->request('GET', '/api/v1/devices/' . $id));
        $this->same($f['tech'], $d['dept_id'], 'department follows the holder');
        $this->same('王晓', $d['holder']);
        $this->same(1, count($d['holders']));
        $this->same('2026-03-05', $d['holders'][0]['start']);
        // change the holder twice: the history keeps every period
        $this->status(200, $this->request('PUT', '/api/v1/devices/' . $id, array_merge($d, array('holder_person_id' => $f['chen'], 'version' => $d['version']))));
        $this->status(409, $this->request('PUT', '/api/v1/devices/' . $id, array_merge($d, array('version' => $d['version']))), 'stale version');
        $this->status(200, $this->request('POST', '/api/v1/devices/' . $id . '/holder', array('person_id' => '', 'date' => date('Y-m-d'), 'note' => '归还')));
        $d = $this->data($this->request('GET', '/api/v1/devices/' . $id));
        $this->same(null, $d['holder_person_id']);
        $this->same(2, count($d['holders']), 'two closed periods, nobody holds it now');
        $this->ok($d['holders'][0]['end'] !== '' && $d['holders'][1]['end'] !== '');
        $list = $this->data($this->request('GET', '/api/v1/devices', null, array('kw' => $this->asset(1))));
        $this->same(1, $list['total']);
        // delete frees the number
        $this->status(200, $this->request('DELETE', '/api/v1/devices/' . $id));
        $this->status(404, $this->request('GET', '/api/v1/devices/' . $id));
        $this->device(1);
        $this->ok($this->auditCount('device_created') >= 2 && $this->auditCount('device_deleted') >= 1);
        // the unit head sees devices too
        $this->login($f['uUnit']['username']);
        $this->same(1, $this->data($this->request('GET', '/api/v1/devices', null, array('kw' => $this->asset(1))))['total']);
    }

    public function testPartsLicencesAndFiles()
    {
        $f = $this->f;
        $this->login($f['uAdmin']['username']);
        $id = $this->device(2);
        $this->status(422, $this->request('POST', '/api/v1/devices/' . $id . '/parts', array('ctype' => 'disk')), 'model or serial needed');
        $cid = $this->data($this->request('POST', '/api/v1/devices/' . $id . '/parts', array('ctype' => 'disk', 'model' => 'WD Blue', 'serial_no' => 'WD-001', 'spec' => '1 TB')))['id'];
        $new = $this->data($this->request('POST', '/api/v1/devices/' . $id . '/parts/' . $cid . '/replace', array('model' => 'Samsung 870', 'serial_no' => 'S-002', 'spec' => '1 TB', 'note' => '坏道')))['id'];
        $this->status(409, $this->request('POST', '/api/v1/devices/' . $id . '/parts/' . $cid . '/replace', array('model' => 'x')), 'removed part');
        $this->ok(DB::value('SELECT removed_on FROM device_components WHERE id = ?', array($cid)) !== null, 'old part kept with removed date');
        $h = DB::table('device_component_history')->where('component_id', $new)->first();
        $this->same(array('replace', $cid), array((string) $h['action'], (int) $h['replaced_component_id']));
        $this->status(200, $this->request('POST', '/api/v1/devices/' . $id . '/parts/' . $new . '/remove', array('note' => '送修')));
        $d = $this->data($this->request('GET', '/api/v1/devices/' . $id));
        $this->same(3, count($d['history']));
        $this->same(2, count($d['components']), 'nothing is deleted');

        // licence: reminders to device admins before expiry, once per trigger point
        $lid = $this->data($this->request('POST', '/api/v1/devices/' . $id . '/licenses', array('name' => 'Office 2021', 'license_no' => 'XXXXX-1', 'seats' => 1, 'expires_on' => '2026-12-30')))['id'];
        Scheduler::run(strtotime('2026-12-23 09:05'));
        Scheduler::run(strtotime('2026-12-23 09:20'));
        $this->same(1, $this->notes($f['uAdmin']['id'], 'remind_license', 'device_license', $lid), '7 days before 09:00');
        $msg = DB::table('notifications')->where('user_id', $f['uAdmin']['id'])->where('type', 'remind_license')->where('biz_id', $lid)->first();
        $this->same('/device/archive?id=' . $id, $msg['link']);
        $this->status(200, $this->request('PUT', '/api/v1/device-licenses/' . $lid, array('name' => 'Office 2021', 'expires_on' => '2027-12-30')));
        $this->same(0, $this->notes($f['uAdmin']['id'], 'remind_license', 'device_license', $lid), 'a new expiry date clears the unread reminder');

        // files: device admins only
        $up = $this->upload('/api/v1/devices/' . $id . '/files', array($this->tmp('发票.txt', 'invoice')));
        $this->status(200, $up);
        $rel = $this->data($up)['files'][0]['relation_id'];
        $this->status(200, $this->request('GET', '/api/v1/files/' . $rel));
        $this->login($f['uWang']['username']);
        $this->ok($this->request('GET', '/api/v1/files/' . $rel)->status >= 403, 'members cannot read device files');
    }

    public function testFaultReportAndRepair()
    {
        $f = $this->f;
        $this->login($f['uAdmin']['username']);
        $id = $this->device(3, array('holder_person_id' => $f['wang'], 'location' => '302 室'));
        $cid = $this->data($this->request('POST', '/api/v1/devices/' . $id . '/parts', array('ctype' => 'memory', 'model' => 'Kingston 8G', 'serial_no' => 'K-1')))['id'];
        $other = $this->device(4);

        // anyone can report: own devices are offered, others are found by number
        $this->login($f['uWang']['username']);
        $opts = $this->data($this->request('GET', '/api/v1/device-faults/options'));
        $this->ok(in_array($id, array_column($opts['devices'], 'id'), true));
        $found = $this->data($this->request('GET', '/api/v1/device-faults/options', null, array('kw' => $this->asset(4))));
        $this->same($other, $found['devices'][0]['id']);
        $this->status(422, $this->request('POST', '/api/v1/device-faults/report', array('device_id' => $id, 'description' => '')));
        $r = $this->upload('/api/v1/device-faults/report', array($this->tmp('蓝屏.txt', 'photo')), array('device_id' => $id, 'description' => '开机蓝屏'));
        $this->status(200, $r);
        $fid = $this->data($r)['id'];
        $this->same('fault', (string) DB::value('SELECT run_status FROM devices WHERE id = ?', array($id)));
        $this->same(1, $this->notes($f['uAdmin']['id'], 'device_fault_reported', 'device_fault', $fid));
        $this->status(403, $this->request('POST', '/api/v1/device-faults/' . $fid . '/assign', array('repairer_name' => 'x')), 'members cannot handle faults');
        $photo = DB::value("SELECT id FROM file_relations WHERE biz_type = 'device_fault' AND biz_id = ?", array($fid));
        $this->status(200, $this->request('GET', '/api/v1/files/' . $photo), 'the reporter sees own photos');
        $this->login($f['uChen']['username']);
        $this->status(404, $this->request('GET', '/api/v1/files/' . $photo));

        // assign → repairing; finish with a replaced part → normal, reporter told
        $this->login($f['uAdmin']['username']);
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(in_array('/device/repair?id=' . $fid, array_column($todo['rows'], 'link'), true));
        $this->status(422, $this->request('POST', '/api/v1/device-faults/' . $fid . '/assign', array()), 'repairer needed');
        $this->status(200, $this->request('POST', '/api/v1/device-faults/' . $fid . '/assign', array('repairer_user_id' => $f['uAdmin']['id'])));
        $this->same('repairing', (string) DB::value('SELECT run_status FROM devices WHERE id = ?', array($id)));
        $this->status(409, $this->request('POST', '/api/v1/device-faults/' . $fid . '/assign', array('repairer_name' => 'x')), 'already assigned');
        $this->status(422, $this->request('POST', '/api/v1/device-faults/' . $fid . '/finish', array('result' => '更换内存', 'parts' => array(array('component_id' => $cid)))), 'new part data needed');
        $this->status(200, $this->request('POST', '/api/v1/device-faults/' . $fid . '/finish', array('result' => '内存故障，已更换', 'cost' => '260',
            'parts' => array(array('component_id' => $cid, 'model' => 'Kingston 8G', 'serial_no' => 'K-2')))));
        $this->same('normal', (string) DB::value('SELECT run_status FROM devices WHERE id = ?', array($id)));
        $rep = (int) DB::value('SELECT id FROM device_repairs WHERE fault_id = ?', array($fid));
        $this->same(1, (int) DB::value("SELECT COUNT(*) FROM device_component_history WHERE repair_id = ? AND action = 'replace'", array($rep)));
        $this->same(1, $this->notes($f['uWang']['id'], 'device_fault_fixed', 'device_fault', $fid));
        $rows = $this->data($this->request('GET', '/api/v1/device-repairs', null, array('kw' => $this->asset(3))))['rows'];
        $this->same('260.00', $rows[0]['repair']['cost']);

        // a second report is cancelled: the device is normal again
        $this->login($f['uChen']['username']);
        $fid2 = $this->data($this->request('POST', '/api/v1/device-faults/report', array('device_id' => $id, 'description' => '风扇响')))['id'];
        $this->login($f['uAdmin']['username']);
        $this->status(422, $this->request('POST', '/api/v1/device-faults/' . $fid2 . '/cancel', array('reason' => '')));
        $this->status(200, $this->request('POST', '/api/v1/device-faults/' . $fid2 . '/cancel', array('reason' => '已清灰，无需维修')));
        $this->same('normal', (string) DB::value('SELECT run_status FROM devices WHERE id = ?', array($id)));
        $this->same(1, $this->notes($f['uChen']['id'], 'device_fault_cancelled', 'device_fault', $fid2));

        // the reporter opens the request from the notification: 故障与维修 shows only their own requests
        $link = (string) DB::value("SELECT link FROM notifications WHERE user_id = ? AND type = 'device_fault_cancelled' AND biz_id = ?", array($f['uChen']['id'], $fid2));
        $this->same('/device/repair?id=' . $fid2, $link);
        $this->login($f['uChen']['username']);
        $this->status(200, $this->request('GET', '/device/repair'));
        $mine = $this->data($this->request('GET', '/api/v1/device-faults', null, array('status' => '')));
        $this->same(array($fid2), array_column($mine['rows'], 'id'), 'only the requests I reported');
        $this->same(array(), $mine['repairers']);
        $seen = $this->data($this->request('GET', '/api/v1/device-faults/' . $fid2));
        $this->same(array('cancelled', '赵磊', array()), array($seen['status'], $seen['closed_by'], $seen['parts']));
        $this->status(404, $this->request('GET', '/api/v1/device-faults/' . $fid), "someone else's request");
        $this->status(403, $this->request('POST', '/api/v1/device-faults/' . $fid2 . '/cancel', array('reason' => 'x')));
        $this->status(403, $this->request('GET', '/api/v1/device-repairs'));

        // the device admin finds what they handled under 已处理
        $this->login($f['uAdmin']['username']);
        $h = $this->data($this->request('GET', '/api/v1/todo/history'));
        $done = array();
        foreach ($h['rows'] as $r) {
            $done[] = $r['kind'] . '|' . $r['result'] . '|' . $r['link'];
        }
        $this->ok(in_array('device_fault|cancelled|/device/repair?id=' . $fid2, $done, true), 'cancelled');
        $this->ok(in_array('device_fault|assigned|/device/repair?id=' . $fid, $done, true), 'assigned');
        $this->ok(in_array('device_repair|fixed|/device/repair?id=' . $fid, $done, true), 'fixed');
        $this->ok($h['total'] >= 3);

        // removing 王晓 from the department: the dialog says one device is still with him
        $this->login($f['uLead']['username']);
        $this->same(1, $this->data($this->request('GET', '/api/v1/staff/' . $f['wang'] . '/remove-impact'))['devices']);
    }

    private function report($token, array $data, $event = 'daily')
    {
        return $this->request('POST', '/api/v1/collector/report', array('event' => $event, 'version' => '1.0', 'data' => $data), array(), array('X-Collector-Token' => $token));
    }

    public function testCollectorAnomaliesAndScope()
    {
        $f = $this->f;
        $this->login($f['uAdmin']['username']);
        $id = $this->device(5);
        $code = $this->data($this->request('POST', '/api/v1/devices/' . $id . '/collector-code'))['code'];
        $this->cookies = array();
        $this->status(403, $this->request('POST', '/api/v1/collector/register', array('code' => 'WRONG-CODE1')), 'wrong code');
        $this->same(1, (int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE username = '#collector'"));
        $r = $this->request('POST', '/api/v1/collector/register', array('code' => strtolower($code), 'computer_name' => 'PC-0005', 'os' => 'Windows 10', 'version' => '1.0'));
        $this->status(200, $r, 'register (code case and dash do not matter)');
        $token = $this->data($r)['token'];
        $this->ok(!DB::table('device_collectors')->where('token_hash', $token)->exists(), 'only the hash is stored');
        $this->status(403, $this->request('POST', '/api/v1/collector/register', array('code' => $code)), 'a code works once');
        $this->status(401, $this->report('nope', array()), 'bad token');

        $hw = array('computer_name' => 'PC-0005', 'os' => 'Windows 10 Pro', 'cpu' => array(array('model' => 'i5-8500', 'serial_no' => 'BFEB', 'spec' => '6 cores')),
            'memory' => array(array('model' => 'Kingston', 'serial_no' => 'M1', 'spec' => '8 GB'), array('model' => 'Kingston', 'serial_no' => 'M2', 'spec' => '8 GB')),
            'disk' => array(array('model' => 'WD Blue', 'serial_no' => 'D1', 'spec' => '512 GB')), 'nic' => array(array('model' => 'Realtek', 'serial_no' => 'AA:BB', 'spec' => null)),
            'keyboard' => 'never kept');
        $this->status(200, $this->report($token, $hw, 'boot'));
        $this->same(5, (int) DB::value("SELECT COUNT(*) FROM device_components WHERE device_id = ? AND source = 'collector' AND removed_on IS NULL", array($id)), 'first report = parts list');
        $snap = (string) DB::value('SELECT data FROM device_hw_snapshots WHERE device_id = ? ORDER BY id DESC LIMIT 1', array($id));
        $this->notContains('keyboard', $snap, 'items outside the scope are dropped');
        $again = $this->data($this->report($token, $hw));
        $this->same(false, $again['changed']);
        $this->same(1, (int) DB::value('SELECT COUNT(*) FROM device_hw_snapshots WHERE device_id = ?', array($id)), 'an identical report stores no snapshot');

        // a disk was swapped: an anomaly, device admins and unit heads are told
        $hw['disk'] = array(array('model' => 'Samsung 870', 'serial_no' => 'D9', 'spec' => '1 TB'));
        $this->same(true, $this->data($this->report($token, $hw))['changed']);
        $a = DB::table('device_anomalies')->where('device_id', $id)->where('status', 'pending')->first();
        $this->ok($a !== null);
        $diff = json_decode($a['diff'], true);
        $this->same(array(1, 1), array(count($diff['added']), count($diff['removed'])));
        $this->same(1, $this->notes($f['uAdmin']['id'], 'device_anomaly', 'device_anomaly', $a['id']));
        $this->same(1, $this->notes($f['uUnit']['id'], 'device_anomaly', 'device_anomaly', $a['id']));
        $this->login($f['uAdmin']['username']);
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(in_array('device_anomaly', array_column($todo['rows'], 'kind'), true));
        $this->status(200, $this->request('POST', '/api/v1/device-anomalies/' . $a['id'] . '/confirm', array('note' => '硬盘升级')));
        $this->status(409, $this->request('POST', '/api/v1/device-anomalies/' . $a['id'] . '/ignore', array()), 'handled once');
        $this->ok(DB::value("SELECT removed_on FROM device_components WHERE device_id = ? AND serial_no = 'D1'", array($id)) !== null);
        $newDisk = (int) DB::value("SELECT id FROM device_components WHERE device_id = ? AND serial_no = 'D9' AND removed_on IS NULL", array($id));
        $this->same('replace', (string) DB::value('SELECT action FROM device_component_history WHERE component_id = ?', array($newDisk)));
        $this->data($this->report($token, $hw));
        $this->same(1, (int) DB::value('SELECT COUNT(*) FROM device_anomalies WHERE device_id = ?', array($id)), 'matching hardware: no new anomaly');

        // scope without network adapters: their data is dropped and they are not reported missing
        $this->status(200, $this->request('PUT', '/api/v1/collect-scope', array('items' => array('computer_name', 'os', 'cpu', 'memory', 'disk', 'board', 'monitor'))));
        $this->same(false, in_array('nic', $this->data($this->report($token, $hw))['scope'], true));
        $snap = (string) DB::value('SELECT data FROM device_hw_snapshots WHERE device_id = ? ORDER BY id DESC LIMIT 1', array($id));
        $this->notContains('AA:BB', $snap);
        $this->same(1, (int) DB::value('SELECT COUNT(*) FROM device_anomalies WHERE device_id = ?', array($id)), 'an item out of scope is not a change');
        $this->status(200, $this->request('PUT', '/api/v1/collect-scope', array('items' => array('computer_name', 'os', 'cpu', 'memory', 'disk', 'board', 'monitor', 'nic'))));
        $usage = $this->data($this->request('GET', '/api/v1/device-usage', null, array('kw' => $this->asset(5))));
        $this->ok($usage['total'] >= 5);

        // parts edited by hand so they no longer match: the next report compares again, even with the same hardware
        $this->same(true, $this->data($this->report($token, $hw))['changed'], 'network adapters are back in the report');
        $this->same(1, (int) DB::value('SELECT COUNT(*) FROM device_anomalies WHERE device_id = ?', array($id)), 'and match the recorded ones');
        DB::statement('UPDATE device_hw_snapshots SET collected_at = DATE_SUB(collected_at, INTERVAL 1 MINUTE) WHERE device_id = ?', array($id));
        $cpu = (int) DB::value("SELECT id FROM device_components WHERE device_id = ? AND ctype = 'cpu' AND removed_on IS NULL", array($id));
        $this->status(200, $this->request('POST', '/api/v1/devices/' . $id . '/parts/' . $cpu . '/replace', array('model' => 'i7-9700', 'serial_no' => 'OTHER')));
        $this->same(false, $this->data($this->report($token, $hw))['changed']);
        $a3 = (int) DB::value("SELECT id FROM device_anomalies WHERE device_id = ? AND status = 'pending'", array($id));
        $this->ok($a3 > 0, 'mismatch after a manual edit is found');
        $this->status(200, $this->request('POST', '/api/v1/device-anomalies/' . $a3 . '/confirm', array()));
        $this->same('BFEB', (string) DB::value("SELECT serial_no FROM device_components WHERE device_id = ? AND ctype = 'cpu' AND removed_on IS NULL", array($id)));

        // ignore keeps the parts; revoke stops the reports
        $hw['memory'] = array(array('model' => 'Kingston', 'serial_no' => 'M1', 'spec' => '8 GB'));
        $this->report($token, $hw);
        $a2 = (int) DB::value("SELECT id FROM device_anomalies WHERE device_id = ? AND status = 'pending'", array($id));
        $this->status(200, $this->request('POST', '/api/v1/device-anomalies/' . $a2 . '/ignore', array('note' => '内存送检')));
        $this->same(2, (int) DB::value("SELECT COUNT(*) FROM device_components WHERE device_id = ? AND ctype = 'memory' AND removed_on IS NULL", array($id)));
        $cid = (int) DB::value("SELECT id FROM device_collectors WHERE device_id = ? AND status = 'active'", array($id));
        $this->status(200, $this->request('POST', '/api/v1/device-collectors/' . $cid . '/revoke'));
        $this->status(401, $this->report($token, $hw));

        // wrong codes are throttled
        $this->cookies = array();
        for ($i = 0; $i < 9; $i++) {
            $this->request('POST', '/api/v1/collector/register', array('code' => 'BAD' . $i));
        }
        $this->status(429, $this->request('POST', '/api/v1/collector/register', array('code' => 'BAD-X')));
        $this->login($f['uAdmin']['username']);
        $this->status(200, $this->request('GET', '/api/v1/collector-client/omps-collector.ps1'));
        $this->status(404, $this->request('GET', '/api/v1/collector-client/..%2F.env'));
    }

    public function testKeyboardAndMouse()
    {
        $f = $this->f;
        $this->ok(!in_array('keyboard', CollectorService::DEFAULT_ITEMS, true) && !in_array('mouse', CollectorService::DEFAULT_ITEMS, true), 'keyboard and mouse are opt-in');
        $this->login($f['uAdmin']['username']);
        $id = $this->device(11);
        $code = $this->data($this->request('POST', '/api/v1/devices/' . $id . '/collector-code'))['code'];
        $this->cookies = array();
        $token = $this->data($this->request('POST', '/api/v1/collector/register', array('code' => $code, 'computer_name' => 'PC-0011', 'version' => '1.0')))['token'];
        $hw = array('computer_name' => 'PC-0011', 'cpu' => array(array('model' => 'i5-8500', 'serial_no' => 'C11', 'spec' => '6 cores')));
        $this->status(200, $this->report($token, $hw, 'boot'));
        $base = array('computer_name', 'os', 'cpu', 'memory', 'disk', 'board', 'monitor', 'nic');
        $this->login($f['uAdmin']['username']);
        $this->status(200, $this->request('PUT', '/api/v1/collect-scope', array('items' => array_merge($base, array('keyboard', 'mouse')))));
        $parts = function ($type) use ($id) {
            return DB::table('device_components')->where('device_id', $id)->where('ctype', $type)->whereNull('removed_on')->get();
        };
        $anomalies = function () use ($id) {
            return (int) DB::value('SELECT COUNT(*) FROM device_anomalies WHERE device_id = ?', array($id));
        };
        try {
            // a client from before 1.1 sends no keyboard or mouse: nothing is missing, and the list asks for an update
            $this->same(false, $this->data($this->report($token, $hw))['changed']);
            $this->same(0, count($parts('keyboard')));
            $rows = $this->data($this->request('GET', '/api/v1/device-collectors', null, array('status' => 'active', 'page' => 1)))['rows'];
            $mine = array_values(array_filter($rows, function ($r) use ($id) { return $r['device_id'] === $id; }));
            $this->same(true, $mine[0]['outdated'], 'version 1.0 needs an update');

            // the updated client: the keyboard and mouse found become parts without an anomaly
            $hw['keyboard'] = array(array('model' => 'USB Keyboard', 'serial_no' => null, 'spec' => 'USB 1C4F:0002'));
            $hw['mouse'] = array(array('model' => 'MX Master 3', 'serial_no' => 'D4:F5:47:0E:1A:2B', 'spec' => 'Bluetooth 046D:B023'));
            $this->same(true, $this->data($this->report($token, $hw))['changed']);
            $this->same(array(1, 1, 0), array(count($parts('keyboard')), count($parts('mouse')), $anomalies()), 'registered as found');
            $this->same('USB 1C4F:0002', (string) $parts('keyboard')[0]['spec']);
            $this->same('D4:F5:47:0E:1A:2B', (string) $parts('mouse')[0]['serial_no']);

            // a keyboard of another model: an anomaly
            $hw['keyboard'] = array(array('model' => 'Logitech K120', 'serial_no' => null, 'spec' => 'USB 046D:C31C'));
            $this->report($token, $hw);
            $a = DB::table('device_anomalies')->where('device_id', $id)->where('status', 'pending')->first();
            $diff = json_decode($a['diff'], true);
            $this->same(array('keyboard', 'keyboard'), array($diff['added'][0]['ctype'], $diff['removed'][0]['ctype']));
            $this->status(200, $this->request('POST', '/api/v1/device-anomalies/' . $a['id'] . '/confirm', array()));
            $this->same('Logitech K120', (string) $parts('keyboard')[0]['model']);

            // a mouse of the same model but another serial number: found as well
            $hw['mouse'][0]['serial_no'] = 'D4:F5:47:0E:99:99';
            $this->report($token, $hw);
            $this->same(2, $anomalies(), 'a mouse with another serial number is a change');

            // the keyboard and mouse taken out of the scope again: not reported missing
            $this->status(200, $this->request('PUT', '/api/v1/collect-scope', array('items' => $base)));
            $this->report($token, $hw);
            $this->same(2, $anomalies());
        } finally {
            $this->request('PUT', '/api/v1/collect-scope', array('items' => $base));
        }
    }

    public function testImportAndExport()
    {
        $f = $this->f;
        $this->login($f['uAdmin']['username']);
        $this->status(200, $this->request('GET', '/api/v1/device-import/template'));
        $this->device(6);
        $wangNo = (string) DB::value('SELECT staff_no FROM personnel_profiles WHERE id = ?', array($f['wang']));
        $csv = "设备编号*,设备名称*,类别*,所属部门,使用人,购置日期,价格（元）\n" .
            $this->asset(7) . ",打印机 A,打印机,信息科" . $f['s'] . ",,2024/3/1,1800\n" .
            $this->asset(6) . ",重复编号,台式电脑,,,,\n" .
            $this->asset(8) . ",未知类别,汽车,,,,\n" .
            $this->asset(9) . ",笔记本,Laptop,,无此人,,\n" .
            $this->asset(10) . ",王晓的笔记本,笔记本电脑,," . $wangNo . ",,\n";
        $r = $this->upload('/api/v1/device-import/check', array($this->tmp('devices.csv', "\xEF\xBB\xBF" . $csv)));
        $this->status(200, $r);
        $res = $this->data($r);
        $this->same(array(2, 3), array($res['ok'], $res['bad']));
        $this->status(200, $this->request('POST', '/api/v1/device-import/run', array('token' => $res['token'])));
        $d = DB::table('devices')->where('asset_no', $this->asset(7))->first();
        $this->same(array('printer', '2024-03-01', '1800.00', $f['tech']), array((string) $d['category'], (string) $d['purchase_date'], (string) $d['price'], (int) $d['dept_id']));
        $this->same($f['tech'], (int) DB::value('SELECT dept_id FROM devices WHERE asset_no = ?', array($this->asset(10))), 'department from the holder');
        $this->status(200, $this->request('GET', '/api/v1/device-export'));
        $this->login($f['uWang']['username']);
        $this->status(403, $this->request('GET', '/api/v1/device-export'));
    }
}
