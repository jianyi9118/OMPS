<?php
namespace Omps\Engines;

use Omps\Core\Settings;
use Omps\Database\DB;

/**
 * Automatic clean-up of data that only grows (数据备份 → 自动清理). Two periods, both "keep for
 * ever" until a super administrator chooses one:
 *  - logs: operation and login logs, device usage records, old hardware snapshots (the latest one of
 *    every device and those an unhandled hardware change points to are kept), read notifications
 *    (read notifications never stay longer than 6 months, as before);
 *  - audit logs (who changed what, who viewed full ID numbers): its own period, for accountability.
 * Business records (plans, results, people, devices, issues...) are never deleted here. Backups have
 * their own period (Backup::prune). Runs at most once a day from the scheduler; rows go in batches
 * so the tables are never locked for long.
 */
class Retention
{
    /** Months to keep logs / audit logs; 0 = for ever. */
    const MONTHS = array(1, 3, 6, 12, 36, 0);
    /** Days to keep automatic backups; 0 = for ever. */
    const BACKUP_DAYS = array(7, 14, 30, 90, 180, 365, 1095, 0);
    const NOTICE_MONTHS = 6;
    const BATCH = 5000;

    public static function logsMonths()
    {
        return self::pick((int) Settings::get('retain_logs_months', 0), self::MONTHS);
    }

    public static function auditMonths()
    {
        return self::pick((int) Settings::get('retain_audit_months', 0), self::MONTHS);
    }

    private static function pick($v, array $allowed)
    {
        return in_array($v, $allowed, true) ? $v : 0;
    }

    private static function cutoff($months, $now)
    {
        return date('Y-m-d H:i:s', strtotime('-' . (int) $months . ' months', $now));
    }

    /** Once a day (called from the scheduler run, web or task). */
    public static function runIfDue($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $day = date('Y-m-d', $now);
        if ((string) Settings::get('retention_last_day', '') === $day) {
            return null;
        }
        Settings::set('retention_last_day', $day, null);
        return self::run($now);
    }

    /** @return array table => rows deleted */
    public static function run($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $out = array();
        $logs = self::logsMonths();
        if ($logs > 0) {
            $limit = self::cutoff($logs, $now);
            $out['operation_logs'] = self::purge('operation_logs', 'created_at < ?', array($limit));
            $out['login_logs'] = self::purge('login_logs', 'created_at < ?', array($limit));
            if (self::exists('device_usage_logs')) {
                $out['device_usage_logs'] = self::purge('device_usage_logs', 'created_at < ?', array($limit));
            }
            if (self::exists('device_hw_snapshots')) {
                // keep the latest snapshot of each device (the next report is compared with it) and
                // the ones a hardware change record still shows
                $keep = array();
                foreach (DB::select('SELECT MAX(id) AS id FROM device_hw_snapshots GROUP BY device_id') as $r) {
                    $keep[] = (int) $r['id'];
                }
                foreach (DB::select('SELECT DISTINCT snapshot_id AS id FROM device_anomalies WHERE snapshot_id IS NOT NULL') as $r) {
                    $keep[] = (int) $r['id'];
                }
                $keep = $keep ? array_values(array_unique($keep)) : array(0);
                $out['device_hw_snapshots'] = self::purge('device_hw_snapshots', 'collected_at < ? AND id NOT IN (' . implode(',', $keep) . ')', array($limit));
            }
        }
        // read notifications: the logs period, but never longer than 6 months
        $months = $logs > 0 ? min($logs, self::NOTICE_MONTHS) : self::NOTICE_MONTHS;
        $out['notifications'] = self::purge('notifications', 'read_at IS NOT NULL AND created_at < ?', array(self::cutoff($months, $now)));
        $audit = self::auditMonths();
        if ($audit > 0) {
            $out['audit_logs'] = self::purge('audit_logs', 'created_at < ?', array(self::cutoff($audit, $now)));
        }
        $total = array_sum($out);
        Settings::set('retention_last_run', array('at' => date('Y-m-d H:i:s', $now), 'rows' => $total), null);
        if ($total > 0) {
            Audit::op(null, 'backup', 'data_purged', 'settings', null, null, $out, 'system');
        }
        return $out;
    }

    private static function purge($table, $where, array $params)
    {
        $n = 0;
        do {
            $k = DB::statement('DELETE FROM `' . $table . '` WHERE ' . $where . ' LIMIT ' . self::BATCH, $params);
            $n += $k;
        } while ($k >= self::BATCH);
        return $n;
    }

    private static function exists($table)
    {
        // $table is one of the constant names above, never user input
        return (bool) DB::select("SHOW TABLES LIKE '" . $table . "'");
    }

    /** Size of the database and of the tables the clean-up looks after (for the backup page). */
    public static function sizes()
    {
        $groups = array('logs' => array('operation_logs', 'login_logs', 'device_usage_logs', 'device_hw_snapshots', 'notifications'), 'audit' => array('audit_logs'));
        $out = array('total' => 0, 'logs' => 0, 'audit' => 0);
        try {
            foreach (DB::select('SELECT table_name AS t, data_length + index_length AS b FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
                $b = (float) $r['b'];
                $out['total'] += $b;
                foreach ($groups as $g => $tables) {
                    if (in_array(strtolower((string) $r['t']), $tables, true)) {
                        $out[$g] += $b;
                    }
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        return $out;
    }
}
