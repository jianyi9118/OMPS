<?php
namespace Omps\Modules\Organization\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;

/**
 * Departments (single unit; departments may have sub-groups). Tree kept with a
 * materialized `path` such as /3/7/ (ids of ancestors), no recursive SQL needed.
 */
class DeptService
{
    const MAX_DEPTH = 4;

    /** Departments visible to the user, as a flat list ordered for a tree. */
    public static function tree($user)
    {
        $q = DB::table('departments d')->leftJoin('personnel_profiles lp', 'lp.id', '=', 'd.leader_person_id')->active()
            ->select(array('d.id', 'd.parent_id', 'd.path', 'd.code', 'd.name', 'd.leader_person_id', 'd.sort', 'd.status', 'd.remark', 'lp.name as leader_name'));
        $ids = Permission::deptIds($user);
        if ($ids !== null) {
            $q->whereIn('d.id', $ids);
        }
        $rows = $q->orderBy('d.sort')->orderBy('d.id')->get();
        $counts = array();
        foreach (DB::select("SELECT dept_id, COUNT(*) AS n FROM personnel_profiles WHERE deleted_at IS NULL AND status = 'active' AND dept_id IS NOT NULL GROUP BY dept_id") as $c) {
            $counts[(int) $c['dept_id']] = (int) $c['n'];
        }
        $visible = array();
        foreach ($rows as $r) {
            $visible[(int) $r['id']] = true;
        }
        $byParent = array();
        foreach ($rows as $r) {
            $pid = $r['parent_id'] === null || !isset($visible[(int) $r['parent_id']]) ? 0 : (int) $r['parent_id'];
            $byParent[$pid][] = $r;
        }
        $out = array();
        $walk = function ($pid, $depth) use (&$walk, &$out, $byParent, $counts) {
            foreach (isset($byParent[$pid]) ? $byParent[$pid] : array() as $r) {
                $id = (int) $r['id'];
                $out[] = array('id' => $id, 'parent_id' => int_or_null($r['parent_id']), 'depth' => $depth, 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                    'leader_person_id' => int_or_null($r['leader_person_id']), 'leader' => str_or_empty($r['leader_name']), 'status' => (string) $r['status'],
                    'remark' => str_or_empty($r['remark']), 'people' => isset($counts[$id]) ? $counts[$id] : 0, 'has_children' => isset($byParent[$id]));
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    public static function find($id)
    {
        return DB::table('departments d')->active()->where('d.id', (int) $id)->first();
    }

    /** Load a department inside the user's scope, else 404 (logged). */
    public static function scoped(Request $req, $id)
    {
        $d = self::find($id);
        $ids = Permission::deptIds($req->user);
        if (!$d || ($ids !== null && !in_array((int) $d['id'], $ids, true))) {
            Audit::denied($req, 'department', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        return $d;
    }

    private static function validate(array $in)
    {
        return Validator::check($in, array(
            'name' => 'required|string|max:100', 'code' => 'nullable|code', 'parent_id' => 'nullable|int',
            'leader_person_id' => 'nullable|int', 'remark' => 'nullable|string|max:500', 'status' => 'nullable|in:active,disabled',
        ), array('name' => 'dept.field.name', 'code' => 'dept.field.code', 'parent_id' => 'dept.field.parent', 'leader_person_id' => 'dept.field.leader', 'remark' => 'dept.field.remark', 'status' => 'dept.field.status'));
    }

    private static function checkLeader($personId)
    {
        if ($personId === null) {
            return null;
        }
        if (!DB::table('personnel_profiles p')->active()->where('p.id', (int) $personId)->exists()) {
            throw ValidationException::field('leader_person_id', 'dept.err.leader_not_found');
        }
        return (int) $personId;
    }

    private static function nextCode()
    {
        $n = (int) DB::value('SELECT COUNT(*) FROM departments') + 1;
        do {
            $code = 'D' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $n++;
        } while (DB::table('departments')->where('code', $code)->exists());
        return $code;
    }

    public static function create(Request $req, array $in)
    {
        $v = self::validate($in);
        $parent = null;
        if (!empty($v['parent_id'])) {
            $parent = self::scoped($req, $v['parent_id']);
        } elseif (Permission::deptIds($req->user) !== null) {
            // department-scope roles can only add sub-groups inside their own department
            throw ValidationException::field('parent_id', 'validation.required', array('field' => __('dept.field.parent')));
        }
        $path = $parent ? (string) $parent['path'] . (int) $parent['id'] . '/' : '/';
        if (substr_count($path, '/') > self::MAX_DEPTH) {
            throw ValidationException::field('parent_id', 'dept.err.too_deep', array('max' => self::MAX_DEPTH));
        }
        $code = !empty($v['code']) ? (string) $v['code'] : self::nextCode();
        if (DB::table('departments')->where('code', $code)->exists()) {
            throw ValidationException::field('code', 'dept.err.code_taken');
        }
        $sort = (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM departments WHERE deleted_at IS NULL AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL'), $parent ? array((int) $parent['id']) : array());
        $id = DB::table('departments')->insert(array(
            'org_id' => (int) config('app.org_id', 1), 'parent_id' => $parent ? (int) $parent['id'] : null, 'path' => $path, 'code' => $code, 'name' => $v['name'],
            'leader_person_id' => self::checkLeader(isset($v['leader_person_id']) ? $v['leader_person_id'] : null), 'sort' => $sort, 'status' => 'active',
            'remark' => isset($v['remark']) ? $v['remark'] : null, 'created_at' => now(), 'created_by' => $req->userId(),
        ));
        Audit::change($req, 'org', 'dept_created', 'department', $id, $v['name'], null, array('code' => $code, 'parent' => $parent ? $parent['name'] : null));
        return $id;
    }

    public static function update(Request $req, $id, array $in)
    {
        $d = self::scoped($req, $id);
        if (isset($in['version']) && (int) $in['version'] !== (int) $d['version']) {
            throw HttpException::conflict('error.stale');
        }
        $v = self::validate($in);
        $upd = array('name' => $v['name'], 'remark' => isset($v['remark']) ? $v['remark'] : null,
            'leader_person_id' => self::checkLeader(isset($v['leader_person_id']) ? $v['leader_person_id'] : null),
            'status' => isset($v['status']) && $v['status'] !== null ? $v['status'] : $d['status']);
        if (!empty($v['code']) && $v['code'] !== $d['code']) {
            if (DB::table('departments')->where('code', $v['code'])->exists()) {
                throw ValidationException::field('code', 'dept.err.code_taken');
            }
            $upd['code'] = $v['code'];
        }
        $newParent = isset($v['parent_id']) && $v['parent_id'] ? (int) $v['parent_id'] : null;
        $oldParent = int_or_null($d['parent_id']);
        $moveFrom = null;
        if ($newParent !== $oldParent) {
            if (Permission::deptIds($req->user) !== null && $newParent === null) {
                throw ValidationException::field('parent_id', 'validation.required', array('field' => __('dept.field.parent')));
            }
            $p = $newParent ? self::scoped($req, $newParent) : null;
            $newPath = $p ? (string) $p['path'] . (int) $p['id'] . '/' : '/';
            if ($p && ((int) $p['id'] === (int) $id || strpos($newPath, '/' . (int) $id . '/') !== false)) {
                throw ValidationException::field('parent_id', 'dept.err.cycle');
            }
            $oldPrefix = (string) $d['path'] . (int) $id . '/';
            $newPrefix = $newPath . (int) $id . '/';
            $maxSub = 0;
            foreach (DB::select('SELECT path FROM departments WHERE path LIKE ?', array(addcslashes($oldPrefix, '%_\\') . '%')) as $s) {
                $maxSub = max($maxSub, substr_count((string) $s['path'], '/') - substr_count($oldPrefix, '/') + 1);
            }
            if (substr_count($newPrefix, '/') - 1 + $maxSub > self::MAX_DEPTH) {
                throw ValidationException::field('parent_id', 'dept.err.too_deep', array('max' => self::MAX_DEPTH));
            }
            $upd['parent_id'] = $newParent;
            $upd['path'] = $newPath;
            $moveFrom = array($oldPrefix, $newPrefix);
        }
        $upd['updated_at'] = now();
        $upd['updated_by'] = $req->userId();
        DB::transaction(function () use ($id, $upd, $moveFrom, $d) {
            if (DB::table('departments')->where('id', (int) $id)->where('version', (int) $d['version'])->increment('version', 1, $upd) === 0) {
                throw HttpException::conflict('error.stale');
            }
            if ($moveFrom !== null) {
                DB::statement('UPDATE departments SET path = CONCAT(?, SUBSTRING(path, ?)) WHERE path LIKE ?', array($moveFrom[1], strlen($moveFrom[0]) + 1, addcslashes($moveFrom[0], '%_\\') . '%'));
            }
        });
        $before = array('name' => $d['name'], 'code' => $d['code'], 'parent_id' => $oldParent, 'leader_person_id' => int_or_null($d['leader_person_id']), 'status' => $d['status'], 'remark' => $d['remark']);
        $after = array_intersect_key(array_merge($before, $upd), $before);
        list($b, $a) = Audit::diff($before, $after);
        if ($a) {
            Audit::change($req, 'org', 'dept_updated', 'department', $id, $upd['name'], $b, $a);
        }
    }

    /** Move up or down among siblings. */
    public static function move(Request $req, $id, $dir)
    {
        $d = self::scoped($req, $id);
        $q = DB::table('departments')->active();
        if ($d['parent_id'] === null) {
            $q->whereNull('parent_id');
        } else {
            $q->where('parent_id', (int) $d['parent_id']);
        }
        $sibs = $q->orderBy('sort')->orderBy('id')->get();
        $idx = null;
        foreach ($sibs as $i => $s) {
            if ((int) $s['id'] === (int) $id) {
                $idx = $i;
            }
        }
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($idx === null || !isset($sibs[$swap])) {
            return;
        }
        $other = $sibs[$swap];
        DB::transaction(function () use ($sibs, $idx, $swap) {
            foreach ($sibs as $i => $s) {
                $pos = $i === $idx ? $swap : ($i === $swap ? $idx : $i);
                DB::table('departments')->where('id', (int) $s['id'])->update(array('sort' => ($pos + 1) * 10));
            }
        });
        Audit::op($req->user, 'org', 'dept_sorted', 'department', $id, $d['name'], array('with' => $other['name']), $req->ip());
    }

    public static function delete(Request $req, $id)
    {
        $d = self::scoped($req, $id);
        $kids = (int) DB::value('SELECT COUNT(*) FROM departments WHERE parent_id = ? AND deleted_at IS NULL', array((int) $id));
        $people = (int) DB::value('SELECT COUNT(*) FROM personnel_profiles WHERE dept_id = ? AND deleted_at IS NULL', array((int) $id));
        if ($kids || $people) {
            throw HttpException::conflict('dept.err.not_empty', array('name' => $d['name'], 'kids' => $kids, 'people' => $people));
        }
        DB::table('departments')->where('id', (int) $id)->update(array('deleted_at' => now(), 'deleted_by' => $req->userId(), 'code' => mb_substr((string) $d['code'], 0, 40) . '~' . (int) $id));
        Audit::change($req, 'org', 'dept_deleted', 'department', $id, $d['name'], array('code' => $d['code']), null);
    }

    /** Options for selects: [id, indented name] within the user's scope. */
    public static function options($user)
    {
        $out = array();
        foreach (self::tree($user) as $d) {
            $out[] = array('id' => $d['id'], 'name' => $d['name'], 'depth' => $d['depth'], 'status' => $d['status']);
        }
        return $out;
    }
}
