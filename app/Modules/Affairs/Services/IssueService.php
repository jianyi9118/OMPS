<?php
namespace Omps\Modules\Affairs\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;
use Omps\Engines\Notify;
use Omps\Engines\Permission;
use Omps\Engines\WorkCalendar;

/**
 * Issued items, leader → staff (design §10.9–10.10).
 *  - One form sends three kinds, classified by the system: tip (read only), document (read only,
 *    with files), order (complete and reply by a deadline).
 *  - Department heads send to their department (and its groups), whole-unit roles to everyone.
 *    Members cannot send; they report upwards with the notebook.
 *  - Recipients: opening = read; orders need a reply (optional files). Overdue orders are reminded
 *    by the scheduler (rules issue_unread / issue_due / issue_overdue, or the interval chosen).
 *  - Senders see progress, nudge (once an hour per person) and withdraw.
 *  - Files: 下发事项/<sender>/<title>/<uploader>/. The sender's documents are visible to all
 *    recipients; reply files only to the sender and the uploader.
 */
class IssueService
{
    const KINDS = array('tip', 'doc', 'order');
    const FREQS = array('auto', 'day', 'half', '2h');

    /** Accounts the user may send to: active people in their scope with an active account, not themselves. */
    public static function allowedRecipients(array $user)
    {
        $out = array();
        if (!Permission::can($user, 'issue.send')) {
            return $out;
        }
        $deptNames = array();
        foreach (DB::table('departments')->active()->select(array('id', 'name', 'leader_person_id'))->get() as $d) {
            $deptNames[(int) $d['id']] = array((string) $d['name'], int_or_null($d['leader_person_id']));
        }
        $active = array();
        foreach (DB::select("SELECT id, status FROM personnel_profiles WHERE deleted_at IS NULL") as $p) {
            $active[(int) $p['id']] = (string) $p['status'] === 'active';
        }
        foreach (Notify::users() as $u) {
            if ($u['id'] === (int) $user['id'] || $u['person_id'] === null || empty($active[$u['person_id']])) {
                continue;
            }
            if (!Permission::personInScope($user, array('id' => $u['person_id'], 'dept_id' => $u['dept_id']))) {
                continue;
            }
            $d = $u['dept_id'] !== null && isset($deptNames[$u['dept_id']]) ? $deptNames[$u['dept_id']] : null;
            $out[$u['id']] = array('user_id' => $u['id'], 'name' => $u['name'], 'dept_id' => $u['dept_id'], 'dept' => $d ? $d[0] : '',
                'head' => $d !== null && $d[1] !== null && $d[1] === $u['person_id']);
        }
        return $out;
    }

    public static function meta(Request $req)
    {
        $can = Permission::can($req->user, 'issue.send');
        $groups = array();
        if ($can) {
            foreach (self::allowedRecipients($req->user) as $r) {
                $k = $r['dept_id'] === null ? 0 : $r['dept_id'];
                if (!isset($groups[$k])) {
                    $groups[$k] = array('dept_id' => $r['dept_id'], 'dept' => $r['dept'], 'people' => array());
                }
                $groups[$k]['people'][] = array('user_id' => $r['user_id'], 'name' => $r['name'], 'head' => $r['head']);
            }
        }
        $scope = Permission::scope($req->user);
        return array('can_send' => $can, 'groups' => array_values($groups), 'wide' => $scope === 'unit' || $scope === 'all', 'freqs' => self::FREQS,
            'pending' => (int) DB::value("SELECT COUNT(*) FROM issue_recipients ir JOIN issues i ON i.id = ir.issue_id WHERE ir.user_id = ? AND i.status = 'active'
                AND ir.done_at IS NULL AND (i.need_reply = 1 OR ir.read_at IS NULL)", array($req->userId())));
    }

    private static function senderName($senderId)
    {
        $u = Notify::user($senderId);
        if ($u) {
            return $u['name'];
        }
        $r = DB::selectOne('SELECT u.display_name, p.name FROM users u LEFT JOIN personnel_profiles p ON p.id = u.person_id WHERE u.id = ?', array((int) $senderId));
        return $r ? ($r['name'] !== null && $r['name'] !== '' ? (string) $r['name'] : (string) $r['display_name']) : '';
    }

    private static function overdue(array $i, $doneAt)
    {
        return (int) $i['need_reply'] === 1 && $i['due_at'] !== null && (string) $i['status'] === 'active' && $doneAt === null && (string) $i['due_at'] < now();
    }

    /** Keyword fields of the lists (the browser offers the same keys); the received list also searches the sender. */
    const SEARCH = array('title' => 'i.title', 'body' => 'i.body');

    /** $f = list filters (period on the time the item was sent). */
    public static function index(Request $req, $box, $filter, array $f, $page, $size)
    {
        $uid = $req->userId();
        if ($box === 'sent') {
            $q = DB::table('issues i')->where('i.sender_id', $uid);
            if ($filter === 'pending') {
                $q->where('i.status', 'active');
            }
            $q->whereFilters($f, 'i.created_at', self::SEARCH);
            $res = $q->orderBy('i.id', 'DESC')->paginate($page, $size);
            $ids = array();
            foreach ($res['rows'] as $i) {
                $ids[] = (int) $i['id'];
            }
            $stats = array();
            if ($ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                foreach (DB::select("SELECT ir.issue_id, COUNT(*) AS n, SUM(ir.read_at IS NOT NULL) AS nread, SUM(ir.done_at IS NOT NULL) AS ndone,
                        SUM(i.need_reply = 1 AND i.due_at IS NOT NULL AND i.due_at < ? AND ir.done_at IS NULL) AS nover
                    FROM issue_recipients ir JOIN issues i ON i.id = ir.issue_id WHERE ir.issue_id IN ($marks) GROUP BY ir.issue_id", array_merge(array(now()), $ids)) as $s) {
                    $stats[(int) $s['issue_id']] = $s;
                }
            }
            $rows = array();
            foreach ($res['rows'] as $i) {
                $s = isset($stats[(int) $i['id']]) ? $stats[(int) $i['id']] : array('n' => 0, 'nread' => 0, 'ndone' => 0, 'nover' => 0);
                $rows[] = array('id' => (int) $i['id'], 'kind' => (string) $i['kind'], 'title' => (string) $i['title'], 'need_reply' => (int) $i['need_reply'] === 1,
                    'due' => $i['due_at'] === null ? '' : substr((string) $i['due_at'], 0, 16), 'status' => (string) $i['status'], 'at' => (string) $i['created_at'],
                    'n' => (int) $s['n'], 'read' => (int) $s['nread'], 'done' => (int) $s['ndone'], 'overdue' => (string) $i['status'] === 'active' ? (int) $s['nover'] : 0);
            }
            $res['rows'] = $rows;
            return $res;
        }
        $q = DB::table('issue_recipients ir')->join('issues i', 'i.id', '=', 'ir.issue_id')->leftJoin('users su', 'su.id', '=', 'i.sender_id')
            ->leftJoin('personnel_profiles sp', 'sp.id', '=', 'su.person_id')->where('ir.user_id', $uid)
            ->select(array('i.*', 'ir.read_at', 'ir.done_at', 'ir.id as rid'));
        if ($filter === 'pending') {
            $q->where('i.status', 'active')->whereNull('ir.done_at')->whereRaw('(i.need_reply = 1 OR ir.read_at IS NULL)');
        }
        $q->whereFilters($f, 'i.created_at', array_merge(self::SEARCH, array('sender' => array('sp.name', 'su.display_name'))));
        $res = $q->orderBy('i.id', 'DESC')->paginate($page, $size);
        $rows = array();
        foreach ($res['rows'] as $i) {
            $rows[] = array('id' => (int) $i['id'], 'kind' => (string) $i['kind'], 'title' => (string) $i['title'], 'need_reply' => (int) $i['need_reply'] === 1,
                'due' => $i['due_at'] === null ? '' : substr((string) $i['due_at'], 0, 16), 'status' => (string) $i['status'], 'at' => (string) $i['created_at'],
                'sender' => self::senderName((int) $i['sender_id']), 'read' => $i['read_at'] !== null, 'done' => $i['done_at'] !== null, 'overdue' => self::overdue($i, $i['done_at']));
        }
        $res['rows'] = $rows;
        return $res;
    }

    private static function load($id)
    {
        $i = DB::table('issues')->find((int) $id);
        if (!$i) {
            throw HttpException::notFound();
        }
        return $i;
    }

    private static function recipientRow($issueId, $userId)
    {
        return DB::table('issue_recipients')->where('issue_id', (int) $issueId)->where('user_id', (int) $userId)->first();
    }

    /** The item as its recipient or its sender sees it; others get 404 (logged). */
    public static function show(Request $req, $id)
    {
        $i = self::load($id);
        $uid = $req->userId();
        $base = array('id' => (int) $i['id'], 'kind' => (string) $i['kind'], 'title' => (string) $i['title'], 'body' => str_or_empty($i['body']),
            'need_reply' => (int) $i['need_reply'] === 1, 'due' => $i['due_at'] === null ? '' : substr((string) $i['due_at'], 0, 16), 'remind_freq' => (string) $i['remind_freq'],
            'status' => (string) $i['status'], 'at' => (string) $i['created_at'], 'withdrawn_at' => str_or_empty($i['withdrawn_at']),
            'sender' => self::senderName((int) $i['sender_id']), 'sender_dept' => '', 'files' => Files::forRecord('issue', (int) $i['id']));
        $su = Notify::user((int) $i['sender_id']);
        if ($su && $su['dept_id'] !== null) {
            $base['sender_dept'] = (string) DB::value('SELECT name FROM departments WHERE id = ?', array($su['dept_id']));
        }
        if ((int) $i['sender_id'] === $uid) {
            $rows = DB::table('issue_recipients ir')->join('users u', 'u.id', '=', 'ir.user_id')->leftJoin('personnel_profiles p', 'p.id', '=', 'u.person_id')
                ->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->where('ir.issue_id', (int) $i['id'])
                ->select(array('ir.*', 'u.display_name', 'p.name as person_name', 'd.name as dept_name'))->orderBy('d.sort')->orderBy('ir.id')->get();
            $list = array();
            foreach ($rows as $r) {
                $info = Scheduler::recipientInfo($i, $r);
                $list[] = array('user_id' => (int) $r['user_id'], 'name' => $r['person_name'] !== null ? (string) $r['person_name'] : (string) $r['display_name'],
                    'dept' => str_or_empty($r['dept_name']), 'read_at' => str_or_empty($r['read_at']), 'done_at' => str_or_empty($r['done_at']), 'reply' => str_or_empty($r['reply']),
                    'files' => Files::forRecord('issue_reply', (int) $r['id']), 'overdue' => self::overdue($i, $r['done_at']),
                    'late' => $r['done_at'] !== null && $i['due_at'] !== null && (string) $r['done_at'] > (string) $i['due_at'],
                    'nudge_count' => (int) $r['nudge_count'], 'nudged_at' => str_or_empty($r['nudged_at']), 'remind_count' => $info['remind_count'], 'next_remind' => $info['next_remind']);
            }
            Notify::markRead($uid, 'issue', (int) $i['id']);
            return array_merge($base, array('role' => 'sender', 'recipients' => $list));
        }
        $r = self::recipientRow((int) $i['id'], $uid);
        if (!$r) {
            Audit::denied($req, 'issue', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        $info = Scheduler::recipientInfo($i, $r);
        return array_merge($base, array('role' => 'recipient', 'mine' => array('read_at' => str_or_empty($r['read_at']), 'done_at' => str_or_empty($r['done_at']),
            'reply' => str_or_empty($r['reply']), 'files' => Files::forRecord('issue_reply', (int) $r['id']), 'overdue' => self::overdue($i, $r['done_at']),
            'late' => $r['done_at'] !== null && $i['due_at'] !== null && (string) $r['done_at'] > (string) $i['due_at'],
            'remind_count' => $info['remind_count'], 'next_remind' => $info['next_remind'])));
    }

    private static function recipientIds($raw)
    {
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array();
        foreach ($list as $x) {
            $x = trim((string) $x);
            if ($x !== '' && ctype_digit($x)) {
                $ids[(int) $x] = (int) $x;
            }
        }
        return array_values($ids);
    }

    public static function create(Request $req, array $in)
    {
        $v = Validator::check($in, array('title' => 'required|string|max:200', 'body' => 'nullable|string|max:5000', 'due_date' => 'nullable|date',
            'due_time' => 'nullable|string|max:5', 'remind_freq' => 'nullable|in:auto,day,half,2h'),
            array('title' => 'issue.field.title', 'body' => 'issue.field.body', 'due_date' => 'issue.field.due', 'due_time' => 'issue.field.due', 'remind_freq' => 'issue.field.freq'));
        $needReply = isset($in['need_reply']) && ($in['need_reply'] === true || in_array((string) $in['need_reply'], array('1', 'true'), true));
        $ids = self::recipientIds(isset($in['recipients']) ? $in['recipients'] : array());
        if (!$ids) {
            throw ValidationException::field('recipients', 'issue.err.no_recipients');
        }
        $allowed = self::allowedRecipients($req->user);
        foreach ($ids as $rid) {
            if (!isset($allowed[$rid])) {
                Audit::denied($req, 'issue_recipient', array('user_id' => $rid));
                throw ValidationException::field('recipients', 'issue.err.recipient_scope');
            }
        }
        $due = null;
        if ($needReply) {
            if (empty($v['due_date'])) {
                throw ValidationException::field('due_date', 'issue.err.due_required');
            }
            $time = !empty($v['due_time']) ? (string) $v['due_time'] : '17:30';
            if (!WorkCalendar::validTime($time)) {
                throw ValidationException::field('due_date', 'issue.err.due_time');
            }
            $due = $v['due_date'] . ' ' . $time . ':00';
            if ($due <= now()) {
                throw ValidationException::field('due_date', 'issue.err.due_past');
            }
        }
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        foreach ($list as $up) {
            Files::check($up);
        }
        $kind = $needReply ? 'order' : ($list ? 'doc' : 'tip');
        $freq = !empty($v['remind_freq']) ? (string) $v['remind_freq'] : 'auto';
        $uid = $req->userId();
        $title = (string) $v['title'];
        $id = DB::transaction(function () use ($v, $needReply, $due, $kind, $freq, $uid, $ids, $title) {
            $id = DB::table('issues')->insert(array('org_id' => (int) config('app.org_id', 1), 'sender_id' => $uid, 'kind' => $kind, 'title' => $title,
                'body' => isset($v['body']) && $v['body'] !== '' ? (string) $v['body'] : null, 'need_reply' => $needReply ? 1 : 0, 'due_at' => $due,
                'remind_freq' => $freq, 'status' => 'active', 'created_at' => now()));
            foreach ($ids as $rid) {
                DB::table('issue_recipients')->insert(array('issue_id' => $id, 'user_id' => $rid));
            }
            return $id;
        });
        if ($list) {
            $folders = array(Files::folder('file.folder.issues'), (string) $req->user['name'], $title, (string) $req->user['name']);
            foreach ($list as $up) {
                Files::store($req, $up, 'issue', $id, 'doc', $folders, $req->user['person_id']);
            }
        }
        Notify::send($ids, 'issue_sent_' . $kind, array('name' => $req->user['name'], 'title' => $title, 'due' => $due === null ? '' : substr($due, 0, 16)),
            '/affairs/issues?id=' . $id, 'issue', $id, $uid, $kind === 'order' ? 'warn' : 'info');
        Audit::op($req->user, 'issue', 'issue_sent', 'issue', $id, $title, array('kind' => $kind, 'recipients' => count($ids), 'due' => $due), $req->ip());
        return array('id' => $id, 'kind' => $kind);
    }

    private static function asRecipient(Request $req, $id)
    {
        $i = self::load($id);
        $r = self::recipientRow((int) $i['id'], $req->userId());
        if (!$r) {
            Audit::denied($req, 'issue', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        return array($i, $r);
    }

    /** Opening an item marks it read (and the bell messages about it). */
    public static function read(Request $req, $id)
    {
        list($i, $r) = self::asRecipient($req, $id);
        if ($r['read_at'] === null) {
            DB::table('issue_recipients')->where('id', (int) $r['id'])->whereNull('read_at')->update(array('read_at' => now()));
        }
        Notify::markRead($req->userId(), 'issue', (int) $i['id']);
        return array('ok' => true);
    }

    public static function reply(Request $req, $id, array $in)
    {
        list($i, $r) = self::asRecipient($req, $id);
        if ((string) $i['status'] !== 'active') {
            throw HttpException::conflict('issue.err.withdrawn');
        }
        if ((int) $i['need_reply'] !== 1) {
            throw HttpException::conflict('issue.err.no_reply_needed');
        }
        if ($r['done_at'] !== null) {
            throw HttpException::conflict('issue.err.already_done');
        }
        $v = Validator::check($in, array('reply' => 'required|string|max:2000'), array('reply' => 'issue.field.reply'));
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        foreach ($list as $up) {
            Files::check($up);
        }
        if (DB::table('issue_recipients')->where('id', (int) $r['id'])->whereNull('done_at')->update(array('done_at' => now(), 'reply' => (string) $v['reply'],
            'read_at' => $r['read_at'] === null ? now() : $r['read_at'])) === 0) {
            throw HttpException::conflict('issue.err.already_done');
        }
        if ($list) {
            $folders = array(Files::folder('file.folder.issues'), self::senderName((int) $i['sender_id']), (string) $i['title'], (string) $req->user['name']);
            foreach ($list as $up) {
                Files::store($req, $up, 'issue_reply', (int) $r['id'], 'reply', $folders, $req->user['person_id']);
            }
        }
        $late = $i['due_at'] !== null && now() > (string) $i['due_at'];
        Notify::markRead($req->userId(), 'issue', (int) $i['id']);
        Notify::send(array((int) $i['sender_id']), $late ? 'issue_done_late' : 'issue_done', array('name' => $req->user['name'], 'title' => (string) $i['title']),
            '/affairs/issues?tab=sent&id=' . (int) $i['id'], 'issue', (int) $i['id'], $req->userId());
        Audit::op($req->user, 'issue', 'issue_replied', 'issue', (int) $i['id'], (string) $i['title'], array('late' => $late), $req->ip());
        return array('ok' => true, 'late' => $late);
    }

    private static function asSender(Request $req, $id)
    {
        $i = self::load($id);
        if ((int) $i['sender_id'] !== $req->userId()) {
            Audit::denied($req, 'issue', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        if ((string) $i['status'] !== 'active') {
            throw HttpException::conflict('issue.err.withdrawn');
        }
        return $i;
    }

    /** Remind people who have not read (tips, documents) or finished (orders); once an hour per person. */
    public static function nudge(Request $req, $id)
    {
        $i = self::asSender($req, $id);
        $order = (int) $i['need_reply'] === 1;
        $rows = DB::table('issue_recipients')->where('issue_id', (int) $i['id'])->whereNull('done_at')->get();
        $limit = date('Y-m-d H:i:s', time() - 3600);
        $to = array();
        $wait = 0;
        foreach ($rows as $r) {
            if (!$order && $r['read_at'] !== null) {
                continue;
            }
            if ($r['nudged_at'] !== null && (string) $r['nudged_at'] > $limit) {
                $wait++;
                continue;
            }
            $to[] = $r;
        }
        if (!$to) {
            throw HttpException::conflict($wait ? 'issue.err.nudge_wait' : 'issue.err.nothing_to_nudge');
        }
        $ids = array();
        foreach ($to as $r) {
            DB::table('issue_recipients')->where('id', (int) $r['id'])->increment('nudge_count', 1, array('nudged_at' => now()));
            $ids[] = (int) $r['user_id'];
        }
        Notify::send($ids, 'issue_nudge', array('name' => $req->user['name'], 'title' => (string) $i['title'], 'due' => $i['due_at'] === null ? '' : substr((string) $i['due_at'], 0, 16)),
            '/affairs/issues?id=' . (int) $i['id'], 'issue', (int) $i['id'], $req->userId(), 'warn');
        Audit::op($req->user, 'issue', 'issue_nudged', 'issue', (int) $i['id'], (string) $i['title'], array('people' => count($ids)), $req->ip());
        return array('nudged' => count($ids), 'skipped' => $wait);
    }

    public static function withdraw(Request $req, $id)
    {
        $i = self::asSender($req, $id);
        DB::table('issues')->where('id', (int) $i['id'])->where('status', 'active')->update(array('status' => 'withdrawn', 'withdrawn_at' => now()));
        $open = array();
        foreach (DB::table('issue_recipients')->where('issue_id', (int) $i['id'])->whereNull('done_at')->get() as $r) {
            if ((int) $i['need_reply'] === 1 || $r['read_at'] === null) {
                $open[] = (int) $r['user_id'];
            }
        }
        Notify::send($open, 'issue_withdrawn', array('name' => $req->user['name'], 'title' => (string) $i['title']), '/affairs/issues?id=' . (int) $i['id'], 'issue', (int) $i['id'], $req->userId());
        Audit::op($req->user, 'issue', 'issue_withdrawn', 'issue', (int) $i['id'], (string) $i['title'], null, $req->ip());
        return array('ok' => true);
    }

    /** Attachments: documents → sender and recipients; reply files → sender and the uploader. Nothing can be removed after sending. */
    public static function fileAccess(Request $req, $bizType, $bizId, $write)
    {
        if ($write) {
            throw HttpException::forbidden();
        }
        $uid = $req->userId();
        if ($bizType === 'issue') {
            $i = DB::table('issues')->find((int) $bizId);
            if ($i && ((int) $i['sender_id'] === $uid || self::recipientRow((int) $i['id'], $uid))) {
                return;
            }
        } else {
            $r = DB::table('issue_recipients')->find((int) $bizId);
            $i = $r ? DB::table('issues')->find((int) $r['issue_id']) : null;
            if ($r && $i && ((int) $r['user_id'] === $uid || (int) $i['sender_id'] === $uid)) {
                return;
            }
        }
        Audit::denied($req, $bizType, array('id' => (int) $bizId));
        throw HttpException::notFound();
    }
}
