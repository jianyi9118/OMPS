<?php
namespace Omps\Console;

use Omps\Core\Lang;
use Omps\Core\Modules;

/**
 * `php omps i18n:check` (design §16):
 *  1. every language pack has exactly the same keys, none empty;
 *  2. every translation key used in PHP and JavaScript exists (static keys, plus the
 *     dynamic key families listed below);
 *  3. no hard-coded Chinese text outside the language packs (PHP strings, views, JS).
 */
class I18nCheck
{
    public static function run()
    {
        $problems = array();
        $dirs = glob(OMPS_ROOT . '/resources/languages/*', GLOB_ONLYDIR);
        $packs = array();
        foreach ($dirs === false ? array() : $dirs as $d) {
            $packs[basename($d)] = Lang::loadPack(basename($d));
        }
        $base = (string) config('app.base_language', 'zh-CN');
        if (!isset($packs[$base])) {
            Console::line('Base language pack missing: ' . $base);
            return 1;
        }
        $baseKeys = array_keys($packs[$base]);
        foreach ($packs as $code => $p) {
            foreach (array_diff($baseKeys, array_keys($p)) as $k) {
                $problems[] = $code . ': missing key ' . $k;
            }
            foreach (array_diff(array_keys($p), $baseKeys) as $k) {
                $problems[] = $code . ': extra key ' . $k . ' (not in ' . $base . ')';
            }
            foreach ($p as $k => $v) {
                if (trim($v) === '') {
                    $problems[] = $code . ': empty text for ' . $k;
                }
            }
        }
        // namespaces (first key segment) that count as translation keys
        $ns = array();
        foreach ($baseKeys as $k) {
            $ns[substr($k, 0, (int) strpos($k . '.', '.'))] = true;
        }
        $used = array_merge(self::staticKeys($ns), self::dynamicKeys());
        foreach (array_unique($used) as $k) {
            if (!isset($packs[$base][$k])) {
                $problems[] = 'key used in code but missing from packs: ' . $k;
            }
        }
        foreach (self::hardcoded() as $h) {
            $problems[] = $h;
        }
        Console::line('i18n:check: ' . count($packs) . ' language(s), ' . count($baseKeys) . ' keys, ' . count(array_unique($used)) . ' keys referenced');
        foreach ($problems as $p) {
            Console::line('  ' . $p);
        }
        Console::line($problems ? count($problems) . ' problem(s).' : 'No problems found.');
        return $problems ? 1 : 0;
    }

    private static function sourceFiles($ext)
    {
        $out = array();
        foreach (array('app', 'resources/views', 'public/assets/js', 'config') as $r) {
            $dir = OMPS_ROOT . '/' . $r;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = str_replace('\\', '/', $f->getPathname());
                if (substr($p, -strlen($ext)) === $ext && strpos($p, '/app/Console/') === false) {
                    $out[] = $p;
                }
            }
        }
        sort($out);
        return $out;
    }

    /** Quoted strings shaped like keys whose namespace exists in the packs. */
    private static function staticKeys(array $ns)
    {
        $keys = array();
        foreach (array_merge(self::sourceFiles('.php'), self::sourceFiles('.js')) as $f) {
            if (substr($f, -8) === 'icons.js') {
                continue;
            }
            $src = (string) file_get_contents($f);
            // a quoted key followed by "+" or "." is only a prefix of a dynamic key (checked in dynamicKeys)
            if (preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"](?!\s*[+.])/', $src, $m)) {
                foreach ($m[1] as $k) {
                    $first = substr($k, 0, (int) strpos($k, '.'));
                    if (isset($ns[$first]) && !preg_match('/\.(php|js|css|json|html|xml|rels)$/', $k) && substr($k, -1) !== '.') {
                        $keys[] = $k;
                    }
                }
            }
        }
        return self::filterNonKeys($keys);
    }

    /** Strings that look like keys but are permission codes, config keys or similar. */
    private static function filterNonKeys(array $keys)
    {
        $perm = array();
        foreach (Modules::collect('permissions.php') as $p) {
            $perm[$p['code']] = true;
        }
        $out = array();
        foreach ($keys as $k) {
            if (isset($perm[$k]) || preg_match('/^(app|database|security)\./', $k)) {
                continue;
            }
            $out[] = $k;
        }
        return $out;
    }

    /** Key families built at run time. */
    private static function dynamicKeys()
    {
        $k = array();
        foreach (array('super', 'unit_head', 'dept_head', 'member') as $r) {
            $k[] = 'role.' . $r;
        }
        foreach (Modules::collect('permissions.php') as $p) {
            if (!isset($p['type']) || $p['type'] !== 'technical') {
                $k[] = 'perm.group.' . $p['group'];
            }
        }
        foreach (\Omps\Modules\System\Services\RoleService::MATRIX_ACTIONS as $a) {
            $k[] = 'perm.action.' . $a;
        }
        foreach (Modules::collect('menus.php') as $m) {
            $k[] = $m['title'];
        }
        foreach (array('all', 'unit', 'dept', 'self') as $s) {
            $k[] = 'scope.' . $s;
        }
        foreach (array('success', 'fail', 'locked', 'disabled', 'ip_blocked', 'logout') as $s) {
            $k[] = 'log.login.' . $s;
        }
        foreach (array('no_user', 'bad_password', 'disabled', 'locked', 'too_many_failures', 'ip_limit') as $s) {
            $k[] = 'log.reason.' . $s;
        }
        foreach (array('operation', 'audit', 'login') as $s) {
            $k[] = 'log.tab.' . $s;
        }
        foreach (array('active', 'disabled', 'left', 'retired') as $s) {
            $k[] = 'staff.status.' . $s;
        }
        foreach (array('join', 'transfer', 'move_out', 'promote', 'leave', 'retire') as $s) {
            $k[] = 'staff.change.' . $s;
        }
        foreach (array('leave', 'retire', 'move_out') as $s) {
            $k[] = 'staff.remove.' . $s;
        }
        foreach (array('pending', 'approved', 'returned', 'withdrawn') as $s) {
            $k[] = 'review.status.' . $s;
        }
        foreach (array('create', 'update', 'delete') as $s) {
            $k[] = 'review.action.' . $s;
            $k[] = 'log.action.record_' . $s;
        }
        foreach (array('photo', 'education', 'title', 'achievement', 'other') as $s) {
            $k[] = 'file.group.' . $s;
        }
        foreach (\Omps\Modules\Plan\Services\PlanService::LEVELS as $s) {
            $k[] = 'plan.level.' . $s;
        }
        foreach (array('high', 'medium', 'low') as $s) {
            $k[] = 'plan.priority.' . $s;
        }
        for ($i = 1; $i <= 4; $i++) {
            $k[] = 'plan.tl.q' . $i;
        }
        for ($i = 0; $i < 7; $i++) {
            $k[] = 'common.wd' . $i;
        }
        foreach (array_merge(array('none'), \Omps\Modules\Performance\Services\PerformanceService::STATUSES) as $s) {
            $k[] = 'perf.status.' . $s;
        }
        foreach (array('submitted', 'approved', 'returned', 'withdrawn') as $s) {
            $k[] = 'perf.state_note.' . $s;
        }
        foreach (array('save', 'submit', 'self_approve', 'withdraw', 'approve', 'edit_approve', 'return') as $s) {
            $k[] = 'perf.kind.' . $s;
        }
        foreach (array('gen', 'manual') as $s) {
            $k[] = 'report.kind.' . $s;
        }
        // phase 5: notifications, to-dos, notebook, issued items, reminder rules
        foreach (\Omps\Engines\Notify::TYPES as $s) {
            $k[] = 'notify.msg.' . $s;
        }
        foreach (\Omps\Engines\Notify::LEVELS as $s) {
            $k[] = 'notify.level.' . $s;
        }
        foreach (\Omps\Modules\Affairs\Services\TodoService::KINDS as $s) {
            $k[] = 'todo.kind.' . $s;
            $k[] = 'todo.msg.' . $s;
        }
        foreach (\Omps\Modules\Affairs\Services\NoteService::STATUSES as $s) {
            $k[] = 'note.status.' . $s;
            $k[] = 'note.in_status.' . $s;
        }
        foreach (\Omps\Modules\Affairs\Services\IssueService::KINDS as $s) {
            $k[] = 'issue.kind.' . $s;
        }
        foreach (\Omps\Modules\Affairs\Services\IssueService::FREQS as $s) {
            $k[] = 'issue.freq.' . $s;
        }
        foreach (\Omps\Modules\Affairs\Services\RuleService::RULES as $code => $meta) {
            $k[] = 'rule.code.' . $code;
            $k[] = 'rule.desc.' . $code;
            $k[] = 'rule.base.' . $code;
            $k[] = 'rule.who.' . $meta['who'];
            if ($meta['esc'] !== null) {
                $k[] = 'rule.esc_on.' . $meta['esc'];
            }
        }
        foreach (\Omps\Modules\Affairs\Services\ReminderCalc::MODES as $s) {
            $k[] = 'rule.mode.' . $s;
        }
        foreach (\Omps\Modules\Affairs\Services\ReminderCalc::UNITS as $s) {
            $k[] = 'rule.unit.' . $s;
        }
        foreach (\Omps\Modules\Affairs\Services\ReminderCalc::REPEATS as $s) {
            $k[] = 'rule.repeat.' . $s;
        }
        foreach (array('wday', 'day') as $s) {
            $k[] = 'rule.say.after_' . $s;
        }
        // phase 6: devices
        foreach (\Omps\Modules\Device\Services\DeviceService::STATUSES as $s) {
            $k[] = 'device.status.' . $s;
        }
        foreach (\Omps\Modules\Device\Services\DeviceService::CTYPES as $s) {
            $k[] = 'device.ctype.' . $s;
        }
        foreach (\Omps\Modules\Device\Services\RepairService::STATUSES as $s) {
            $k[] = 'device.fault_status.' . $s;
        }
        foreach (\Omps\Modules\Device\Services\CollectorService::ITEMS as $s) {
            $k[] = 'device.item.' . $s;
            $k[] = 'device.item_desc.' . $s;
        }
        foreach (array('register', 'boot', 'daily', 'manual') as $s) {
            $k[] = 'device.event.' . $s;
        }
        foreach (array('install', 'replace', 'remove') as $s) {
            $k[] = 'device.hist.' . $s;
        }
        foreach (array('pending', 'confirmed', 'ignored') as $s) {
            $k[] = 'device.anomaly.' . $s;
        }
        foreach (array('usage', 'anomaly', 'clients', 'scope') as $s) {
            $k[] = 'device.mon.' . $s;
        }
        foreach (array('device_record', 'device_repair') as $s) {
            $k[] = 'file.folder.' . $s;
        }
        // phase 7: custom fields, statistics, theme
        foreach (\Omps\Engines\CustomFields::ENTITIES as $s) {
            $k[] = 'cf.entity.' . $s;
        }
        foreach (\Omps\Engines\CustomFields::TYPES as $s) {
            $k[] = 'cf.type.' . $s;
        }
        foreach (array('required', 'searchable', 'statable', 'in_list', 'enabled') as $s) {
            $k[] = 'cf.flag.' . $s;
        }
        foreach (\Omps\Modules\Stats\Services\StatEngine::ENTITIES as $s) {
            $k[] = 'stat.entity.' . $s;
            foreach (\Omps\Modules\Stats\Services\StatEngine::DIMS[$s] as $d) {
                $k[] = 'stat.dim.' . $d;
            }
            foreach (\Omps\Modules\Stats\Services\StatEngine::MEASURES[$s] as $m) {
                $k[] = 'stat.measure.' . $m;
            }
        }
        foreach (\Omps\Modules\Stats\Services\StatEngine::AGE_BANDS as $s) {
            $k[] = 'stat.age.' . $s;
        }
        foreach (array('active', 'done', 'cancelled') as $s) {
            $k[] = 'stat.plan_status.' . $s;
        }
        foreach (\Omps\Modules\Stats\Services\StatEngine::CHARTS as $s) {
            $k[] = 'sd.chart.' . $s;
        }
        foreach (array('active', 'all') as $s) {
            $k[] = 'sd.only.' . $s;
        }
        foreach (array('people', 'male', 'female', 'avg_age', 'hired_year') as $s) {
            $k[] = 'stat.kpi.' . $s;
        }
        foreach (array('fields', 'stats', 'theme', 'banner', 'docs') as $s) {
            $k[] = 'custom.tab.' . $s;
        }
        // phase 9: file manager, languages, backups
        foreach (array('all', 'mine', 'dup', 'trash') as $s) {
            $k[] = 'fm.tab.' . $s;
        }
        foreach (array_keys(\Omps\Engines\Files::RESTORABLE) as $s) {
            $k[] = 'fm.biz.' . $s;
        }
        foreach (array('langs', 'entries') as $s) {
            $k[] = 'i18n.tab.' . $s;
        }
        foreach (array_merge(\Omps\Engines\Backup::KINDS, array('external')) as $s) {
            $k[] = 'backup.kind.' . $s;
        }
        // phase 8: document types and placeholder names
        foreach (\Omps\Engines\Documents::TYPES as $type => $def) {
            $k[] = 'doc.type.' . $type;
            foreach (array_merge($def['fields'], $def['blocks'], $def['images']) as $s) {
                $k[] = \Omps\Engines\Documents::phKey($type, $s);
            }
            foreach ($def['lists'] as $list => $cols) {
                $k[] = \Omps\Engines\Documents::phKey($type, $list);
                foreach ($cols as $c) {
                    $k[] = \Omps\Engines\Documents::phKey($type, $c, $list);
                }
            }
        }
        foreach (array_keys(\Omps\Modules\Custom\Services\ThemeService::PRESETS) as $s) {
            $k[] = 'theme.color.' . $s;
        }
        foreach (\Omps\Modules\Custom\Services\ThemeService::MODES as $s) {
            $k[] = 'theme.mode.' . $s;
        }
        foreach (array('male', 'female') as $s) {
            $k[] = 'gender.' . $s;
        }
        foreach (array('login_max_failures', 'login_lock_minutes', 'session_idle_minutes', 'session_absolute_hours', 'password_min_length') as $s) {
            $k[] = 'settings.' . $s;
        }
        $base = require OMPS_ROOT . '/database/seeds/base.php';
        foreach ($base['dictionaries'] as $code => $items) {
            $k[] = 'dict.' . $code;
            foreach ($items as $it) {
                $k[] = 'dict.' . $code . '.' . $it;
            }
        }
        // audit module and action codes
        foreach (self::sourceFiles('.php') as $f) {
            $src = (string) file_get_contents($f);
            if (preg_match_all('/Audit::(?:change|op|log)\(\s*\$[\w\->\[\]\']+\s*,\s*\'([a-z_]+)\'\s*,\s*\'([a-z_]+)\'\s*,/', $src, $m)) {
                foreach ($m[1] as $i => $mod) {
                    $k[] = 'log.module.' . $mod;
                    $k[] = 'log.action.' . $m[2][$i];
                }
            }
            if (preg_match_all('/Audit::log\(null,\s*\'([a-z_]+)\'\s*,\s*\'([a-z_]+)\'/', $src, $m)) {
                foreach ($m[1] as $i => $mod) {
                    $k[] = 'log.module.' . $mod;
                    $k[] = 'log.action.' . $m[2][$i];
                }
            }
            if (preg_match_all('/\'person_\'\s*\.\s*\$type/', $src)) {
                foreach (array('transfer', 'promote', 'leave', 'retire', 'move_out') as $t) {
                    $k[] = 'log.action.person_' . $t;
                }
            }
        }
        $k[] = 'log.module.security';
        $k[] = 'log.action.access_denied';
        return $k;
    }

    /** Hard-coded CJK text outside language packs. */
    private static function hardcoded()
    {
        $out = array();
        $cjk = '/[\x{3000}-\x{303F}\x{3400}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';
        foreach (self::sourceFiles('.php') as $f) {
            foreach (token_get_all((string) file_get_contents($f)) as $t) {
                if (is_array($t) && in_array($t[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML), true) && preg_match($cjk, $t[1])) {
                    $out[] = self::rel($f) . ':' . $t[2] . '  hard-coded text: ' . mb_substr(trim($t[1]), 0, 40);
                }
            }
        }
        foreach (self::sourceFiles('.js') as $f) {
            $src = self::stripJsComments((string) file_get_contents($f));
            $lines = explode("\n", $src);
            foreach ($lines as $i => $line) {
                if (preg_match($cjk, $line)) {
                    $out[] = self::rel($f) . ':' . ($i + 1) . '  hard-coded text: ' . mb_substr(trim($line), 0, 60);
                }
            }
        }
        return $out;
    }

    private static function stripJsComments($src)
    {
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        return preg_replace('#(^|[^:"\'\\\\])//[^\n]*#', '$1', (string) $src);
    }

    private static function rel($f)
    {
        return ltrim(substr($f, strlen(str_replace('\\', '/', OMPS_ROOT))), '/');
    }
}
