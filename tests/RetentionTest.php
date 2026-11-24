<?php
namespace Omps\Tests;

use Omps\Core\Settings;
use Omps\Database\DB;
use Omps\Engines\Backup;
use Omps\Engines\Retention;

/**
 * 数据备份 → 自动清理: logs and audit logs past their kept period are deleted, business data and
 * what the hardware comparison still needs are kept; the periods are chosen from a fixed list.
 */
class RetentionTest extends TestCase
{
    public function testOldLogsGoAndWhatIsNeededStays()
    {
        $old = date('Y-m-d H:i:s', strtotime('-2 months'));
        $older = date('Y-m-d H:i:s', strtotime('-8 months'));
        $now = now();
        $mark = 'ret' . substr(md5(uniqid('', true)), 0, 8);
        $op = function ($at) use ($mark) {
            return DB::table('operation_logs')->insert(array('module' => 'test', 'action' => $mark, 'created_at' => $at));
        };
        $opOld = $op($old);
        $opNew = $op($now);
        $auditOld = DB::table('audit_logs')->insert(array('module' => 'test', 'action' => $mark, 'created_at' => $older));
        $login = DB::table('login_logs')->insert(array('username' => $mark, 'result' => 'success', 'created_at' => $old));
        $dev = 900000 + mt_rand(1, 99999);
        $snap = function ($at) use ($dev) {
            return DB::table('device_hw_snapshots')->insert(array('collector_id' => 1, 'device_id' => $dev, 'collected_at' => $at, 'data' => '{}', 'data_hash' => str_repeat('0', 64)));
        };
        $s1 = $snap($older);
        $s2 = $snap($old);
        $s3 = $snap($old); // the latest of this device: kept although old
        DB::table('device_anomalies')->insert(array('device_id' => $dev, 'snapshot_id' => $s2, 'diff' => '{}', 'status' => 'pending', 'created_at' => $old));
        $uid = (int) $this->user('member')['id'];
        $readOld = DB::table('notifications')->insert(array('user_id' => $uid, 'type' => 'test', 'created_at' => $older, 'read_at' => $older));
        $unreadOld = DB::table('notifications')->insert(array('user_id' => $uid, 'type' => 'test', 'created_at' => $older));
        $has = function ($table, $id) {
            return DB::table($table)->where('id', $id)->exists();
        };

        // nothing chosen yet: logs are kept for ever; read notifications still go after 6 months
        Settings::set('retain_logs_months', 0);
        Settings::set('retain_audit_months', 0);
        Retention::run();
        $this->same(array(true, true, true, false, true), array($has('operation_logs', $opOld), $has('audit_logs', $auditOld), $has('device_hw_snapshots', $s1),
            $has('notifications', $readOld), $has('notifications', $unreadOld)), 'for ever by default');

        try {
            // logs 1 month: old logs go; the latest snapshot and the one a hardware change shows stay
            Settings::set('retain_logs_months', 1);
            $out = Retention::run();
            $this->ok($out['operation_logs'] >= 1 && $out['login_logs'] >= 1);
            $this->same(array(false, true, false), array($has('operation_logs', $opOld), $has('operation_logs', $opNew), $has('login_logs', $login)));
            $this->same(array(false, true, true), array($has('device_hw_snapshots', $s1), $has('device_hw_snapshots', $s2), $has('device_hw_snapshots', $s3)), 'snapshots');
            $this->same(true, $has('audit_logs', $auditOld), 'audit logs have their own period');
            $this->same(true, $has('notifications', $unreadOld), 'unread notifications are kept');

            Settings::set('retain_audit_months', 6);
            Retention::run();
            $this->same(false, $has('audit_logs', $auditOld));

            // once a day from the scheduler
            Settings::set('retention_last_day', '');
            $this->ok(Retention::runIfDue() !== null);
            $this->same(null, Retention::runIfDue(), 'second run the same day');

            // the page: only listed periods; a shorter period applies at once; backups may be kept for ever
            $this->login($this->user('super')['username']);
            $this->status(422, $this->request('PUT', '/api/v1/backups/settings', array('auto' => true, 'time' => '02:00', 'keep_days' => 14, 'logs_months' => 5, 'audit_months' => 0)));
            $this->status(422, $this->request('PUT', '/api/v1/backups/settings', array('auto' => true, 'time' => '02:00', 'keep_days' => 400, 'logs_months' => 0, 'audit_months' => 0)));
            $d = $this->data($this->request('PUT', '/api/v1/backups/settings', array('auto' => true, 'time' => '02:00', 'keep_days' => 0, 'logs_months' => 12, 'audit_months' => 36)));
            $this->same(array(0, 12, 36), array($d['settings']['keep_days'], $d['settings']['logs_months'], $d['settings']['audit_months']));
            $this->same(Retention::MONTHS, $d['choices']['months']);
            $this->ok($d['sizes'] === null || $d['sizes']['total'] > 0);
            $this->same(0, Backup::prune(), 'backups kept for ever');
        } finally {
            Settings::set('retain_logs_months', 0);
            Settings::set('retain_audit_months', 0);
            Settings::set('backup_keep_days', 14);
        }
    }
}
