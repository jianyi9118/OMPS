<?php
namespace Omps\Database;

use Omps\Core\Modules;

/**
 * Syncs base data: languages, built-in roles, permissions, menus, dictionaries, module registry.
 * Safe to run any number of times.
 */
class Seeder
{
    /** @var callable|null */
    private $out;

    public function __construct($out = null)
    {
        $this->out = $out;
    }

    private function say($msg)
    {
        if ($this->out !== null) {
            call_user_func($this->out, $msg);
        }
    }

    public function run()
    {
        $base = require OMPS_ROOT . '/database/seeds/base.php';
        DB::transaction(function () use ($base) {
            $this->languages($base['languages']);
            $this->permissions();
            $this->roles($base['roles']);
            $this->menus();
            $this->dictionaries($base['dictionaries']);
            $this->policies(isset($base['field_policies']) ? $base['field_policies'] : array());
            $this->reminders();
            $this->modules();
        });
    }

    private function languages(array $langs)
    {
        $hasDefault = DB::table('languages')->where('is_default', 1)->exists();
        foreach ($langs as $l) {
            if (!DB::table('languages')->where('code', $l['code'])->exists()) {
                DB::table('languages')->insert(array('code' => $l['code'], 'name' => $l['name'], 'enabled' => $l['enabled'],
                    'is_default' => $hasDefault ? 0 : $l['is_default'], 'sort' => $l['sort'], 'created_at' => now()));
                $this->say('language ' . $l['code']);
            }
        }
    }

    private function permissions()
    {
        $sort = 0;
        foreach (Modules::collect('permissions.php') as $p) {
            $sort += 10;
            $type = isset($p['type']) ? $p['type'] : 'action';
            $row = DB::table('permissions')->where('code', $p['code'])->first();
            $data = array('module' => $p['module'], 'action' => $p['action'], 'type' => $type, 'sort' => $sort);
            if ($row) {
                DB::table('permissions')->where('id', (int) $row['id'])->update($data);
            } else {
                $pid = DB::table('permissions')->insert(array_merge(array('code' => $p['code']), $data));
                $this->say('permission ' . $p['code']);
                // a permission added by a later module version goes to the built-in roles it names,
                // only when it first appears (later changes in Roles & permissions are kept)
                if (!empty($p['roles'])) {
                    foreach (DB::table('roles')->whereIn('code', $p['roles'])->pluck('id') as $rid) {
                        DB::table('role_permissions')->insert(array('role_id' => (int) $rid, 'permission_id' => $pid));
                    }
                }
            }
        }
    }

    private function roles(array $roles)
    {
        foreach ($roles as $r) {
            if (DB::table('roles')->where('code', $r['code'])->exists()) {
                continue;
            }
            $id = DB::table('roles')->insert(array('code' => $r['code'], 'name_key' => 'role.' . $r['code'], 'is_builtin' => 1, 'role_rank' => $r['rank'],
                'scope' => $r['scope'], 'sort' => $r['sort'], 'created_at' => now()));
            $codes = $r['perms'] === array('*')
                ? DB::table('permissions')->whereIn('type', array('action', 'sensitive'))->pluck('code')
                : $r['perms'];
            if ($r['perms'] !== array('*')) {
                foreach (Modules::collect('permissions.php') as $p) {
                    if (!empty($p['roles']) && in_array($r['code'], $p['roles'], true)) {
                        $codes[] = $p['code'];
                    }
                }
                $codes = array_values(array_unique($codes));
            }
            if ($codes) {
                foreach (DB::table('permissions')->whereIn('code', $codes)->pluck('id') as $pid) {
                    DB::table('role_permissions')->insert(array('role_id' => $id, 'permission_id' => (int) $pid));
                }
            }
            $this->say('role ' . $r['code']);
        }
    }

    private function menus()
    {
        $items = Modules::collect('menus.php');
        // parents first
        usort($items, function ($a, $b) {
            $pa = $a['parent'] === null ? 0 : 1;
            $pb = $b['parent'] === null ? 0 : 1;
            return $pa - $pb;
        });
        foreach ($items as $m) {
            $parentId = null;
            if ($m['parent'] !== null) {
                $parentId = (int) DB::value('SELECT id FROM menus WHERE code = ?', array($m['parent']));
            }
            $data = array('parent_id' => $parentId, 'title_key' => $m['title'], 'icon' => $m['icon'], 'route' => $m['route'], 'permission_code' => $m['perm'],
                'requires_device_admin' => empty($m['device_admin']) ? 0 : 1, 'module' => $m['module'], 'sort' => (int) $m['sort']);
            if (DB::table('menus')->where('code', $m['code'])->exists()) {
                DB::table('menus')->where('code', $m['code'])->update($data);
            } else {
                DB::table('menus')->insert(array_merge(array('code' => $m['code'], 'enabled' => 1), $data));
                $this->say('menu ' . $m['code']);
            }
        }
    }

    private function dictionaries(array $dicts)
    {
        foreach ($dicts as $code => $items) {
            $id = DB::value('SELECT id FROM dictionaries WHERE code = ?', array($code));
            if ($id === null) {
                $id = DB::table('dictionaries')->insert(array('code' => $code, 'name_key' => 'dict.' . $code, 'is_builtin' => 1));
            }
            $sort = 0;
            foreach ($items as $it) {
                $sort += 10;
                if (!DB::table('dictionary_items')->where('dict_id', (int) $id)->where('code', $it)->exists()) {
                    DB::table('dictionary_items')->insert(array('dict_id' => (int) $id, 'code' => $it, 'name_key' => 'dict.' . $code . '.' . $it, 'sort' => $sort));
                }
            }
        }
    }

    private function policies(array $list)
    {
        if (!$list || !DB::select("SHOW TABLES LIKE 'profile_field_policies'")) {
            return;
        }
        foreach ($list as $p) {
            if (!DB::table('profile_field_policies')->where('entity', $p[0])->where('field_key', $p[1])->exists()) {
                DB::table('profile_field_policies')->insert(array('entity' => $p[0], 'field_key' => $p[1], 'self_edit' => $p[2], 'admin_only' => $p[2] === 'forbid' ? 1 : 0));
            }
        }
    }

    /** Default reminder rules (module file reminders.php); existing rules are never changed. */
    private function reminders()
    {
        $list = Modules::collect('reminders.php');
        if (!$list || !DB::select("SHOW TABLES LIKE 'reminder_rules'")) {
            return;
        }
        foreach ($list as $r) {
            if (DB::table('reminder_rules')->where('code', $r['code'])->exists()) {
                continue;
            }
            $id = DB::table('reminder_rules')->insert(array('code' => $r['code'], 'level' => $r['level'], 'max_repeat' => (int) $r['max_repeat'],
                'escalate' => (int) $r['escalate'], 'enabled' => 1, 'sort' => (int) $r['sort']));
            $sort = 0;
            foreach ($r['triggers'] as $t) {
                $sort += 10;
                DB::table('reminder_triggers')->insert(array('rule_id' => $id, 'mode' => $t['mode'], 'amount' => isset($t['amount']) ? (int) $t['amount'] : 0,
                    'unit' => isset($t['unit']) ? $t['unit'] : null, 'time_of_day' => isset($t['time']) ? $t['time'] : null,
                    'weekday' => isset($t['weekday']) ? (int) $t['weekday'] : null, 'month_day' => isset($t['month_day']) ? (int) $t['month_day'] : null,
                    'repeat_mode' => isset($t['repeat']) ? $t['repeat'] : null, 'sort' => $sort));
            }
            $this->say('reminder rule ' . $r['code']);
        }
    }

    private function modules()
    {
        foreach (Modules::all() as $m) {
            $row = DB::table('module_registry')->where('code', $m['code'])->first();
            if ($row) {
                if ((string) $row['version'] !== (string) $m['version']) {
                    DB::table('module_registry')->where('code', $m['code'])->update(array('version' => $m['version'], 'updated_at' => now()));
                }
            } else {
                DB::table('module_registry')->insert(array('code' => $m['code'], 'version' => $m['version'], 'enabled' => 1, 'installed_at' => now()));
                $this->say('module ' . $m['code'] . ' ' . $m['version']);
            }
        }
    }
}
