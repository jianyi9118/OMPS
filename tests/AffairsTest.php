<?php
namespace Omps\Tests;

use Omps\Database\DB;
use Omps\Engines\WorkCalendar;
use Omps\Modules\Affairs\Services\ReminderCalc;
use Omps\Modules\Affairs\Services\Scheduler;

/**
 * Phase 5: notifications, to-dos, notebook, issued items, reminder engine and rules, preferences.
 */
class AffairsTest extends TestCase
{
    /** @var array */
    private $f;

    public function setUp()
    {
        parent::setUp();
        $s = substr(md5(uniqid('', true)), 0, 4);
        $tech = $this->dept('技术部' . $s);
        $soft = $this->dept('软件组', $tech);
        $hr = $this->dept('综合部' . $s);
        $lead = $this->person('李建国', $tech);
        $wang = $this->person('王晓', $soft);
        $chen = $this->person('陈静', $soft);
        $zhou = $this->person('周敏', $hr);
        $boss = $this->person('张伟', null);
        DB::table('departments')->where('id', $tech)->update(array('leader_person_id' => $lead));
        DB::table('departments')->where('id', $hr)->update(array('leader_person_id' => $zhou));
        $this->f = array('tech' => $tech, 'soft' => $soft, 'lead' => $lead, 'wang' => $wang, 'chen' => $chen, 'zhou' => $zhou, 'boss' => $boss,
            'uLead' => $this->user('dept_head', $lead), 'uWang' => $this->user('member', $wang), 'uChen' => $this->user('member', $chen),
            'uZhou' => $this->user('dept_head', $zhou), 'uUnit' => $this->user('unit_head', $boss));
    }

    private function notes($userId, $type, $bizType = null, $bizId = null, $unreadOnly = false)
    {
        $q = DB::table('notifications')->where('user_id', (int) $userId)->where('type', $type);
        if ($bizType !== null) {
            $q->where('biz_type', $bizType)->where('biz_id', (int) $bizId);
        }
        if ($unreadOnly) {
            $q->whereNull('read_at');
        }
        return $q->count();
    }

    private function tmpFile($name, $content)
    {
        $p = sys_get_temp_dir() . '/omps-' . uniqid() . '-' . $name;
        file_put_contents($p, $content);
        return array('path' => $p, 'name' => $name);
    }

    public function testNotebook()
    {
        $f = $this->f;
        $this->login($f['uWang']['username']);
        $meta = $this->data($this->request('GET', '/api/v1/notes/meta'));
        $this->same('李建国', $meta['superior']['name'], 'member reports to the head of the department above the group');
        $this->same(false, $meta['inbox'], 'members have no received notes yet');
        $this->status(422, $this->request('POST', '/api/v1/notes', array('title' => '')), 'title required');
        $id = $this->data($this->request('POST', '/api/v1/notes', array('title' => '联调问题记录', 'body' => '秘密正文：汇总接口跨周重复计算')))['id'];
        $this->status(200, $this->request('PUT', '/api/v1/notes/' . $id, array('title' => '联调问题记录', 'body' => '秘密正文：已定位到周编号算法', 'version' => 1)));
        $this->status(409, $this->request('PUT', '/api/v1/notes/' . $id, array('title' => 'x', 'version' => 1)), 'stale version');

        // private: nobody else can read it, not even the head or the unit head
        foreach (array('uLead', 'uUnit', 'uChen') as $who) {
            $this->login($f[$who]['username']);
            $this->status(404, $this->request('GET', '/api/v1/notes/' . $id), $who . ' cannot read a private note');
            $this->same(0, count($this->data($this->request('GET', '/api/v1/notes', null, array('box' => 'inbox')))['rows']));
        }

        // submit → locked, the head is told; withdraw before reading retracts the message
        $this->login($f['uWang']['username']);
        $r = $this->data($this->request('POST', '/api/v1/notes/' . $id . '/submit'));
        $this->same('李建国', $r['to']);
        $this->status(409, $this->request('PUT', '/api/v1/notes/' . $id, array('title' => 'x')), 'locked after submit');
        $this->status(409, $this->request('DELETE', '/api/v1/notes/' . $id), 'cannot delete a submitted note');
        $this->same(1, $this->notes($f['uLead']['id'], 'note_submitted', 'note', $id));
        $this->status(200, $this->request('POST', '/api/v1/notes/' . $id . '/withdraw'));
        $this->same(0, $this->notes($f['uLead']['id'], 'note_submitted', 'note', $id), 'withdrawn: unread message removed');
        $this->status(200, $this->request('POST', '/api/v1/notes/' . $id . '/submit'));

        // the head reads it with a comment
        $this->login($f['uLead']['username']);
        $meta = $this->data($this->request('GET', '/api/v1/notes/meta'));
        $this->same(true, $meta['inbox']);
        $this->same(1, $meta['inbox_unread']);
        $rows = $this->data($this->request('GET', '/api/v1/notes', null, array('box' => 'inbox', 'filter' => 'unread')))['rows'];
        $this->same($id, $rows[0]['id']);
        $n = $this->data($this->request('GET', '/api/v1/notes/' . $id));
        $this->same(true, $n['caps']['read']);
        $this->same(0, $this->notes($f['uLead']['id'], 'note_submitted', 'note', $id, true), 'opening marks the message read');
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(in_array('note_read', array_column($todo['rows'], 'kind'), true), 'received note is a to-do');
        $this->status(200, $this->request('POST', '/api/v1/notes/' . $id . '/read', array('reply' => '先按部门分页，下周评审')));
        $this->status(409, $this->request('POST', '/api/v1/notes/' . $id . '/read', array()), 'already read');
        $this->same(1, $this->notes($f['uWang']['id'], 'note_replied', 'note', $id));

        // read notes stay as records; copy to continue
        $this->login($f['uWang']['username']);
        $this->status(409, $this->request('POST', '/api/v1/notes/' . $id . '/withdraw'), 'cannot withdraw after reading');
        $this->status(409, $this->request('DELETE', '/api/v1/notes/' . $id));
        $n = $this->data($this->request('GET', '/api/v1/notes/' . $id));
        $this->same('先按部门分页，下周评审', $n['reply']);
        $copy = $this->data($this->request('POST', '/api/v1/notes/' . $id . '/copy'))['id'];
        $this->same('private', (string) DB::value('SELECT status FROM notes WHERE id = ?', array($copy)));
        $this->status(200, $this->request('DELETE', '/api/v1/notes/' . $copy));

        // the department head reports to a unit head; the unit head has nobody above
        $this->login($f['uLead']['username']);
        $sup = $this->data($this->request('GET', '/api/v1/notes/meta'))['superior'];
        $this->same('unit', (string) DB::value('SELECT r.scope FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', array($sup['id'])));
        $this->login($f['uUnit']['username']);
        $this->same(null, $this->data($this->request('GET', '/api/v1/notes/meta'))['superior']);
        $nid = $this->data($this->request('POST', '/api/v1/notes', array('title' => '单位负责人的记事')))['id'];
        $this->status(409, $this->request('POST', '/api/v1/notes/' . $nid . '/submit'), 'no superior');

        // logs keep the title and the action, never the text
        $this->same(0, (int) DB::value("SELECT COUNT(*) FROM operation_logs WHERE module = 'note' AND detail LIKE ?", array('%秘密正文%')));
        $this->ok((int) DB::value("SELECT COUNT(*) FROM operation_logs WHERE module = 'note' AND action = 'note_submitted' AND target_id = ?", array($id)) >= 2);
    }

    public function testIssues()
    {
        $f = $this->f;
        $due = date('Y-m-d', time() + 3 * 86400);
        $this->login($f['uWang']['username']);
        $this->status(403, $this->request('POST', '/api/v1/issues', array('title' => 'x')), 'members cannot send');
        $this->same(false, $this->data($this->request('GET', '/api/v1/issues/meta'))['can_send']);

        $this->login($f['uLead']['username']);
        $meta = $this->data($this->request('GET', '/api/v1/issues/meta'));
        $ids = array();
        foreach ($meta['groups'] as $g) {
            foreach ($g['people'] as $p) {
                $ids[] = $p['user_id'];
            }
        }
        $this->ok(in_array($f['uWang']['id'], $ids, true) && in_array($f['uChen']['id'], $ids, true), 'own department and its groups');
        $this->ok(!in_array($f['uZhou']['id'], $ids, true) && !in_array($f['uLead']['id'], $ids, true), 'not other departments, not oneself');

        $base = array('title' => '报表功能上线前自测清单', 'body' => '请按附件清单逐项自测', 'need_reply' => '1', 'due_date' => $due, 'due_time' => '17:30');
        $this->status(422, $this->request('POST', '/api/v1/issues', array_merge($base, array('recipients' => (string) $f['uZhou']['id']))), 'outside scope');
        $this->status(422, $this->request('POST', '/api/v1/issues', array_merge($base, array('recipients' => ''))), 'recipients required');
        $this->status(422, $this->request('POST', '/api/v1/issues', array_merge($base, array('due_date' => '', 'recipients' => (string) $f['uWang']['id']))), 'deadline required');
        $this->status(422, $this->request('POST', '/api/v1/issues', array_merge($base, array('due_date' => date('Y-m-d', time() - 86400), 'recipients' => (string) $f['uWang']['id']))), 'deadline in the past');
        $doc = $this->tmpFile('自测清单.txt', "1. 导出\n2. 打印\n");
        $r = $this->upload('/api/v1/issues', array($doc), array_merge($base, array('recipients' => $f['uWang']['id'] . ',' . $f['uChen']['id'])));
        $this->status(200, $r);
        $iid = $this->data($r)['id'];
        $this->same('order', $this->data($r)['kind']);
        $tip = $this->data($this->request('POST', '/api/v1/issues', array('title' => '周五下午停电', 'need_reply' => '0', 'recipients' => (string) $f['uChen']['id'])));
        $this->same('tip', $tip['kind']);
        $docItem = $this->upload('/api/v1/issues', array($this->tmpFile('安全检查通知.txt', 'x')), array('title' => '年末信息安全检查', 'need_reply' => '0', 'recipients' => (string) $f['uChen']['id']));
        $this->same('doc', $this->data($docItem)['kind']);

        // recipient: bell, to-do, read on opening
        $this->same(1, $this->notes($f['uWang']['id'], 'issue_sent_order', 'issue', $iid));
        $this->login($f['uWang']['username']);
        $p = $this->data($this->request('GET', '/api/v1/me/pulse'));
        $this->ok($p['unread'] >= 1 && $p['counts']['issues'] === 1, 'pulse counts');
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->same('issue_order', $todo['rows'][0]['kind']);
        $x = $this->data($this->request('GET', '/api/v1/issues/' . $iid));
        $this->same('recipient', $x['role']);
        $this->same(1, count($x['files']));
        $docRel = $x['files'][0]['relation_id'];
        $this->status(200, $this->request('POST', '/api/v1/issues/' . $iid . '/read'));
        $this->ok(DB::value('SELECT read_at FROM issue_recipients WHERE issue_id = ? AND user_id = ?', array($iid, $f['uWang']['id'])) !== null);
        $this->same(0, $this->notes($f['uWang']['id'], 'issue_sent_order', 'issue', $iid, true), 'opening marks the bell message read');

        // nudge: unfinished people only, once an hour
        $this->login($f['uLead']['username']);
        $n = $this->data($this->request('POST', '/api/v1/issues/' . $iid . '/nudge'));
        $this->same(2, $n['nudged']);
        $this->status(409, $this->request('POST', '/api/v1/issues/' . $iid . '/nudge'), 'one nudge per hour');

        // reply with a file
        $this->login($f['uWang']['username']);
        $this->status(422, $this->request('POST', '/api/v1/issues/' . $iid . '/reply', array('reply' => '')), 'reply text required');
        $r = $this->upload('/api/v1/issues/' . $iid . '/reply', array($this->tmpFile('自测结果.txt', 'ok')), array('reply' => '已完成，发现 2 个问题'));
        $this->status(200, $r);
        $this->same(false, $this->data($r)['late']);
        $this->status(409, $this->request('POST', '/api/v1/issues/' . $iid . '/reply', array('reply' => 'again')), 'already replied');
        $this->same(1, $this->notes($f['uLead']['id'], 'issue_done', 'issue', $iid));
        $this->login($f['uChen']['username']);
        $this->status(409, $this->request('POST', '/api/v1/issues/' . $tip['id'] . '/reply', array('reply' => 'x')), 'tips need no reply');

        // sender view: progress and reply files; file visibility
        $this->login($f['uLead']['username']);
        $x = $this->data($this->request('GET', '/api/v1/issues/' . $iid));
        $this->same('sender', $x['role']);
        $byUser = array();
        foreach ($x['recipients'] as $rr) {
            $byUser[$rr['user_id']] = $rr;
        }
        $this->ok($byUser[$f['uWang']['id']]['done_at'] !== '' && count($byUser[$f['uWang']['id']]['files']) === 1);
        $this->same(1, $byUser[$f['uChen']['id']]['nudge_count']);
        $replyRel = $byUser[$f['uWang']['id']]['files'][0]['relation_id'];
        $this->status(200, $this->request('GET', '/api/v1/files/' . $replyRel), 'sender reads reply files');
        $sent = $this->data($this->request('GET', '/api/v1/issues', null, array('box' => 'sent')))['rows'];
        $row = null;
        foreach ($sent as $s) {
            if ($s['id'] === $iid) {
                $row = $s;
            }
        }
        $this->same(array(2, 1), array($row['n'], $row['done']));
        $this->login($f['uChen']['username']);
        $this->status(404, $this->request('GET', '/api/v1/files/' . $replyRel), 'other recipients cannot read reply files');
        $this->status(200, $this->request('GET', '/api/v1/files/' . $docRel), 'recipients read the documents');
        $this->status(403, $this->request('DELETE', '/api/v1/files/' . $docRel), 'documents cannot be removed');
        $this->login($f['uZhou']['username']);
        $this->status(404, $this->request('GET', '/api/v1/issues/' . $iid), 'not a recipient');
        $this->status(404, $this->request('GET', '/api/v1/files/' . $docRel));

        // withdraw: unfinished recipients are told; nothing can be done afterwards
        $this->login($f['uLead']['username']);
        $this->status(200, $this->request('POST', '/api/v1/issues/' . $iid . '/withdraw'));
        $this->same(1, $this->notes($f['uChen']['id'], 'issue_withdrawn', 'issue', $iid));
        $this->same(0, $this->notes($f['uWang']['id'], 'issue_withdrawn', 'issue', $iid), 'finished recipients are not told');
        $this->status(409, $this->request('POST', '/api/v1/issues/' . $iid . '/nudge'));
        $this->login($f['uChen']['username']);
        $this->status(409, $this->request('POST', '/api/v1/issues/' . $iid . '/reply', array('reply' => 'x')), 'withdrawn');
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(!in_array('/affairs/issues?id=' . $iid, array_column($todo['rows'], 'link'), true), 'withdrawn item leaves the to-dos');

        // the unit head can send to everyone, with a quick "department heads" choice
        $this->login($f['uUnit']['username']);
        $meta = $this->data($this->request('GET', '/api/v1/issues/meta'));
        $this->same(true, $meta['wide']);
        $heads = array();
        foreach ($meta['groups'] as $g) {
            foreach ($g['people'] as $p) {
                if ($p['head']) {
                    $heads[] = $p['user_id'];
                }
            }
        }
        $this->ok(in_array($f['uLead']['id'], $heads, true) && in_array($f['uZhou']['id'], $heads, true));
        $this->ok($this->auditOps('issue_sent') >= 3 && $this->auditOps('issue_nudged') >= 1 && $this->auditOps('issue_withdrawn') >= 1);
    }

    private function auditOps($action)
    {
        return (int) DB::value('SELECT COUNT(*) FROM operation_logs WHERE action = ?', array($action));
    }

    private function make(array $body)
    {
        $r = $this->request('POST', '/api/v1/plans', $body);
        $this->status(200, $r, 'create ' . $body['title']);
        return $this->data($r)['id'];
    }

    public function testReviewAndProfileNotifications()
    {
        $f = $this->f;
        $this->login($f['uUnit']['username']);
        $long = $this->make(array('title' => '2026 年度设备运维', 'level' => 'long', 'start' => '2026-01-01', 'end' => '2030-12-31', 'assignees' => array($f['lead'])));
        $this->login($f['uLead']['username']);
        $annual = $this->make(array('title' => '机房巡检', 'level' => 'annual', 'parent_id' => $long, 'start' => '2026-01-01', 'end' => '2026-12-31', 'assignees' => array($f['wang'])));

        $this->login($f['uWang']['username']);
        $this->status(200, $this->request('PUT', '/api/v1/perf/plans/' . $annual . '/mine', array('progress' => '30', 'done' => '巡检 7 台', 'submit' => true)));
        $rid = (int) DB::value('SELECT id FROM performance_reports WHERE plan_id = ? AND person_id = ?', array($annual, $f['wang']));
        $this->same(1, $this->notes($f['uLead']['id'], 'perf_submitted', 'performance', $rid), 'department head reviews members');
        $this->same(0, $this->notes($f['uUnit']['id'], 'perf_submitted', 'performance', $rid), 'unit head is not told when a department head exists');
        $this->status(200, $this->request('POST', '/api/v1/perf/reports/' . $rid . '/withdraw'));
        $this->same(0, $this->notes($f['uLead']['id'], 'perf_submitted', 'performance', $rid), 'withdrawn: message removed');
        $this->status(200, $this->request('PUT', '/api/v1/perf/plans/' . $annual . '/mine', array('progress' => '30', 'done' => '巡检 7 台', 'submit' => true)));

        $this->login($f['uLead']['username']);
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(in_array('/performance/review?focus=' . $rid, array_column($todo['rows'], 'link'), true), 'review is a to-do with a jump link');
        $this->status(200, $this->request('GET', '/api/v1/perf/reviews/' . $rid));
        $this->same(0, $this->notes($f['uLead']['id'], 'perf_submitted', 'performance', $rid, true), 'opening the review marks it read');
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/return', array('ids' => array($rid), 'reason' => '请补充数量')));
        $msg = DB::table('notifications')->where('user_id', $f['uWang']['id'])->where('type', 'perf_returned')->first();
        $this->same('请补充数量', json_decode($msg['params'], true)['reason']);
        $this->same('/plans/mine?plan=' . $annual, $msg['link']);
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(!in_array('/performance/review?focus=' . $rid, array_column($todo['rows'], 'link'), true), 'handled review leaves the to-dos');

        $this->login($f['uWang']['username']);
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(in_array('perf_returned', array_column($todo['rows'], 'kind'), true));
        $this->status(200, $this->request('PUT', '/api/v1/perf/plans/' . $annual . '/mine', array('progress' => '35', 'done' => '巡检 7 台，更换硬盘 2 块', 'submit' => true)));
        $this->login($f['uLead']['username']);
        $this->status(200, $this->request('POST', '/api/v1/perf/reviews/approve', array('ids' => array($rid))));
        $this->same(1, $this->notes($f['uWang']['id'], 'perf_approved', 'performance', $rid));

        // a department head's own result goes to the unit heads
        $this->status(200, $this->request('PUT', '/api/v1/perf/plans/' . $long . '/mine', array('progress' => '10', 'done' => '制定运维规范', 'submit' => true)));
        $lr = (int) DB::value('SELECT id FROM performance_reports WHERE plan_id = ? AND person_id = ?', array($long, $f['lead']));
        $this->same(1, $this->notes($f['uUnit']['id'], 'perf_submitted', 'performance', $lr));

        // profile change: the department head is told, then the member gets the result
        $this->login($f['uWang']['username']);
        $this->request('PUT', '/api/v1/me/profile', array('profile' => array('phone' => '13600003333')));
        $cid = (int) DB::value("SELECT id FROM profile_change_requests WHERE person_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", array($f['wang']));
        $this->ok($cid > 0);
        $this->same(1, $this->notes($f['uLead']['id'], 'change_submitted', 'change_request', $cid));
        $this->login($f['uLead']['username']);
        $this->status(200, $this->request('POST', '/api/v1/reviews/approve', array('ids' => array($cid))), 'approve change');
        $this->same(1, $this->notes($f['uWang']['id'], 'change_approved', 'change_request', $cid));

        // moved out of the department: the unit head is told and gets a to-do
        $this->status(200, $this->request('POST', '/api/v1/staff/' . $f['chen'] . '/remove', array('type' => 'move_out', 'date' => date('Y-m-d'))));
        $this->same(1, $this->notes($f['uUnit']['id'], 'staff_moved_out', 'person', $f['chen']));
        $this->login($f['uUnit']['username']);
        $todo = $this->data($this->request('GET', '/api/v1/todo'));
        $this->ok(in_array('staff_assign', array_column($todo['rows'], 'kind'), true));

        // bell: list, open, mark all read
        $this->login($f['uWang']['username']);
        $list = $this->data($this->request('GET', '/api/v1/notifications', null, array('tab' => 'unread')));
        $this->ok($list['unread'] >= 2 && count($list['rows']) === $list['unread']);
        $one = $this->data($this->request('POST', '/api/v1/notifications/' . $list['rows'][0]['id'] . '/read'));
        $this->same($list['unread'] - 1, $one['unread']);
        $this->status(404, $this->request('POST', '/api/v1/notifications/' . $this->firstNoteOf($f['uLead']['id']) . '/read'), 'only own messages');
        $this->status(200, $this->request('POST', '/api/v1/notifications/read-all'));
        $this->same(0, $this->data($this->request('GET', '/api/v1/me/pulse'))['unread']);
    }

    private function firstNoteOf($userId)
    {
        return (int) DB::value('SELECT id FROM notifications WHERE user_id = ? ORDER BY id LIMIT 1', array((int) $userId));
    }

    public function testCalendarAndTriggers()
    {
        $cal = new WorkCalendar(array(1, 2, 3, 4, 5), '08:30', '17:30');
        $ts = function ($s) {
            return (int) strtotime($s);
        };
        $fmt = function (array $list) {
            return array_map(function ($x) {
                return date('Y-m-d H:i', $x);
            }, $list);
        };
        $due = $ts('2026-11-30 17:00'); // Monday
        $one = function (array $t, $base) use ($cal, $fmt, $ts) {
            return $fmt(ReminderCalc::slots($t, $base, $cal, $ts('2026-11-01'), $ts('2027-01-31')));
        };
        $this->same(array('2026-11-27 09:00'), $one(array('mode' => 'before', 'amount' => 1, 'unit' => 'd', 'time_of_day' => '09:00'), $due), 'Sunday moves to Friday');
        $this->same(array('2026-11-30 09:00'), $one(array('mode' => 'onday', 'time_of_day' => '09:00'), $due));
        $this->same(array('2026-11-30 15:00'), $one(array('mode' => 'before', 'amount' => 2, 'unit' => 'h'), $due));
        $this->same(array('2026-11-26 09:00'), $one(array('mode' => 'before', 'amount' => 2, 'unit' => 'wd', 'time_of_day' => '09:00'), $due));
        $sunday = $ts('2026-11-29 12:00');
        $this->same(array('2026-11-27 09:00'), $one(array('mode' => 'onday', 'time_of_day' => '09:00'), $sunday), 'non-workday due: on the day → previous workday');
        $this->same(array('2026-11-30 11:00'), $one(array('mode' => 'evt', 'amount' => 4, 'unit' => 'wh'), $ts('2026-11-27 16:00')), 'work hours skip the weekend');
        $this->same(array('2026-11-30 08:30'), $one(array('mode' => 'evt', 'amount' => 24, 'unit' => 'h'), $ts('2026-11-28 10:00')), 'outside work time → next start');
        $this->same(array('2026-11-30 09:00'), $one(array('mode' => 'evt', 'amount' => 1, 'unit' => 'wd', 'time_of_day' => '09:00'), $ts('2026-11-27 10:00')));
        $this->same(array('2026-12-01 09:00', '2026-12-02 09:00', '2026-12-03 09:00'),
            $fmt(ReminderCalc::slots(array('mode' => 'after', 'repeat_mode' => 'wday', 'time_of_day' => '09:00'), $due, $cal, $due, $ts('2027-01-31'), 3)), 'overdue repeats capped');
        $this->same(array('2026-12-04 09:00', '2026-12-07 09:00'),
            $fmt(ReminderCalc::slots(array('mode' => 'after', 'repeat_mode' => 'day', 'time_of_day' => '09:00'), $ts('2026-12-03 17:00'), $cal, $due, $ts('2027-01-31'), 2)), 'every day: weekend joins Monday');
        $this->same(array('2026-11-27 17:00', '2026-11-30 17:00'),
            $fmt(ReminderCalc::slots(array('mode' => 'wday', 'time_of_day' => '17:00'), null, $cal, $ts('2026-11-27 00:00'), $ts('2026-11-30 23:59'))));
        $this->same(array('2026-11-30 16:00'),
            $fmt(ReminderCalc::slots(array('mode' => 'mlast', 'time_of_day' => '16:00'), null, $cal, $ts('2026-11-01'), $ts('2026-11-30 23:59'))), 'last workday of November');
        $this->same(array('2026-11-30 09:00'),
            $fmt(ReminderCalc::slots(array('mode' => 'mday', 'month_day' => 28, 'time_of_day' => '09:00'), null, $cal, $ts('2026-11-01'), $ts('2026-11-30 23:59'))), 'Saturday 28th → Monday');
        $this->same(true, $cal->inWorkTime($ts('2026-11-30 08:30')));
        $this->same(false, $cal->inWorkTime($ts('2026-11-30 17:30')));
        $this->same(false, $cal->inWorkTime($ts('2026-11-28 10:00')));
    }

    public function testSchedulerRemindersOnce()
    {
        $f = $this->f;
        DB::table('user_prefs')->insert(array('user_id' => $f['uChen']['id'], 'pkey' => 'quiet_on', 'pvalue' => '1'));
        DB::table('user_prefs')->insert(array('user_id' => $f['uChen']['id'], 'pkey' => 'quiet_from', 'pvalue' => '09:00'));
        DB::table('user_prefs')->insert(array('user_id' => $f['uChen']['id'], 'pkey' => 'quiet_to', 'pvalue' => '10:00'));
        $iid = DB::table('issues')->insert(array('sender_id' => $f['uLead']['id'], 'kind' => 'order', 'title' => '整理验收会议纪要', 'need_reply' => 1, 'due_at' => '2026-11-30 17:00:00',
            'remind_freq' => 'auto', 'status' => 'active', 'created_at' => '2026-11-23 10:00:00'));
        DB::table('issue_recipients')->insert(array('issue_id' => $iid, 'user_id' => $f['uWang']['id'], 'read_at' => '2026-11-23 11:00:00'));
        DB::table('issue_recipients')->insert(array('issue_id' => $iid, 'user_id' => $f['uChen']['id'], 'read_at' => '2026-11-23 11:00:00'));
        $count = function ($user, $type) use ($iid) {
            return (int) DB::value('SELECT COUNT(*) FROM notifications WHERE biz_type = ? AND biz_id = ? AND user_id = ? AND type = ?', array('issue', $iid, $user, $type));
        };

        $this->same('off_hours', Scheduler::run(strtotime('2026-11-28 10:00'))['skipped'], 'no reminders on Saturday');
        Scheduler::run(strtotime('2026-11-27 09:05'));
        $this->same(1, $count($f['uWang']['id'], 'remind_issue_due'), 'one day before 09:00');
        $this->same(0, $count($f['uChen']['id'], 'remind_issue_due'), 'quiet hours postpone');
        Scheduler::run(strtotime('2026-11-27 09:10'));
        $this->same(1, $count($f['uWang']['id'], 'remind_issue_due'), 'never twice for the same trigger point');
        Scheduler::run(strtotime('2026-11-27 10:05'));
        $this->same(1, $count($f['uChen']['id'], 'remind_issue_due'), 'sent after quiet hours');
        Scheduler::run(strtotime('2026-11-30 15:05'));
        $this->same(2, $count($f['uWang']['id'], 'remind_issue_due'), 'on the day 09:00 was missed: only the latest point (2 h before) is sent, no flood');
        Scheduler::run(strtotime('2026-11-30 15:30'));
        $this->same(2, $count($f['uWang']['id'], 'remind_issue_due'), 'the missed 09:00 point is not sent later');

        // overdue: every workday, the sender told once who is overdue
        DB::table('issue_recipients')->where('issue_id', $iid)->where('user_id', $f['uChen']['id'])->update(array('done_at' => '2026-11-30 16:00:00', 'reply' => 'ok'));
        Scheduler::run(strtotime('2026-12-01 09:05'));
        Scheduler::run(strtotime('2026-12-02 09:05'));
        $this->same(2, $count($f['uWang']['id'], 'remind_issue_overdue'));
        $this->same(0, $count($f['uChen']['id'], 'remind_issue_overdue'), 'finished people are not reminded');
        $this->same(1, $count($f['uLead']['id'], 'issue_overdue_list'));
        $list = DB::table('notifications')->where('user_id', $f['uLead']['id'])->where('type', 'issue_overdue_list')->where('biz_id', $iid)->first();
        $this->same(array('王晓'), json_decode($list['params'], true)['names']);

        // results: today's day plan at 17:00, a month plan one workday before its end
        $this->login($f['uUnit']['username']);
        $long = $this->make(array('title' => '数字化规划', 'level' => 'long', 'start' => '2026-01-01', 'end' => '2030-12-31', 'assignees' => array($f['lead'])));
        $this->login($f['uLead']['username']);
        $annual = $this->make(array('title' => '年度建设', 'level' => 'annual', 'parent_id' => $long, 'start' => '2026-01-01', 'end' => '2026-12-31', 'assignees' => array($f['wang'])));
        $this->login($f['uWang']['username']);
        $q = $this->make(array('title' => '第四季度', 'level' => 'quarter', 'parent_id' => $annual, 'start' => '2026-10-01', 'end' => '2026-12-31'));
        $m = $this->make(array('title' => '11 月报表', 'level' => 'month', 'parent_id' => $q, 'start' => '2026-11-01', 'end' => '2026-11-30'));
        $day = $this->make(array('title' => '11-27 联调', 'level' => 'day', 'parent_id' => $m, 'start' => '2026-11-27', 'end' => '2026-11-27'));
        Scheduler::run(strtotime('2026-11-27 17:05'));
        $this->same(1, $this->notes($f['uWang']['id'], 'remind_perf_daily', 'plan', $day));
        $this->same(1, $this->notes($f['uWang']['id'], 'remind_perf_period', 'plan', $m));
        $this->same(0, $this->notes($f['uWang']['id'], 'remind_perf_period', 'plan', $q), 'quarter ends later');

        // review waiting 24 hours → reviewer reminded
        $this->status(200, $this->request('PUT', '/api/v1/perf/plans/' . $annual . '/mine', array('progress' => '20', 'done' => '方案评审', 'submit' => true)));
        $rid = (int) DB::value('SELECT id FROM performance_reports WHERE plan_id = ? AND person_id = ?', array($annual, $f['wang']));
        $sub = strtotime((string) DB::value('SELECT submitted_at FROM performance_reports WHERE id = ?', array($rid)));
        $at = ReminderCalc::slots(array('mode' => 'evt', 'amount' => 24, 'unit' => 'h'), $sub, WorkCalendar::fromSettings(), $sub, $sub + 86400 * 10);
        Scheduler::run($at[0] + 300);
        $this->same(1, $this->notes($f['uLead']['id'], 'remind_review', 'performance', $rid));
    }

    public function testRulesPrefsAndPages()
    {
        $f = $this->f;
        $this->login($f['uWang']['username']);
        $this->status(403, $this->request('GET', '/api/v1/reminder-rules'), 'members cannot change rules');
        $this->status(403, $this->request('GET', '/system/reminders'));
        foreach (array('/affairs/todo', '/affairs/issues', '/affairs/notes', '/affairs/notifications') as $p) {
            $r = $this->request('GET', $p, null, array(), array('Accept' => 'text/html'));
            $this->status(200, $r, $p);
        }
        $home = $this->request('GET', '/me/profile', null, array(), array('Accept' => 'text/html'));
        $this->contains('我的事务', $home->body, 'member menu has 我的事务');
        $this->contains('id="bellBtn"', $home->body);

        // preferences
        $this->status(422, $this->request('PUT', '/api/v1/me/prefs', array('quiet_on' => true, 'quiet_from' => '25:00', 'quiet_to' => '13:00')));
        $this->status(422, $this->request('PUT', '/api/v1/me/prefs', array('quiet_on' => true, 'quiet_from' => '12:00', 'quiet_to' => '12:00')));
        $this->status(200, $this->request('PUT', '/api/v1/me/prefs', array('quiet_on' => true, 'quiet_from' => '12:00', 'quiet_to' => '13:30', 'popup' => false)));
        $p = $this->data($this->request('GET', '/api/v1/me/prefs'));
        $this->same(array('1', '12:00', '13:30', '0'), array($p['quiet_on'], $p['quiet_from'], $p['quiet_to'], $p['popup']));

        // rules (unit head)
        $this->login($f['uUnit']['username']);
        $this->status(200, $this->request('GET', '/system/reminders', null, array(), array('Accept' => 'text/html')));
        $rules = array();
        foreach ($this->data($this->request('GET', '/api/v1/reminder-rules'))['rules'] as $r) {
            $rules[$r['code']] = $r;
        }
        $this->same(array('issue_unread', 'issue_due', 'issue_overdue', 'perf_daily', 'perf_period', 'review_pending', 'license_expire'), array_keys($rules));
        $id = $rules['issue_due']['id'];
        $this->status(422, $this->request('PUT', '/api/v1/reminder-rules/' . $id, array('triggers' => array())), 'at least one trigger');
        $this->status(422, $this->request('PUT', '/api/v1/reminder-rules/' . $id, array('triggers' => array(array('mode' => 'before', 'amount' => 1, 'unit' => 'd')))), 'day triggers need a time');
        $this->status(422, $this->request('PUT', '/api/v1/reminder-rules/' . $id, array('triggers' => array(array('mode' => 'before', 'amount' => 0, 'unit' => 'h')))), 'amount > 0');
        $this->status(422, $this->request('PUT', '/api/v1/reminder-rules/' . $id, array('triggers' => array(array('mode' => 'wday', 'time_of_day' => '09:00')))), 'mode not allowed for the base');
        $two = array(array('mode' => 'before', 'amount' => 2, 'unit' => 'h'), array('mode' => 'before', 'amount' => 2, 'unit' => 'h'));
        $this->status(422, $this->request('PUT', '/api/v1/reminder-rules/' . $id, array('triggers' => $two)), 'duplicates');
        $new = array(array('mode' => 'before', 'amount' => 3, 'unit' => 'd', 'time_of_day' => '09:00'), array('mode' => 'onday', 'time_of_day' => '08:30'));
        $prev = $this->data($this->request('POST', '/api/v1/reminder-rules/preview', array('id' => $id, 'triggers' => $new)));
        $this->same(2, count($prev['rows']));
        $this->ok(count($prev['rows'][0]['times']) === 1 && $prev['base'] !== '');
        $this->status(200, $this->request('PUT', '/api/v1/reminder-rules/' . $id, array('triggers' => $new, 'level' => 'urgent', 'enabled' => true)));
        $this->same(2, (int) DB::value('SELECT COUNT(*) FROM reminder_triggers WHERE rule_id = ?', array($id)));
        $this->same(1, $this->auditCount('reminder_rule_updated'));
        $oid = $rules['issue_overdue']['id'];
        $this->status(422, $this->request('PUT', '/api/v1/reminder-rules/' . $oid, array('triggers' => $rules['issue_overdue']['triggers'], 'max_repeat' => 0)), 'max repeat 1–30');
        // restore the defaults for other tests
        $this->status(200, $this->request('PUT', '/api/v1/reminder-rules/' . $id, array('triggers' => $rules['issue_due']['triggers'], 'level' => 'warn', 'enabled' => true)));

        // work time in system settings (super admin)
        $super = $this->user('super');
        $this->login($super['username']);
        $s = $this->data($this->request('GET', '/api/v1/settings'));
        $this->same('08:30', $s['work_start']);
        $body = $s;
        unset($body['languages'], $body['limits']);
        $body['unit_name'] = $s['unit_name'] !== '' ? $s['unit_name'] : 'Test Unit';
        $this->status(422, $this->request('PUT', '/api/v1/settings', array_merge($body, array('work_start' => '18:00', 'work_end' => '17:30'))), 'end after start');
        $this->status(200, $this->request('PUT', '/api/v1/settings', array_merge($body, array('work_days' => array(1, 2, 3, 4, 5, 6)))));
        $this->same('1,2,3,4,5,6', (string) \Omps\Core\Settings::get('work_days'));
        $this->status(200, $this->request('PUT', '/api/v1/settings', array_merge($body, array('work_days' => array(1, 2, 3, 4, 5)))));
    }
}
