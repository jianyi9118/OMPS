<?php
namespace Omps\Engines;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;

/**
 * File engine (design §11).
 *  - Files live outside the web root under config app.files_path, in folders that mirror the
 *    platform, e.g. 人员档案/技术部/软件组/王晓/学历经历/证书.pdf. The database is the source of truth.
 *  - Type, size and content are checked; nothing in the store is ever executed or served directly.
 *  - Same content uploaded again by the same person is stored once (SHA-256) and referenced.
 *  - Deleting moves a file to the recycle bin (status trash); a later cleanup removes it for good.
 * Permission to see or change the business record is checked by the caller (the business service).
 */
class Files
{
    const TYPES = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'mp4' => 'video/mp4',
    );
    const IMAGE_TYPES = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp');

    public static function root()
    {
        return rtrim(str_replace('\\', '/', (string) config('app.files_path')), '/');
    }

    public static function maxBytes()
    {
        return max(1, (int) config('app.upload_max_mb', 50)) * 1048576;
    }

    /** Folder or file name safe on Windows: no \ / : * ? " < > |, no control chars, at most $max characters. */
    public static function safeName($name, $max = 30)
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', (string) $name);
        $name = trim((string) $name, " .\t");
        if ($name === '' || preg_match('/^(CON|PRN|AUX|NUL|COM\d|LPT\d)$/i', $name)) {
            $name = '_' . $name;
        }
        return mb_substr($name, 0, $max);
    }

    /** Check an uploaded file; returns [ext, mime]. $images = only image types. */
    public static function check(array $up, $images = false)
    {
        if ((int) $up['error'] === UPLOAD_ERR_INI_SIZE || (int) $up['error'] === UPLOAD_ERR_FORM_SIZE || (int) $up['size'] > self::maxBytes()) {
            throw ValidationException::field('file', 'file.err.too_big', array('name' => $up['name'], 'max' => (int) config('app.upload_max_mb', 50)));
        }
        if ((int) $up['error'] !== UPLOAD_ERR_OK || !is_file($up['tmp_name'])) {
            throw ValidationException::field('file', 'file.err.upload_failed', array('name' => $up['name']));
        }
        $ext = strtolower((string) pathinfo((string) $up['name'], PATHINFO_EXTENSION));
        $allowed = $images ? self::IMAGE_TYPES : array_keys(self::TYPES);
        if (!in_array($ext, $allowed, true)) {
            throw ValidationException::field('file', $images ? 'file.err.not_image' : 'file.err.type', array('name' => $up['name']));
        }
        $head = (string) file_get_contents($up['tmp_name'], false, null, 0, 4096);
        if (!self::contentMatches($ext, $head, $up['tmp_name'])) {
            throw ValidationException::field('file', 'file.err.content', array('name' => $up['name']));
        }
        return array($ext, self::TYPES[$ext]);
    }

    /** Magic bytes per type; scripts hidden in any file are refused. */
    private static function contentMatches($ext, $head, $path)
    {
        if (preg_match('/<\?php|<script/i', $head)) {
            return false;
        }
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'bmp':
            case 'webp':
                $info = @getimagesize($path);
                return $info !== false;
            case 'pdf':
                return strncmp($head, '%PDF', 4) === 0;
            case 'docx':
            case 'xlsx':
            case 'pptx':
            case 'zip':
                return strncmp($head, "PK\x03\x04", 4) === 0;
            case 'doc':
            case 'xls':
            case 'ppt':
                return strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0;
            case 'rar':
                return strncmp($head, 'Rar!', 4) === 0;
            case '7z':
                return strncmp($head, "7z\xBC\xAF", 4) === 0;
            case 'mp4':
                return substr($head, 4, 4) === 'ftyp';
            case 'txt':
            case 'csv':
                return strpos($head, "\x00") === false;
        }
        return false;
    }

    /**
     * Store one upload and link it to a business record.
     * @param array $folders folder names below the root, e.g. ['人员档案', '技术部', '王晓', '学历经历']
     * @return array ['file_id', 'relation_id', 'duplicate' => bool]
     */
    public static function store(Request $req, array $up, $bizType, $bizId, $group, array $folders, $personId = null, $images = false)
    {
        list($ext, $mime) = self::check($up, $images);
        $sha = hash_file('sha256', $up['tmp_name']);
        $uid = $req->userId();
        $existing = DB::selectOne("SELECT id FROM files WHERE sha256 = ? AND uploaded_by = ? AND status = 'normal' ORDER BY id LIMIT 1", array($sha, $uid));
        if ($existing) {
            $already = DB::table('file_relations')->where('file_id', (int) $existing['id'])->where('biz_type', $bizType)->where('biz_id', (int) $bizId)->where('biz_group', $group)->first();
            if ($already) {
                return array('file_id' => (int) $existing['id'], 'relation_id' => (int) $already['id'], 'duplicate' => true);
            }
            $rid = DB::table('file_relations')->insert(array('file_id' => (int) $existing['id'], 'biz_type' => $bizType, 'biz_id' => (int) $bizId, 'biz_group' => $group,
                'person_id' => $personId, 'is_reference' => 1, 'created_by' => $uid, 'created_at' => now()));
            Audit::op($req->user, 'files', 'file_referenced', $bizType, $bizId, (string) $up['name'], null, $req->ip());
            return array('file_id' => (int) $existing['id'], 'relation_id' => $rid, 'duplicate' => true);
        }
        $segs = array();
        foreach ($folders as $f) {
            if ((string) $f !== '') {
                $segs[] = self::safeName($f);
            }
        }
        $relDir = implode('/', $segs);
        $absDir = self::root() . ($relDir === '' ? '' : '/' . $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            throw new \RuntimeException('Cannot create folder ' . $absDir);
        }
        $base = self::safeName(pathinfo((string) $up['name'], PATHINFO_FILENAME), 80);
        $name = $base . '.' . $ext;
        for ($i = 2; is_file($absDir . '/' . $name); $i++) {
            $name = $base . '(' . $i . ').' . $ext;
        }
        $target = $absDir . '/' . $name;
        $moved = is_uploaded_file($up['tmp_name']) ? move_uploaded_file($up['tmp_name'], $target)
            : ((string) config('app.env') === 'testing' && copy($up['tmp_name'], $target));
        if (!$moved) {
            throw new \RuntimeException('Cannot store the uploaded file');
        }
        $fid = DB::table('files')->insert(array('org_id' => (int) config('app.org_id', 1), 'rel_path' => ($relDir === '' ? '' : $relDir . '/') . $name,
            'original_name' => mb_substr((string) $up['name'], 0, 200), 'ext' => $ext, 'mime' => $mime, 'size' => (int) filesize($target), 'sha256' => $sha,
            'uploaded_by' => $uid, 'uploaded_at' => now(), 'status' => 'normal'));
        $rid = DB::table('file_relations')->insert(array('file_id' => $fid, 'biz_type' => $bizType, 'biz_id' => (int) $bizId, 'biz_group' => $group,
            'person_id' => $personId, 'is_reference' => 0, 'created_by' => $uid, 'created_at' => now()));
        Audit::op($req->user, 'files', 'file_uploaded', $bizType, $bizId, (string) $up['name'], array('path' => ($relDir === '' ? '' : $relDir . '/') . $name), $req->ip());
        return array('file_id' => $fid, 'relation_id' => $rid, 'duplicate' => false);
    }

    /** Files linked to a record (normal status), grouped by biz_group. */
    public static function forRecord($bizType, $bizId)
    {
        $rows = DB::select("SELECT r.id AS relation_id, r.biz_group, f.id, f.original_name, f.ext, f.size, f.uploaded_by, f.uploaded_at, u.display_name AS uploader
            FROM file_relations r JOIN files f ON f.id = r.file_id LEFT JOIN users u ON u.id = f.uploaded_by
            WHERE r.biz_type = ? AND r.biz_id = ? AND f.status = 'normal' ORDER BY r.id", array($bizType, (int) $bizId));
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('relation_id' => (int) $r['relation_id'], 'id' => (int) $r['id'], 'group' => str_or_empty($r['biz_group']), 'name' => (string) $r['original_name'],
                'ext' => (string) $r['ext'], 'size' => (int) $r['size'], 'uploader' => str_or_empty($r['uploader']), 'uploaded_by' => int_or_null($r['uploaded_by']),
                'at' => (string) $r['uploaded_at'], 'image' => in_array((string) $r['ext'], self::IMAGE_TYPES, true));
        }
        return $out;
    }

    /** One relation with its file, or 404. */
    public static function relation($relationId)
    {
        $r = DB::selectOne("SELECT r.*, f.rel_path, f.original_name, f.mime, f.ext, f.status, f.uploaded_by FROM file_relations r JOIN files f ON f.id = r.file_id WHERE r.id = ?", array((int) $relationId));
        if (!$r || (string) $r['status'] !== 'normal') {
            throw HttpException::notFound();
        }
        return $r;
    }

    public static function download(Request $req, array $rel, $inline)
    {
        $path = self::root() . '/' . $rel['rel_path'];
        if (!is_file($path)) {
            throw HttpException::notFound();
        }
        Audit::op($req->user, 'files', 'file_downloaded', (string) $rel['biz_type'], (int) $rel['biz_id'], (string) $rel['original_name'], null, $req->ip());
        return \Omps\Core\Response::file($path, (string) $rel['original_name'], (string) $rel['mime'], $inline);
    }

    /**
     * Remove a file from a record. Only the uploader (or the super admin) may delete; when no other
     * record uses the file it goes to the recycle bin.
     */
    public static function detach(Request $req, array $rel)
    {
        if ((int) $rel['uploaded_by'] !== $req->userId() && !Permission::isSuper($req->user)) {
            throw new HttpException(403, 'forbidden', 'file.err.not_owner');
        }
        DB::transaction(function () use ($req, $rel) {
            self::removeRelations(array($rel), $req->userId());
        });
        Audit::op($req->user, 'files', 'file_deleted', (string) $rel['biz_type'], (int) $rel['biz_id'], (string) $rel['original_name'], null, $req->ip());
    }

    /**
     * Remove relations (rows of file_relations). A file that no record uses any more goes to the
     * recycle bin, and where it was attached is kept so that it can be restored (phase 9).
     * Call inside a transaction.
     */
    public static function removeRelations(array $rels, $userId)
    {
        $byFile = array();
        foreach ($rels as $r) {
            DB::table('file_relations')->where('id', (int) $r['id'])->delete();
            $byFile[(int) $r['file_id']][] = $r;
        }
        foreach ($byFile as $fid => $list) {
            if (DB::table('file_relations')->where('file_id', $fid)->exists()) {
                continue;
            }
            foreach ($list as $r) {
                DB::table('file_trash_relations')->insert(array('file_id' => $fid, 'biz_type' => (string) $r['biz_type'], 'biz_id' => (int) $r['biz_id'],
                    'biz_group' => isset($r['biz_group']) ? $r['biz_group'] : null, 'person_id' => isset($r['person_id']) ? $r['person_id'] : null,
                    'is_reference' => isset($r['is_reference']) ? (int) $r['is_reference'] : 0, 'created_by' => isset($r['created_by']) ? $r['created_by'] : null,
                    'created_at' => isset($r['created_at']) ? $r['created_at'] : null, 'removed_by' => $userId, 'removed_at' => now()));
            }
            DB::table('files')->where('id', $fid)->where('status', 'normal')->update(array('status' => 'trash', 'deleted_at' => now(), 'deleted_by' => $userId));
        }
    }

    /**
     * Rename a folder of the store and the paths of the files in it (a plan was renamed, design §11.2).
     * The database stays the source of truth: when the folder cannot be renamed (or the new name is
     * taken) nothing changes and a warning is logged. Paths are relative to the root, segments already safe.
     */
    public static function moveFolder($oldRel, $newRel)
    {
        $oldRel = trim((string) $oldRel, '/');
        $newRel = trim((string) $newRel, '/');
        if ($oldRel === '' || $newRel === '' || $oldRel === $newRel) {
            return false;
        }
        $from = self::root() . '/' . $oldRel;
        $to = self::root() . '/' . $newRel;
        if (!is_dir($from)) {
            return false;
        }
        if (file_exists($to) || (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0775, true)) || !@rename($from, $to)) {
            \Omps\Core\Logger::warning('Folder not renamed', array('from' => $oldRel, 'to' => $newRel));
            return false;
        }
        $len = mb_strlen($oldRel . '/');
        DB::statement('UPDATE files SET rel_path = CONCAT(?, SUBSTRING(rel_path, ?)) WHERE LEFT(rel_path, ?) = ?', array($newRel . '/', $len + 1, $len, $oldRel . '/'));
        return true;
    }

    /** Relations of a business record (for removeRelations). */
    public static function relationsOf($bizType, array $bizIds)
    {
        if (!$bizIds) {
            return array();
        }
        return DB::table('file_relations')->where('biz_type', $bizType)->whereIn('biz_id', array_map('intval', $bizIds))->get();
    }

    /** Business records a restored file may go back to: type => [table, soft delete]. */
    const RESTORABLE = array(
        'personnel' => array('personnel_profiles', true), 'performance' => array('performance_reports', false), 'report' => array('reports', true),
        'device' => array('devices', true), 'device_fault' => array('device_faults', false), 'issue' => array('issues', false), 'issue_reply' => array('issue_recipients', false),
    );

    /**
     * Restore a file from the recycle bin to the records it was attached to.
     * Only the uploader or the super administrator; records that no longer exist are skipped, and when
     * none is left the file cannot be restored (configuration files are uploaded again instead).
     */
    public static function restore(Request $req, $fileId)
    {
        $f = DB::table('files')->where('id', (int) $fileId)->where('status', 'trash')->first();
        if (!$f) {
            throw HttpException::notFound();
        }
        if ((int) $f['uploaded_by'] !== $req->userId() && !Permission::isSuper($req->user)) {
            // not in this person's recycle bin
            throw HttpException::notFound();
        }
        $stash =DB::table('file_trash_relations')->where('file_id', (int) $f['id'])->orderBy('id')->get();
        $back = array();
        foreach ($stash as $s) {
            $t = isset(self::RESTORABLE[$s['biz_type']]) ? self::RESTORABLE[$s['biz_type']] : null;
            if ($t === null) {
                continue;
            }
            $q = DB::table($t[0])->where('id', (int) $s['biz_id']);
            if ($t[1]) {
                $q->whereNull('deleted_at');
            }
            if ($q->exists()) {
                $back[] = $s;
            }
        }
        if (!$back) {
            throw HttpException::conflict('file.err.restore_gone');
        }
        DB::transaction(function () use ($f, $back) {
            foreach ($back as $s) {
                DB::table('file_relations')->insert(array('file_id' => (int) $f['id'], 'biz_type' => $s['biz_type'], 'biz_id' => (int) $s['biz_id'], 'biz_group' => $s['biz_group'],
                    'person_id' => $s['person_id'], 'is_reference' => (int) $s['is_reference'], 'created_by' => $s['created_by'], 'created_at' => $s['created_at'] !== null ? $s['created_at'] : now()));
            }
            DB::table('file_trash_relations')->where('file_id', (int) $f['id'])->delete();
            DB::table('files')->where('id', (int) $f['id'])->update(array('status' => 'normal', 'deleted_at' => null, 'deleted_by' => null));
        });
        Audit::op($req->user, 'files', 'file_restored', (string) $back[0]['biz_type'], (int) $back[0]['biz_id'], (string) $f['original_name'], null, $req->ip());
        return count($back);
    }

    /** Folder names are kept in the base language (they are server paths, not screen text). */
    public static function folder($key)
    {
        return Lang::get($key, array(), (string) config('app.base_language', 'zh-CN'));
    }
}
