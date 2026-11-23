<?php
namespace Omps\Modules\Files\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;
use Omps\Engines\Permission;

/**
 * 文件管理 (design §11.4, phase 9): browse the stored files by the platform's folders, preview,
 * download, delete own files, restore from the recycle bin, and merge duplicate copies.
 *
 * Who sees a file: its uploader, and whoever may see the record it is attached to under that
 * module's own rules (the same check as downloading it there); the super administrator sees all.
 * Uploading happens only on the business pages.
 */
class FileManagerService
{
    const RECYCLE_DAYS = 30;

    private static $seen = array();

    /** May the user see the record a relation points to (module rules, without logging refusals)? */
    private static function recordVisible(Request $req, $type, $id)
    {
        $k = $req->userId() . ':' . $type . ':' . $id;
        if (isset(self::$seen[$k])) {
            return self::$seen[$k];
        }
        $ok = Audit::quietly(function () use ($req, $type, $id) {
            try {
                switch ($type) {
                    case 'personnel':
                        \Omps\Modules\Personnel\Services\PersonnelService::access($req, $id);
                        return true;
                    case 'performance':
                        \Omps\Modules\Performance\Services\PerformanceService::fileAccess($req, $id, false);
                        return true;
                    case 'report':
                        \Omps\Modules\Report\Services\ReportService::fileAccess($req, $id, false);
                        return true;
                    case 'device':
                        \Omps\Modules\Device\Services\DeviceService::fileAccess($req, $id, false);
                        return true;
                    case 'device_fault':
                        \Omps\Modules\Device\Services\RepairService::fileAccess($req, $id, false);
                        return true;
                    case 'issue':
                    case 'issue_reply':
                        \Omps\Modules\Affairs\Services\IssueService::fileAccess($req, $type, $id, false);
                        return true;
                }
            } catch (HttpException $e) {
                return false;
            }
            return false;
        });
        self::$seen[$k] = $ok;
        return $ok;
    }

    /** Normal files with their relations: [file id => ['file' => row, 'rels' => rows]]. */
    private static function allFiles()
    {
        // caches live for one call only (records and rights change between requests)
        self::$seen = array();
        self::$labels = array();
        $rows = DB::select("SELECT f.id AS fid, f.rel_path, f.original_name, f.ext, f.size, f.sha256, f.uploaded_by, f.uploaded_at, u.display_name AS uploader,
                r.id AS rid, r.biz_type, r.biz_id, r.biz_group
            FROM files f LEFT JOIN file_relations r ON r.file_id = f.id LEFT JOIN users u ON u.id = f.uploaded_by
            WHERE f.status = 'normal' ORDER BY f.id, r.id");
        $out = array();
        foreach ($rows as $r) {
            $fid = (int) $r['fid'];
            if (!isset($out[$fid])) {
                $out[$fid] = array('file' => $r, 'rels' => array());
            }
            if ($r['rid'] !== null) {
                $out[$fid]['rels'][] = array('id' => (int) $r['rid'], 'biz_type' => (string) $r['biz_type'], 'biz_id' => (int) $r['biz_id'], 'biz_group' => str_or_empty($r['biz_group']));
            }
        }
        return $out;
    }

    /** Relations of a file the user may see; [] = none. Uploader and super admin see every relation. */
    private static function visibleRels(Request $req, array $entry)
    {
        $f = $entry['file'];
        if (Permission::isSuper($req->user) || (int) $f['uploaded_by'] === $req->userId()) {
            return $entry['rels'];
        }
        $out = array();
        foreach ($entry['rels'] as $r) {
            if ($r['biz_type'] !== 'system' && $r['biz_type'] !== 'doc_template' && self::recordVisible($req, $r['biz_type'], $r['biz_id'])) {
                $out[] = $r;
            }
        }
        return $out;
    }

    // ---------- labels ----------

    private static $labels = array();

    /** "Area · record" for a relation, in the current language. */
    private static function bizLabel(array $r)
    {
        $k = $r['biz_type'] . ':' . $r['biz_id'];
        if (!isset(self::$labels[$k])) {
            self::$labels[$k] = self::makeLabel($r);
        }
        $l = self::$labels[$k];
        if ($r['biz_type'] === 'personnel' && $r['biz_group'] !== '' && Lang::has('file.group.' . $r['biz_group'])) {
            $l .= ' · ' . __('file.group.' . $r['biz_group']);
        }
        return $l;
    }

    private static function makeLabel(array $r)
    {
        $id = (int) $r['biz_id'];
        $name = '';
        switch ($r['biz_type']) {
            case 'personnel':
                $name = (string) DB::value('SELECT name FROM personnel_profiles WHERE id = ?', array($id));
                break;
            case 'performance':
                $name = (string) DB::value('SELECT p.title FROM performance_reports r JOIN plans p ON p.id = r.plan_id WHERE r.id = ?', array($id));
                break;
            case 'report':
                $name = (string) DB::value('SELECT title FROM reports WHERE id = ?', array($id));
                break;
            case 'device':
                $d = DB::selectOne('SELECT asset_no, name FROM devices WHERE id = ?', array($id));
                $name = $d ? $d['asset_no'] . ' ' . $d['name'] : '';
                break;
            case 'device_fault':
                $name = (string) DB::value('SELECT d.asset_no FROM device_faults f JOIN devices d ON d.id = f.device_id WHERE f.id = ?', array($id));
                break;
            case 'issue':
                $name = (string) DB::value('SELECT title FROM issues WHERE id = ?', array($id));
                break;
            case 'issue_reply':
                $name = (string) DB::value('SELECT i.title FROM issue_recipients r JOIN issues i ON i.id = r.issue_id WHERE r.id = ?', array($id));
                break;
        }
        $area = Lang::has('fm.biz.' . $r['biz_type']) ? __('fm.biz.' . $r['biz_type']) : (string) $r['biz_type'];
        return $name !== '' ? $area . ' · ' . $name : $area;
    }

    /** Folder names the system made (stored in the base language) shown in the current language. */
    private static function folderNames()
    {
        static $map = null;
        if ($map === null) {
            $map = array();
            $base = (string) config('app.base_language', 'zh-CN');
            foreach (Lang::pack($base) as $k => $v) {
                if (strpos($k, 'file.folder.') === 0 || strpos($k, 'file.group.') === 0) {
                    $map[Files::safeName($v)] = $k;
                }
            }
        }
        return $map;
    }

    private static function dirOf($relPath)
    {
        $segs = explode('/', (string) $relPath);
        array_pop($segs);
        $map = self::folderNames();
        $out = array();
        foreach ($segs as $s) {
            $out[] = isset($map[$s]) ? __($map[$s]) : $s;
        }
        return $out;
    }

    private static function present(Request $req, array $f, array $rels, $dupCount)
    {
        $uid = $req->userId();
        $labels = array();
        foreach ($rels as $r) {
            $labels[] = self::bizLabel($r);
        }
        $ext = (string) $f['ext'];
        return array('id' => (int) (isset($f['fid']) ? $f['fid'] : $f['id']), 'name' => (string) $f['original_name'], 'ext' => $ext, 'size' => (int) $f['size'],
            'uploader' => str_or_empty($f['uploader']), 'at' => (string) $f['uploaded_at'], 'dir' => self::dirOf($f['rel_path']),
            'links' => array_values(array_unique($labels)), 'mine' => (int) $f['uploaded_by'] === $uid,
            'can_delete' => (int) $f['uploaded_by'] === $uid || Permission::isSuper($req->user),
            'preview' => in_array($ext, Files::IMAGE_TYPES, true) || $ext === 'pdf', 'image' => in_array($ext, Files::IMAGE_TYPES, true), 'dup' => $dupCount);
    }

    // ---------- pages ----------

    /** Everything the user may see, plus the counts at the top. */
    public static function index(Request $req)
    {
        $all = self::allFiles();
        $sha = array();
        foreach ($all as $e) {
            $s = (string) $e['file']['sha256'];
            $sha[$s] = isset($sha[$s]) ? $sha[$s] + 1 : 1;
        }
        $files = array();
        $mine = 0;
        foreach ($all as $e) {
            $rels = self::visibleRels($req, $e);
            $own = (int) $e['file']['uploaded_by'] === $req->userId();
            if (!$rels && !$own) {
                continue;
            }
            $files[] = self::present($req, $e['file'], $rels, $sha[(string) $e['file']['sha256']]);
            $mine += $own ? 1 : 0;
        }
        $super = Permission::isSuper($req->user);
        $out = array('files' => $files, 'mine' => $mine, 'dup_groups' => count(self::duplicateGroups($req, $all)), 'super' => $super, 'root' => '', 'used' => null);
        if ($super || Permission::scope($req->user) === 'unit' || Permission::scope($req->user) === 'all') {
            $out['used'] = (float) DB::value('SELECT COALESCE(SUM(size), 0) FROM files');
        }
        if ($super) {
            $out['root'] = str_replace('/', DIRECTORY_SEPARATOR, Files::root());
        }
        return $out;
    }

    /** Groups of identical normal files with at least one copy the user may see; oldest copy is kept. */
    private static function duplicateGroups(Request $req, array $all)
    {
        $by = array();
        foreach ($all as $fid => $e) {
            $by[(string) $e['file']['sha256']][] = $fid;
        }
        $groups = array();
        foreach ($by as $sha => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            $copies = array();
            $anyVisible = false;
            foreach ($ids as $fid) {
                $rels = self::visibleRels($req, $all[$fid]);
                $own = (int) $all[$fid]['file']['uploaded_by'] === $req->userId();
                $anyVisible = $anyVisible || $rels || $own;
                $copies[] = array('entry' => $all[$fid], 'rels' => $rels, 'own' => $own);
            }
            if ($anyVisible) {
                $groups[$sha] = $copies;
            }
        }
        return $groups;
    }

    public static function duplicates(Request $req)
    {
        $out = array();
        $saving = 0;
        foreach (self::duplicateGroups($req, self::allFiles()) as $copies) {
            $list = array();
            foreach ($copies as $i => $c) {
                $p = self::present($req, $c['entry']['file'], $c['rels'], count($copies));
                $p['kept'] = $i === 0;
                $p['hidden'] = !$c['rels'] && !$c['own'] && !Permission::isSuper($req->user);
                if ($p['hidden']) {
                    // a copy the user may not see: only that it exists
                    $p = array('id' => $p['id'], 'kept' => $p['kept'], 'hidden' => true, 'size' => $p['size']);
                }
                $p['can_merge'] = $i > 0 && ($c['own'] || Permission::isSuper($req->user));
                $list[] = $p;
            }
            $size = (int) $copies[0]['entry']['file']['size'];
            $saving += $size * (count($copies) - 1);
            $out[] = array('name' => (string) $copies[0]['entry']['file']['original_name'], 'size' => $size, 'copies' => $list);
        }
        return array('groups' => $out, 'saving' => $saving);
    }

    /** Replace one's own copy by a reference to the kept copy; the copy itself is removed (the content is kept). */
    public static function merge(Request $req, $fileId)
    {
        $f = DB::table('files')->where('id', (int) $fileId)->where('status', 'normal')->first();
        if (!$f) {
            throw HttpException::notFound();
        }
        if ((int) $f['uploaded_by'] !== $req->userId() && !Permission::isSuper($req->user)) {
            throw new HttpException(403, 'forbidden', 'file.err.not_owner');
        }
        $kept = DB::selectOne("SELECT * FROM files WHERE sha256 = ? AND status = 'normal' ORDER BY id LIMIT 1", array((string) $f['sha256']));
        if (!$kept || (int) $kept['id'] === (int) $f['id']) {
            throw HttpException::conflict('fm.err.is_kept');
        }
        $keptPath = Files::root() . '/' . $kept['rel_path'];
        if (!is_file($keptPath) || hash_file('sha256', $keptPath) !== (string) $f['sha256']) {
            throw HttpException::conflict('fm.err.kept_missing');
        }
        DB::transaction(function () use ($f, $kept) {
            DB::table('file_relations')->where('file_id', (int) $f['id'])->update(array('file_id' => (int) $kept['id'], 'is_reference' => 1));
            DB::table('files')->where('id', (int) $f['id'])->delete();
        });
        $path = Files::root() . '/' . $f['rel_path'];
        if (is_file($path)) {
            @unlink($path);
        }
        Audit::op($req->user, 'files', 'file_merged', 'file', (int) $kept['id'], (string) $f['original_name'], array('removed' => (string) $f['rel_path'], 'kept' => (string) $kept['rel_path']), $req->ip());
        return array('ok' => true);
    }

    /** Delete one's own file everywhere it is used (to the recycle bin). */
    public static function delete(Request $req, $fileId)
    {
        $f = DB::table('files')->where('id', (int) $fileId)->where('status', 'normal')->first();
        if (!$f) {
            throw HttpException::notFound();
        }
        if ((int) $f['uploaded_by'] !== $req->userId() && !Permission::isSuper($req->user)) {
            throw new HttpException(403, 'forbidden', 'file.err.not_owner');
        }
        $rels = DB::table('file_relations')->where('file_id', (int) $f['id'])->get();
        foreach ($rels as $r) {
            if ((string) $r['biz_type'] === 'performance') {
                $st = (string) DB::value('SELECT status FROM performance_reports WHERE id = ?', array((int) $r['biz_id']));
                if ($st === 'submitted' || $st === 'approved') {
                    throw HttpException::conflict('perf.err.file_locked');
                }
            }
        }
        $uid = $req->userId();
        DB::transaction(function () use ($rels, $uid, $f) {
            Files::removeRelations($rels, $uid);
            DB::table('files')->where('id', (int) $f['id'])->where('status', 'normal')->update(array('status' => 'trash', 'deleted_at' => now(), 'deleted_by' => $uid));
        });
        Audit::op($req->user, 'files', 'file_deleted', 'file', (int) $f['id'], (string) $f['original_name'], null, $req->ip());
        return array('ok' => true);
    }

    /** Recycle bin: own deleted files (super admin: all), with the days left. */
    public static function trash(Request $req)
    {
        self::$labels = array();
        $q = DB::table('files f')->leftJoin('users u', 'u.id', '=', 'f.uploaded_by')->leftJoin('users d', 'd.id', '=', 'f.deleted_by')
            ->select(array('f.*', 'u.display_name as uploader', 'd.display_name as deleter'))->where('f.status', 'trash')->orderBy('f.deleted_at', 'DESC');
        if (!Permission::isSuper($req->user)) {
            $q->where('f.uploaded_by', $req->userId());
        }
        $out = array();
        foreach ($q->get() as $f) {
            $labels = array();
            foreach (DB::table('file_trash_relations')->where('file_id', (int) $f['id'])->get() as $s) {
                $labels[] = self::bizLabel(array('biz_type' => (string) $s['biz_type'], 'biz_id' => (int) $s['biz_id'], 'biz_group' => str_or_empty($s['biz_group'])));
            }
            $left = self::RECYCLE_DAYS - (int) floor((time() - strtotime((string) $f['deleted_at'])) / 86400);
            $out[] = array('id' => (int) $f['id'], 'name' => (string) $f['original_name'], 'ext' => (string) $f['ext'], 'size' => (int) $f['size'], 'uploader' => str_or_empty($f['uploader']),
                'deleted_at' => (string) $f['deleted_at'], 'deleter' => str_or_empty($f['deleter']), 'days_left' => max(0, $left), 'links' => array_values(array_unique($labels)),
                'restorable' => (bool) $labels);
        }
        return array('rows' => $out, 'days' => self::RECYCLE_DAYS);
    }

    public static function restore(Request $req, $fileId)
    {
        return array('restored' => Files::restore($req, $fileId));
    }

    /** Preview or download from the file manager (same visibility as the list; logged). */
    public static function download(Request $req, $fileId, $inline)
    {
        $all = self::allFiles();
        if (!isset($all[(int) $fileId])) {
            throw HttpException::notFound();
        }
        $e = $all[(int) $fileId];
        $rels = self::visibleRels($req, $e);
        if (!$rels && (int) $e['file']['uploaded_by'] !== $req->userId()) {
            Audit::denied($req, 'file', array('id' => (int) $fileId));
            throw HttpException::notFound();
        }
        $f = DB::table('files')->find((int) $fileId);
        $first = $rels ? $rels[0] : array('biz_type' => 'file', 'biz_id' => (int) $fileId);
        return Files::download($req, array('rel_path' => $f['rel_path'], 'original_name' => $f['original_name'], 'mime' => $f['mime'],
            'biz_type' => $first['biz_type'], 'biz_id' => $first['biz_id']), $inline);
    }
}
