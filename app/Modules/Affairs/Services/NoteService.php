<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\Validator;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Notify;
use Omps\Engines\Permission;

/**
 * Notebook (design §10.7–10.8).
 *  - A note is visible only to its owner until submitted; no role can read someone else's private
 *    notes: every query filters on "own, or submitted to me".
 *  - One button submits it to the superior the system picks (member → department head →
 *    unit head). Submitted notes are locked; they can be withdrawn until read.
 *  - The superior writes an optional comment (批示) and marks it read; read notes stay as records
 *    (copy to continue writing).
 *  - Logs keep the title and the action only, never the text.
 */
class NoteService
{
    const STATUSES = array('private', 'submitted', 'read');

    private static function names(array $userIds)
    {
        $out = array();
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids) {
            foreach (DB::table('users u')->leftJoin('personnel_profiles p', 'p.id', '=', 'u.person_id')->whereIn('u.id', $ids)
                ->select(array('u.id', 'u.display_name', 'p.name'))->get() as $u) {
                $out[(int) $u['id']] = $u['name'] !== null && $u['name'] !== '' ? (string) $u['name'] : (string) $u['display_name'];
            }
        }
        return $out;
    }

    private static function myPlans(array $user)
    {
        if ($user['person_id'] === null) {
            return array();
        }
        $out = array();
        foreach (DB::select("SELECT p.id, p.title, p.level FROM plan_assignments a JOIN plans p ON p.id = a.plan_id WHERE a.person_id = ? AND p.deleted_at IS NULL
            ORDER BY p.end_date DESC, p.id DESC LIMIT 300", array((int) $user['person_id'])) as $p) {
            $out[] = array('id' => (int) $p['id'], 'title' => (string) $p['title'], 'level' => (string) $p['level']);
        }
        return $out;
    }

    /** Received notes exist for leaders (department or whole-unit scope) and anyone who has received one. */
    private static function hasInbox(array $user)
    {
        $s = Permission::scope($user);
        return $s === 'dept' || $s === 'unit' || $s === 'all' || DB::table('notes')->where('submitted_to', (int) $user['id'])->exists();
    }

    public static function meta(Request $req)
    {
        $sup = Notify::superiorOf($req->user);
        $inbox = self::hasInbox($req->user);
        return array('superior' => $sup ? array('id' => $sup['id'], 'name' => $sup['name']) : null, 'plans' => self::myPlans($req->user), 'inbox' => $inbox,
            'inbox_unread' => $inbox ? DB::table('notes')->where('submitted_to', $req->userId())->where('status', 'submitted')->count() : 0);
    }

    public static function index(Request $req, $box, $kw, $filter)
    {
        $uid = $req->userId();
        $q = DB::table('notes');
        if ($box === 'inbox') {
            $q->where('submitted_to', $uid)->whereIn('status', array('submitted', 'read'));
            if ($filter === 'unread') {
                $q->where('status', 'submitted');
            }
            $q->orderBy('submitted_at', 'DESC');
        } else {
            $q->where('owner_id', $uid);
            if ($filter === 'private') {
                $q->where('status', 'private');
            } elseif ($filter === 'submitted') {
                $q->whereIn('status', array('submitted', 'read'));
            }
            $q->orderBy('updated_at', 'DESC');
        }
        if ($kw !== '') {
            $q->whereLikeAny(array('title', 'body'), $kw);
        }
        $rows = $q->orderBy('id', 'DESC')->limit(500)->get();
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = (int) $r['owner_id'];
            $ids[] = (int) $r['submitted_to'];
        }
        $names = self::names($ids);
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('id' => (int) $r['id'], 'title' => (string) $r['title'], 'snippet' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r['body'])), 0, 60),
                'status' => (string) $r['status'], 'updated_at' => (string) ($r['updated_at'] !== null ? $r['updated_at'] : $r['created_at']),
                'submitted_at' => str_or_empty($r['submitted_at']), 'read_at' => str_or_empty($r['read_at']),
                'owner' => isset($names[(int) $r['owner_id']]) ? $names[(int) $r['owner_id']] : '', 'to' => isset($names[(int) $r['submitted_to']]) ? $names[(int) $r['submitted_to']] : '');
        }
        return array('rows' => $out);
    }

    /** Own note, or one submitted to me; anything else is 404 (logged). */
    private static function visible(Request $req, $id)
    {
        $n = DB::table('notes')->find((int) $id);
        $uid = $req->userId();
        if ($n && ((int) $n['owner_id'] === $uid || ((int) $n['submitted_to'] === $uid && (string) $n['status'] !== 'private'))) {
            return $n;
        }
        Audit::denied($req, 'note', array('id' => (int) $id));
        throw HttpException::notFound();
    }

    private static function own(Request $req, $id)
    {
        $n = self::visible($req, $id);
        if ((int) $n['owner_id'] !== $req->userId()) {
            throw HttpException::notFound();
        }
        return $n;
    }

    public static function show(Request $req, $id)
    {
        $n = self::visible($req, $id);
        $uid = $req->userId();
        $mine = (int) $n['owner_id'] === $uid;
        $names = self::names(array((int) $n['owner_id'], (int) $n['submitted_to']));
        $plan = $n['plan_id'] === null ? null : DB::selectOne('SELECT id, title, level FROM plans WHERE id = ? AND deleted_at IS NULL', array((int) $n['plan_id']));
        if (!$mine) {
            Notify::markRead($uid, 'note', (int) $n['id']);
        } elseif ((string) $n['status'] === 'read') {
            Notify::markRead($uid, 'note', (int) $n['id']);
        }
        $st = (string) $n['status'];
        return array(
            'id' => (int) $n['id'], 'title' => (string) $n['title'], 'body' => str_or_empty($n['body']), 'status' => $st, 'mine' => $mine,
            'plan' => $plan ? array('id' => (int) $plan['id'], 'title' => (string) $plan['title'], 'level' => (string) $plan['level']) : null,
            'owner' => isset($names[(int) $n['owner_id']]) ? $names[(int) $n['owner_id']] : '', 'to' => isset($names[(int) $n['submitted_to']]) ? $names[(int) $n['submitted_to']] : '',
            'created_at' => (string) $n['created_at'], 'updated_at' => str_or_empty($n['updated_at']), 'submitted_at' => str_or_empty($n['submitted_at']),
            'read_at' => str_or_empty($n['read_at']), 'reply' => str_or_empty($n['reply']), 'version' => (int) $n['version'],
            'caps' => array('edit' => $mine && $st === 'private', 'submit' => $mine && $st === 'private', 'withdraw' => $mine && $st === 'submitted',
                'copy' => $mine && $st !== 'private', 'read' => !$mine && $st === 'submitted'),
        );
    }

    private static function fields(Request $req, array $in)
    {
        $v = Validator::check($in, array('title' => 'required|string|max:200', 'body' => 'nullable|string|max:50000', 'plan_id' => 'nullable|int', 'version' => 'nullable|int'),
            array('title' => 'note.field.title', 'body' => 'note.field.body', 'plan_id' => 'note.field.plan'));
        $plan = isset($v['plan_id']) && $v['plan_id'] ? (int) $v['plan_id'] : null;
        if ($plan !== null) {
            $ok = false;
            foreach (self::myPlans($req->user) as $p) {
                if ($p['id'] === $plan) {
                    $ok = true;
                }
            }
            if (!$ok) {
                throw ValidationException::field('plan_id', 'note.err.plan');
            }
        }
        return array('title' => (string) $v['title'], 'body' => isset($v['body']) && $v['body'] !== '' ? (string) $v['body'] : null, 'plan_id' => $plan,
            'version' => isset($v['version']) ? $v['version'] : null);
    }

    public static function create(Request $req, array $in)
    {
        $v = self::fields($req, $in);
        $id = DB::table('notes')->insert(array('org_id' => (int) config('app.org_id', 1), 'owner_id' => $req->userId(), 'title' => $v['title'], 'body' => $v['body'],
            'plan_id' => $v['plan_id'], 'status' => 'private', 'created_at' => now(), 'updated_at' => now()));
        Audit::op($req->user, 'note', 'note_created', 'note', $id, $v['title'], null, $req->ip());
        return array('id' => $id, 'version' => 1);
    }

    public static function update(Request $req, $id, array $in)
    {
        $n = self::own($req, $id);
        if ((string) $n['status'] !== 'private') {
            throw HttpException::conflict('note.err.locked');
        }
        $v = self::fields($req, $in);
        if ($v['version'] !== null && (int) $v['version'] !== (int) $n['version']) {
            throw HttpException::conflict('error.stale');
        }
        if (DB::table('notes')->where('id', (int) $n['id'])->where('status', 'private')->where('version', (int) $n['version'])
            ->increment('version', 1, array('title' => $v['title'], 'body' => $v['body'], 'plan_id' => $v['plan_id'], 'updated_at' => now())) === 0) {
            throw HttpException::conflict('error.stale');
        }
        return array('id' => (int) $n['id'], 'version' => (int) $n['version'] + 1);
    }

    public static function delete(Request $req, $id)
    {
        $n = self::own($req, $id);
        if ((string) $n['status'] !== 'private') {
            throw HttpException::conflict('note.err.locked');
        }
        DB::table('notes')->where('id', (int) $n['id'])->delete();
        Audit::op($req->user, 'note', 'note_deleted', 'note', (int) $n['id'], (string) $n['title'], null, $req->ip());
        return array('ok' => true);
    }

    public static function submit(Request $req, $id)
    {
        $n = self::own($req, $id);
        if ((string) $n['status'] !== 'private') {
            throw HttpException::conflict('note.err.locked');
        }
        $sup = Notify::superiorOf($req->user);
        if (!$sup) {
            throw HttpException::conflict('note.err.no_superior');
        }
        if (DB::table('notes')->where('id', (int) $n['id'])->where('status', 'private')->increment('version', 1, array('status' => 'submitted', 'submitted_to' => $sup['id'],
            'submitted_at' => now(), 'read_at' => null, 'reply' => null)) === 0) {
            throw HttpException::conflict('error.stale');
        }
        Notify::send(array($sup['id']), 'note_submitted', array('name' => $req->user['name'], 'title' => (string) $n['title']),
            '/affairs/notes?tab=inbox&id=' . (int) $n['id'], 'note', (int) $n['id'], $req->userId());
        Audit::op($req->user, 'note', 'note_submitted', 'note', (int) $n['id'], (string) $n['title'], array('to' => $sup['name']), $req->ip());
        return array('ok' => true, 'to' => $sup['name']);
    }

    public static function withdraw(Request $req, $id)
    {
        $n = self::own($req, $id);
        if ((string) $n['status'] !== 'submitted') {
            throw HttpException::conflict((string) $n['status'] === 'read' ? 'note.err.already_read' : 'note.err.not_submitted');
        }
        if (DB::table('notes')->where('id', (int) $n['id'])->where('status', 'submitted')->increment('version', 1, array('status' => 'private', 'submitted_to' => null,
            'submitted_at' => null, 'updated_at' => now())) === 0) {
            throw HttpException::conflict('note.err.already_read');
        }
        Notify::retract('note', (int) $n['id'], array('note_submitted'));
        Audit::op($req->user, 'note', 'note_withdrawn', 'note', (int) $n['id'], (string) $n['title'], null, $req->ip());
        return array('ok' => true);
    }

    /** The superior marks a received note as read, with an optional comment. */
    public static function read(Request $req, $id, array $in)
    {
        $n = self::visible($req, $id);
        $uid = $req->userId();
        if ((int) $n['submitted_to'] !== $uid || (int) $n['owner_id'] === $uid) {
            throw HttpException::notFound();
        }
        if ((string) $n['status'] !== 'submitted') {
            throw HttpException::conflict('note.err.already_read');
        }
        $v = Validator::check($in, array('reply' => 'nullable|string|max:2000'), array('reply' => 'note.field.reply'));
        $reply = isset($v['reply']) && trim((string) $v['reply']) !== '' ? trim((string) $v['reply']) : null;
        if (DB::table('notes')->where('id', (int) $n['id'])->where('status', 'submitted')->increment('version', 1, array('status' => 'read', 'read_at' => now(), 'reply' => $reply)) === 0) {
            throw HttpException::conflict('note.err.already_read');
        }
        Notify::markRead($uid, 'note', (int) $n['id']);
        Notify::send(array((int) $n['owner_id']), $reply === null ? 'note_read' : 'note_replied', array('name' => $req->user['name'], 'title' => (string) $n['title']),
            '/affairs/notes?id=' . (int) $n['id'], 'note', (int) $n['id'], $uid);
        Audit::op($req->user, 'note', 'note_read', 'note', (int) $n['id'], (string) $n['title'], null, $req->ip());
        return array('ok' => true);
    }

    /** Copy a submitted or read note into a new private one, to keep writing. */
    public static function copy(Request $req, $id)
    {
        $n = self::own($req, $id);
        $nid = DB::table('notes')->insert(array('org_id' => (int) config('app.org_id', 1), 'owner_id' => $req->userId(), 'title' => (string) $n['title'], 'body' => $n['body'],
            'plan_id' => $n['plan_id'], 'status' => 'private', 'created_at' => now(), 'updated_at' => now()));
        Audit::op($req->user, 'note', 'note_created', 'note', $nid, (string) $n['title'], array('copy_of' => (int) $n['id']), $req->ip());
        return array('id' => $nid);
    }
}
