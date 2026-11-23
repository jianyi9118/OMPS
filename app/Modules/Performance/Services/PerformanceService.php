<?php
namespace Omps\Modules\Performance\Services;

use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;
use Omps\Engines\Notify;
use Omps\Engines\Permission;
use Omps\Modules\Plan\Services\PlanService;

/**
 * Results written on plans (design §08–§09, phase 4).
 *
 *  - Each assignee writes one result per plan: completion %, what was done, problems, next steps,
 *    attachments. Save keeps a draft; submit sends it for review.
 *  - Review chain is fixed (design §06): a reviewer has perf.audit and a data scope covering the
 *    person, never the person themself. Whole-unit roles have nobody above them, so their own
 *    results take effect when submitted.
 *  - The last approved values are kept in appr_*; progress and reports use only those, so a person
 *    can update an approved result and the old approval stays valid until the new one is approved.
 *  - Completion of a plan = average over its assignees of (approved % of that person, or the
 *    weighted completion of the sub-plans when the person has no approved result yet, else 0).
 *    Sub-plans are weighted by local_weight; a parent is never counted twice.
 */
class PerformanceService
{
    const STATUSES = array('draft', 'submitted', 'approved', 'returned', 'withdrawn');
    const EDITABLE = array('draft', 'returned', 'withdrawn', 'approved');

    /** People whose own results take effect at once (nobody above them). */
    public static function selfApproves($user)
    {
        $s = Permission::scope($user);
        return $s === 'all' || $s === 'unit';
    }

    /** Results on these plans: [plan_id][person_id] => row. */
    public static function byPlans(array $planIds)
    {
        $out = array();
        if (!$planIds) {
            return $out;
        }
        foreach (array_chunk(array_values($planIds), 500) as $chunk) {
            foreach (DB::table('performance_reports')->whereIn('plan_id', $chunk)->orderBy('id')->get() as $r) {
                $out[(int) $r['plan_id']][(int) $r['person_id']] = $r;
            }
        }
        return $out;
    }

    private static function kids(array $plans)
    {
        $kids = array();
        foreach ($plans as $id => $p) {
            if ($p['parent_id'] !== null && isset($plans[(int) $p['parent_id']])) {
                $kids[(int) $p['parent_id']][] = $id;
            }
        }
        return $kids;
    }

    /** Completion of every plan (0–100, one decimal), from approved results only. */
    public static function progressMap(array $plans, array $reports)
    {
        $kids = self::kids($plans);
        $memo = array();
        $calc = function ($id) use (&$calc, &$memo, $plans, $kids, $reports) {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            $memo[$id] = 0.0;
            $roll = null;
            if (!empty($kids[$id])) {
                $sw = 0.0;
                $sum = 0.0;
                $plain = 0.0;
                foreach ($kids[$id] as $k) {
                    $w = (float) $plans[$k]['local_weight'];
                    $v = $calc($k);
                    $sw += $w;
                    $sum += $w * $v;
                    $plain += $v;
                }
                $roll = $sw > 0 ? $sum / $sw : $plain / count($kids[$id]);
            }
            $as = $plans[$id]['assignees'];
            if (!$as) {
                $v = $roll === null ? 0.0 : $roll;
            } else {
                $t = 0.0;
                foreach ($as as $pid) {
                    $r = isset($reports[$id][$pid]) ? $reports[$id][$pid] : null;
                    if ($r !== null && $r['appr_progress'] !== null) {
                        $t += (float) $r['appr_progress'];
                    } elseif ($roll !== null) {
                        $t += $roll;
                    }
                }
                $v = $t / count($as);
            }
            $memo[$id] = round(min(100, max(0, $v)), 1);
            return $memo[$id];
        };
        foreach ($plans as $id => $_) {
            $calc($id);
        }
        return $memo;
    }

    /**
     * Suggested completion for a person on a plan: own sub-plans weighted by their share, each counted
     * with the person's latest % (drafts included: it is their own figure) or, failing that, its own
     * suggestion. null when the person has no sub-plans here.
     */
    public static function reference($personId, $planId, array $plans, array $reports)
    {
        $kids = self::kids($plans);
        $walk = function ($id, $depth) use (&$walk, $personId, $plans, $kids, $reports) {
            $mine = array();
            foreach (isset($kids[$id]) ? $kids[$id] : array() as $k) {
                if (in_array($personId, $plans[$k]['assignees'], true)) {
                    $mine[] = $k;
                }
            }
            if (!$mine || $depth > 12) {
                return null;
            }
            $sw = 0.0;
            $sum = 0.0;
            $plain = 0.0;
            foreach ($mine as $k) {
                $r = isset($reports[$k][$personId]) ? $reports[$k][$personId] : null;
                if ($r !== null && $r['progress'] !== null) {
                    $v = (float) $r['progress'];
                } else {
                    $sub = $walk($k, $depth + 1);
                    $v = $sub === null ? 0.0 : $sub;
                }
                $w = (float) $plans[$k]['local_weight'];
                $sw += $w;
                $sum += $w * $v;
                $plain += $v;
            }
            return round($sw > 0 ? $sum / $sw : $plain / count($mine), 1);
        };
        return $walk((int) $planId, 0);
    }

    // ---------- who may see a result ----------

    private static function personRows(array $ids)
    {
        $out = array();
        if ($ids) {
            foreach (DB::table('personnel_profiles')->whereIn('id', array_values(array_unique($ids)))->select(array('id', 'name', 'dept_id'))->orderBy('id')->get() as $p) {
                $out[(int) $p['id']] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'dept_id' => int_or_null($p['dept_id']));
            }
        }
        return $out;
    }

    private static function isMe($user, $personId)
    {
        return $user['person_id'] !== null && (int) $user['person_id'] === (int) $personId;
    }

    /** May the user read someone's result? Own always; others: department / unit scope covering the person. */
    private static function maySee($user, array $person)
    {
        if (self::isMe($user, $person['id'])) {
            return true;
        }
        return Permission::scope($user) !== 'self' && Permission::personInScope($user, $person);
    }

    /** The fields another person may read: the current ones once submitted, else the last approved ones. */
    private static function shown(array $r, $own)
    {
        $status = (string) $r['status'];
        if ($own || in_array($status, array('submitted', 'approved', 'returned'), true)) {
            return array('progress' => self::num($r['progress']), 'done' => str_or_empty($r['done_content']), 'problems' => str_or_empty($r['problems']),
                'next' => str_or_empty($r['next_steps']), 'approved_only' => false);
        }
        if ($r['appr_at'] !== null) {
            return array('progress' => self::num($r['appr_progress']), 'done' => str_or_empty($r['appr_content']), 'problems' => str_or_empty($r['appr_problems']),
                'next' => str_or_empty($r['appr_next']), 'approved_only' => true);
        }
        return null;
    }

    private static function num($v)
    {
        return $v === null ? null : round((float) $v, 2);
    }

    private static function brief(array $p)
    {
        return array('id' => (int) $p['id'], 'title' => (string) $p['title'], 'level' => (string) $p['level'], 'start' => (string) $p['start_date'], 'end' => (string) $p['end_date'],
            'tl_mode' => (string) $p['time_label_mode'], 'tl_text' => str_or_empty($p['time_label_text']), 'parent_id' => int_or_null($p['parent_id']));
    }

    // ---------- hooks used by the plan pages ----------

    /** Result texts the user may read, for the plan search box: [plan_id => text]. */
    public static function searchText($user, array $plans)
    {
        $reports = self::byPlans(array_keys($plans));
        $ids = array();
        foreach ($reports as $byPerson) {
            foreach ($byPerson as $pid => $_) {
                $ids[] = $pid;
            }
        }
        $people = self::personRows($ids);
        $out = array();
        foreach ($reports as $planId => $byPerson) {
            foreach ($byPerson as $pid => $r) {
                if (!isset($people[$pid]) || !self::maySee($user, $people[$pid])) {
                    continue;
                }
                $s = self::shown($r, self::isMe($user, $pid));
                if ($s !== null) {
                    $out[$planId] = (isset($out[$planId]) ? $out[$planId] . ' ' : '') . $s['done'] . ' ' . $s['problems'];
                }
            }
        }
        return $out;
    }

    /** Adds completion, the user's own result and result summaries to plan rows. */
    public static function decorate($user, array $rows, array $plans)
    {
        $reports = self::byPlans(array_keys($plans));
        $prog = self::progressMap($plans, $reports);
        $ids = array();
        foreach ($rows as $row) {
            foreach ($row['assignees'] as $a) {
                $ids[] = $a;
            }
        }
        $people = self::personRows($ids);
        $me = $user['person_id'] === null ? null : (int) $user['person_id'];
        $canWrite = Permission::can($user, 'perf.edit') && $me !== null;
        $today = date('Y-m-d');
        foreach ($rows as $i => $row) {
            $id = $row['id'];
            $rows[$i]['progress'] = isset($prog[$id]) ? $prog[$id] : 0.0;
            $mine = $me !== null && isset($reports[$id][$me]) ? $reports[$id][$me] : null;
            $assigned = $me !== null && in_array($me, $row['assignees'], true);
            $rows[$i]['my'] = $mine ? array('id' => (int) $mine['id'], 'status' => (string) $mine['status'], 'progress' => self::num($mine['progress'])) : null;
            $counts = array();
            $snippet = '';
            $snipAt = '';
            $seen = false;
            foreach ($row['assignees'] as $pid) {
                $r = isset($reports[$id][$pid]) ? $reports[$id][$pid] : null;
                $st = $r ? (string) $r['status'] : 'none';
                $counts[$st] = isset($counts[$st]) ? $counts[$st] + 1 : 1;
                if ($r && isset($people[$pid]) && self::maySee($user, $people[$pid])) {
                    $s = self::shown($r, self::isMe($user, $pid));
                    if ($s !== null && ($s['done'] !== '' || $s['progress'] !== null)) {
                        $seen = true;
                        $at = (string) ($r['updated_at'] !== null ? $r['updated_at'] : $r['created_at']);
                        if ($s['done'] !== '' && $at >= $snipAt) {
                            $snippet = mb_substr(preg_replace('/\s+/u', ' ', $s['done']), 0, 60);
                            $snipAt = $at;
                        }
                    }
                }
            }
            $rows[$i]['results'] = array('counts' => $counts, 'snippet' => $snippet);
            $ctx = !empty($row['context']);
            $rows[$i]['caps']['write'] = !$ctx && $canWrite && $assigned && (!$mine || in_array((string) $mine['status'], self::EDITABLE, true));
            $rows[$i]['caps']['results'] = !$ctx && ($seen || $mine !== null);
            $rows[$i]['due_today'] = $assigned && $row['level'] === 'day' && $row['start'] <= $today && $row['end'] >= $today
                && (!$mine || in_array((string) $mine['status'], array('draft', 'returned', 'withdrawn'), true));
        }
        return $rows;
    }

    /** Results that would go with a deleted branch: [non-draft count]. */
    public static function branchResults(array $planIds)
    {
        if (!$planIds) {
            return 0;
        }
        return (int) DB::table('performance_reports')->whereIn('plan_id', $planIds)->whereIn('status', array('submitted', 'approved'))->count();
    }

    /** After plans were deleted: unsubmitted results go too, their attachments to the recycle bin. */
    public static function onPlansDeleted(Request $req, array $planIds)
    {
        if (!$planIds) {
            return;
        }
        $ids = DB::table('performance_reports')->whereIn('plan_id', $planIds)->whereIn('status', array('draft', 'returned', 'withdrawn'))->pluck('id');
        if (!$ids) {
            return;
        }
        $ids = array_map('intval', $ids);
        $uid = $req->userId();
        DB::transaction(function () use ($ids, $uid) {
            \Omps\Engines\Files::removeRelations(\Omps\Engines\Files::relationsOf('performance', $ids), $uid);
            DB::table('performance_versions')->whereIn('report_id', $ids)->delete();
            DB::table('performance_reports')->whereIn('id', $ids)->delete();
        });
    }

    // ---------- writing ----------

    private static function present(array $r)
    {
        return array(
            'id' => (int) $r['id'], 'plan_id' => (int) $r['plan_id'], 'person_id' => (int) $r['person_id'], 'status' => (string) $r['status'],
            'progress' => self::num($r['progress']), 'done' => str_or_empty($r['done_content']), 'problems' => str_or_empty($r['problems']), 'next' => str_or_empty($r['next_steps']),
            'submitted_at' => str_or_empty($r['submitted_at']), 'reviewed_at' => str_or_empty($r['reviewed_at']), 'comment' => str_or_empty($r['review_comment']),
            'score' => self::num($r['score']), 'edited' => (int) $r['reviewer_edited'] === 1, 'approved' => $r['appr_at'] !== null ? array(
                'progress' => self::num($r['appr_progress']), 'done' => str_or_empty($r['appr_content']), 'at' => (string) $r['appr_at']) : null,
            'updated_at' => str_or_empty($r['updated_at'] !== null ? $r['updated_at'] : $r['created_at']), 'version' => (int) $r['version'],
        );
    }

    /** The plan, all plans, and the user's person id; the user must be an assignee with perf.edit. */
    private static function writable(Request $req, $planId)
    {
        list($p, $plans) = PlanService::find($req, $planId);
        $me = $req->user['person_id'];
        if (!Permission::can($req->user, 'perf.edit') || $me === null || !in_array((int) $me, $p['assignees'], true)) {
            throw new HttpException(403, 'forbidden', 'perf.err.not_assignee');
        }
        return array($p, $plans, (int) $me);
    }

    /** The write dialog: my result, my results on sub-plans, the suggested %, attachments. */
    public static function mine(Request $req, $planId)
    {
        list($p, $plans, $me) = self::writable($req, $planId);
        $branch = PlanService::branch($p['id']);
        $reports = self::byPlans(array_keys($plans));
        $r = isset($reports[(int) $p['id']][$me]) ? $reports[(int) $p['id']][$me] : null;
        $sub = array();
        foreach ($branch as $bid) {
            if ($bid === (int) $p['id'] || !isset($reports[$bid][$me]) || !isset($plans[$bid])) {
                continue;
            }
            $x = $reports[$bid][$me];
            $sub[] = array('plan' => self::brief($plans[$bid]), 'status' => (string) $x['status'], 'progress' => self::num($x['progress']), 'done' => str_or_empty($x['done_content']));
        }
        usort($sub, function ($a, $b) {
            if ($a['plan']['start'] !== $b['plan']['start']) {
                return strcmp($a['plan']['start'], $b['plan']['start']);
            }
            return array_search($a['plan']['level'], PlanService::LEVELS, true) - array_search($b['plan']['level'], PlanService::LEVELS, true);
        });
        return array(
            'plan' => self::brief($p), 'report' => $r ? self::present($r) : null, 'sub' => $sub,
            'reference' => self::reference($me, (int) $p['id'], $plans, $reports),
            'files' => $r ? Files::forRecord('performance', (int) $r['id']) : array(),
            'self_approves' => self::selfApproves($req->user),
            'editable' => !$r || in_array((string) $r['status'], self::EDITABLE, true),
        );
    }

    private static function snapshot(array $r)
    {
        return json_encode(array('progress' => self::num($r['progress']), 'done' => str_or_empty($r['done_content']), 'problems' => str_or_empty($r['problems']),
            'next' => str_or_empty($r['next_steps']), 'status' => (string) $r['status'], 'comment' => str_or_empty($r['review_comment']), 'score' => self::num($r['score'])), JSON_UNESCAPED_UNICODE);
    }

    private static function addVersion($reportId, $kind, $uid)
    {
        $r = DB::table('performance_reports')->find((int) $reportId);
        $no = (int) DB::value('SELECT COALESCE(MAX(version_no), 0) + 1 FROM performance_versions WHERE report_id = ?', array((int) $reportId));
        DB::table('performance_versions')->insert(array('report_id' => (int) $reportId, 'version_no' => $no, 'kind' => $kind, 'snapshot' => self::snapshot($r),
            'created_by' => $uid, 'created_at' => now()));
    }

    private static function validate(array $in, $submit, $reviewer = false)
    {
        foreach (array('progress', 'score') as $k) {
            if (isset($in[$k]) && (is_int($in[$k]) || is_float($in[$k]))) {
                $in[$k] = (string) $in[$k];
            }
        }
        $pct = array('nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/');
        $rules = array(
            'progress' => $submit ? array('required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/') : $pct,
            'done' => ($submit ? 'required' : 'nullable') . '|string|max:5000',
            'problems' => 'nullable|string|max:2000', 'next' => 'nullable|string|max:2000', 'version' => 'nullable|int',
        );
        if ($reviewer) {
            $rules['score'] = $pct;
            $rules['comment'] = 'nullable|string|max:500';
        }
        $v = Validator::check($in, $rules, array('progress' => 'perf.field.progress', 'done' => 'perf.field.done', 'problems' => 'perf.field.problems',
            'next' => 'perf.field.next', 'score' => 'perf.field.score', 'comment' => 'perf.field.comment'));
        foreach (array('progress', 'score') as $k) {
            if (isset($v[$k]) && $v[$k] !== null && $v[$k] !== '' && (float) $v[$k] > 100) {
                throw ValidationException::field($k, 'validation.max_num', array('field' => __('perf.field.' . $k), 'max' => 100));
            }
        }
        return $v;
    }

    private static function val(array $v, $k)
    {
        return isset($v[$k]) && $v[$k] !== '' ? $v[$k] : null;
    }

    /** Save (draft) or submit my result on a plan. */
    public static function save(Request $req, $planId, array $in)
    {
        list($p, , $me) = self::writable($req, $planId);
        $submit = !empty($in['submit']);
        $v = self::validate($in, $submit);
        $r = DB::table('performance_reports')->where('plan_id', (int) $p['id'])->where('person_id', $me)->first();
        if ($r && !in_array((string) $r['status'], self::EDITABLE, true)) {
            throw HttpException::conflict('perf.err.locked');
        }
        if ($r && isset($v['version']) && $v['version'] !== null && (int) $v['version'] !== (int) $r['version']) {
            throw HttpException::conflict('error.stale');
        }
        $uid = $req->userId();
        $auto = $submit && self::selfApproves($req->user);
        $data = array('progress' => self::val($v, 'progress'), 'done_content' => self::val($v, 'done'), 'problems' => self::val($v, 'problems'),
            'next_steps' => self::val($v, 'next'), 'updated_at' => now(), 'updated_by' => $uid, 'status' => 'draft');
        if ($submit) {
            $data['status'] = 'submitted';
            $data['submitted_at'] = now();
            $data['submitted_by'] = $uid;
            $data['review_comment'] = null;
            $data['reviewed_at'] = null;
            $data['reviewed_by'] = null;
            $data['score'] = null;
            $data['reviewer_edited'] = 0;
        }
        if ($auto) {
            $data = array_merge($data, array('status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $uid, 'appr_progress' => $data['progress'],
                'appr_content' => $data['done_content'], 'appr_problems' => $data['problems'], 'appr_next' => $data['next_steps'], 'appr_at' => now()));
        }
        $id = DB::transaction(function () use ($r, $p, $me, $data, $uid, $submit, $auto) {
            if ($r) {
                if (DB::table('performance_reports')->where('id', (int) $r['id'])->where('version', (int) $r['version'])->increment('version', 1, $data) === 0) {
                    throw HttpException::conflict('error.stale');
                }
                $id = (int) $r['id'];
            } else {
                $id = DB::table('performance_reports')->insert(array_merge($data, array('org_id' => (int) config('app.org_id', 1), 'plan_id' => (int) $p['id'],
                    'person_id' => $me, 'created_at' => now(), 'created_by' => $uid)));
            }
            self::addVersion($id, $auto ? 'self_approve' : ($submit ? 'submit' : 'save'), $uid);
            return $id;
        });
        $action = $auto ? 'perf_self_approved' : ($submit ? 'perf_submitted' : 'perf_saved');
        Audit::op($req->user, 'perf', $action, 'performance', $id, (string) $p['title'], array('progress' => $data['progress']), $req->ip());
        if ($submit && !$auto) {
            $person = DB::table('personnel_profiles')->find($me);
            Notify::send(Notify::reviewersOf('perf.audit', $person), 'perf_submitted', array('name' => (string) $person['name'], 'title' => (string) $p['title']),
                '/performance/review?focus=' . $id, 'performance', $id, $uid);
        }
        return array('id' => $id, 'status' => $data['status']);
    }

    public static function withdraw(Request $req, $id)
    {
        $r = DB::table('performance_reports')->find((int) $id);
        if (!$r || !self::isMe($req->user, $r['person_id'])) {
            throw HttpException::notFound();
        }
        if ((string) $r['status'] !== 'submitted') {
            throw HttpException::conflict((string) $r['status'] === 'approved' ? 'perf.err.withdraw_approved' : 'perf.err.not_pending');
        }
        $uid = $req->userId();
        DB::transaction(function () use ($r, $uid) {
            if (DB::table('performance_reports')->where('id', (int) $r['id'])->where('status', 'submitted')->increment('version', 1, array('status' => 'withdrawn', 'updated_at' => now(), 'updated_by' => $uid)) === 0) {
                throw HttpException::conflict('error.stale');
            }
            self::addVersion((int) $r['id'], 'withdraw', $uid);
        });
        Audit::op($req->user, 'perf', 'perf_withdrawn', 'performance', (int) $r['id'], self::planTitle((int) $r['plan_id']), null, $req->ip());
        Notify::retract('performance', (int) $r['id'], array('perf_submitted', 'remind_review'));
        return array('ok' => true);
    }

    private static function planTitle($planId)
    {
        return (string) DB::value('SELECT title FROM plans WHERE id = ?', array((int) $planId));
    }

    /** Attachments: upload on my result (a draft is created when there is none yet). */
    public static function upload(Request $req, $planId)
    {
        list($p, $plans, $me) = self::writable($req, $planId);
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        $r = DB::table('performance_reports')->where('plan_id', (int) $p['id'])->where('person_id', $me)->first();
        if ($r && (string) $r['status'] === 'submitted') {
            throw HttpException::conflict('perf.err.locked');
        }
        if (!$r) {
            $rid = DB::table('performance_reports')->insert(array('org_id' => (int) config('app.org_id', 1), 'plan_id' => (int) $p['id'], 'person_id' => $me, 'status' => 'draft',
                'created_at' => now(), 'created_by' => $req->userId()));
        } else {
            $rid = (int) $r['id'];
        }
        $folders = array(Files::folder('file.folder.plans'));
        $chain = array();
        $q = $p;
        while ($q !== null) {
            array_unshift($chain, (string) $q['title']);
            $q = $q['parent_id'] !== null && isset($plans[(int) $q['parent_id']]) ? $plans[(int) $q['parent_id']] : null;
        }
        $folders = array_merge($folders, $chain);
        $folders[] = (string) DB::value('SELECT name FROM personnel_profiles WHERE id = ?', array($me));
        $out = array();
        foreach ($list as $up) {
            $out[] = Files::store($req, $up, 'performance', $rid, 'evidence', $folders, $me);
        }
        return array('report_id' => $rid, 'files' => Files::forRecord('performance', $rid), 'stored' => $out);
    }

    /** Access check for attachments (download: anyone who may read the result; delete: own, not while submitted or approved). */
    public static function fileAccess(Request $req, $reportId, $write)
    {
        $r = DB::table('performance_reports')->find((int) $reportId);
        if (!$r) {
            throw HttpException::notFound();
        }
        $own = self::isMe($req->user, $r['person_id']);
        if ($write) {
            if (!$own) {
                throw HttpException::forbidden();
            }
            if (in_array((string) $r['status'], array('submitted', 'approved'), true)) {
                throw HttpException::conflict('perf.err.file_locked');
            }
            return;
        }
        PlanService::find($req, (int) $r['plan_id']);
        $people = self::personRows(array((int) $r['person_id']));
        if (!$own && (!isset($people[(int) $r['person_id']]) || !self::maySee($req->user, $people[(int) $r['person_id']]) || self::shown($r, false) === null)) {
            Audit::denied($req, 'performance', array('id' => (int) $r['id']));
            throw HttpException::notFound();
        }
    }

    /** Results on one plan, per assignee (plan details, "view results"). */
    public static function forPlan(Request $req, $planId)
    {
        list($p, $plans) = PlanService::find($req, $planId);
        $reports = self::byPlans(array((int) $p['id']));
        $people = self::personRows($p['assignees']);
        $out = array();
        foreach ($p['assignees'] as $pid) {
            $r = isset($reports[(int) $p['id']][$pid]) ? $reports[(int) $p['id']][$pid] : null;
            $own = self::isMe($req->user, $pid);
            $row = array('person_id' => $pid, 'person' => isset($people[$pid]) ? $people[$pid]['name'] : '', 'status' => $r ? (string) $r['status'] : 'none', 'own' => $own,
                'shown' => null, 'files' => array(), 'submitted_at' => '', 'reviewed_at' => '', 'comment' => '', 'score' => null, 'edited' => false, 'id' => $r ? (int) $r['id'] : null);
            if ($r && isset($people[$pid]) && self::maySee($req->user, $people[$pid])) {
                $row['shown'] = self::shown($r, $own);
                if ($row['shown'] !== null) {
                    $row['files'] = Files::forRecord('performance', (int) $r['id']);
                    $row['submitted_at'] = str_or_empty($r['submitted_at']);
                    $row['reviewed_at'] = str_or_empty($r['reviewed_at']);
                    $row['comment'] = str_or_empty($r['review_comment']);
                    $row['score'] = self::num($r['score']);
                    $row['edited'] = (int) $r['reviewer_edited'] === 1;
                }
            }
            $out[] = $row;
        }
        $prog = self::progressMap($plans, self::byPlans(array_keys($plans)));
        return array('plan' => self::brief($p), 'progress' => isset($prog[(int) $p['id']]) ? $prog[(int) $p['id']] : 0.0, 'rows' => $out);
    }

    /** History of one result (own, or a reviewer of the person). */
    public static function history(Request $req, $id)
    {
        $r = DB::table('performance_reports')->find((int) $id);
        if (!$r) {
            throw HttpException::notFound();
        }
        if (!self::isMe($req->user, $r['person_id'])) {
            self::reviewTarget($req, (int) $id, self::STATUSES);
        }
        $rows = DB::table('performance_versions v')->leftJoin('users u', 'u.id', '=', 'v.created_by')->where('v.report_id', (int) $id)
            ->select(array('v.version_no', 'v.kind', 'v.snapshot', 'v.created_at', 'u.display_name as by_name'))->orderBy('v.version_no', 'DESC')->get();
        $out = array();
        foreach ($rows as $v) {
            $out[] = array('no' => (int) $v['version_no'], 'kind' => (string) $v['kind'], 'at' => (string) $v['created_at'], 'by' => str_or_empty($v['by_name']),
                'data' => json_decode((string) $v['snapshot'], true));
        }
        return array('rows' => $out);
    }

    // ---------- review ----------

    private static function reviewBase()
    {
        return DB::table('performance_reports r')->join('plans p', 'p.id', '=', 'r.plan_id')->join('personnel_profiles pp', 'pp.id', '=', 'r.person_id')
            ->leftJoin('departments d', 'd.id', '=', 'pp.dept_id')->leftJoin('users ru', 'ru.id', '=', 'r.reviewed_by')
            ->whereNull('p.deleted_at')->whereNull('pp.deleted_at')
            ->select(array('r.*', 'p.title as plan_title', 'p.level as plan_level', 'p.start_date as plan_start', 'p.end_date as plan_end',
                'p.time_label_mode as plan_tl_mode', 'p.time_label_text as plan_tl_text', 'p.parent_id as plan_parent', 'pp.name as person_name', 'd.name as dept_name', 'ru.display_name as reviewer_name'));
    }

    private static function scoped(Request $req)
    {
        $q = self::reviewBase();
        Permission::scopePersonnel($q, $req->user, 'pp');
        if ($req->user['person_id'] !== null) {
            $q->where('r.person_id', '<>', (int) $req->user['person_id']);
        }
        return $q;
    }

    private static function presentReview(array $r)
    {
        $out = self::present($r);
        $out['person'] = (string) $r['person_name'];
        $out['dept'] = str_or_empty($r['dept_name']);
        $out['reviewer'] = str_or_empty($r['reviewer_name']);
        $out['plan'] = array('id' => (int) $r['plan_id'], 'title' => (string) $r['plan_title'], 'level' => (string) $r['plan_level'], 'start' => (string) $r['plan_start'],
            'end' => (string) $r['plan_end'], 'tl_mode' => (string) $r['plan_tl_mode'], 'tl_text' => str_or_empty($r['plan_tl_text']), 'parent_id' => int_or_null($r['plan_parent']));
        $out['files'] = (int) DB::value('SELECT COUNT(*) FROM file_relations fr JOIN files f ON f.id = fr.file_id WHERE fr.biz_type = ? AND fr.biz_id = ? AND f.status = ?', array('performance', (int) $r['id'], 'normal'));
        return $out;
    }

    public static function pendingCount($user)
    {
        if (!Permission::can($user, 'perf.audit')) {
            return 0;
        }
        $q = DB::table('performance_reports r')->join('plans p', 'p.id', '=', 'r.plan_id')->join('personnel_profiles pp', 'pp.id', '=', 'r.person_id')
            ->whereNull('p.deleted_at')->whereNull('pp.deleted_at')->where('r.status', 'submitted');
        Permission::scopePersonnel($q, $user, 'pp');
        if ($user['person_id'] !== null) {
            $q->where('r.person_id', '<>', (int) $user['person_id']);
        }
        return $q->count();
    }

    public static function reviews(Request $req, $tab, $personId, $kw, $page, $size)
    {
        $q = self::scoped($req);
        if ($tab === 'done') {
            $q->whereIn('r.status', array('approved', 'returned'))->whereNotNull('r.reviewed_by');
        } else {
            $q->where('r.status', 'submitted');
        }
        if ($personId) {
            $q->where('r.person_id', (int) $personId);
        }
        if ($kw !== '') {
            $q->whereLikeAny(array('p.title', 'r.done_content', 'pp.name'), $kw);
        }
        $q->orderBy($tab === 'done' ? 'r.reviewed_at' : 'r.submitted_at', $tab === 'done' ? 'DESC' : 'ASC')->orderBy('r.id');
        $res = $q->paginate($page, $size);
        $res['rows'] = array_map(array(__CLASS__, 'presentReview'), $res['rows']);
        $res['pending'] = self::pendingCount($req->user);
        $people = DB::table('personnel_profiles p')->active()->where('p.status', 'active')->select(array('p.id', 'p.name'));
        Permission::scopePersonnel($people, $req->user, 'p');
        if ($req->user['person_id'] !== null) {
            $people->where('p.id', '<>', (int) $req->user['person_id']);
        }
        $res['people'] = array();
        foreach ($people->orderBy('p.name')->get() as $x) {
            $res['people'][] = array('id' => (int) $x['id'], 'name' => (string) $x['name']);
        }
        return $res;
    }

    /** A result the user may review, else 404 (logged). */
    private static function reviewTarget(Request $req, $id, array $statuses)
    {
        if (!Permission::can($req->user, 'perf.audit')) {
            throw HttpException::forbidden();
        }
        $r = self::scoped($req)->where('r.id', (int) $id)->first();
        if (!$r) {
            Audit::denied($req, 'performance', array('id' => (int) $id));
            throw HttpException::notFound();
        }
        if (!in_array((string) $r['status'], $statuses, true)) {
            throw HttpException::conflict('perf.err.not_pending');
        }
        return $r;
    }

    public static function reviewDetail(Request $req, $id)
    {
        $r = self::reviewTarget($req, $id, self::STATUSES);
        if ((string) $r['status'] === 'draft') {
            throw HttpException::notFound();
        }
        $out = self::presentReview($r);
        $out['files'] = Files::forRecord('performance', (int) $r['id']);
        Notify::markRead($req->userId(), 'performance', (int) $r['id']);
        return $out;
    }

    private static function ids(array $in)
    {
        $ids = array();
        foreach (isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : array() as $x) {
            if (is_int($x) || (is_string($x) && ctype_digit($x))) {
                $ids[(int) $x] = (int) $x;
            }
        }
        if (!$ids) {
            throw HttpException::bad('perf.err.none_selected');
        }
        return array_values($ids);
    }

    private static function approveRow(array $r, array $extra, $uid)
    {
        $data = array_merge(array('status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $uid, 'updated_at' => now(), 'updated_by' => $uid), $extra);
        $prog = array_key_exists('progress', $data) ? $data['progress'] : $r['progress'];
        $done = array_key_exists('done_content', $data) ? $data['done_content'] : $r['done_content'];
        $prob = array_key_exists('problems', $data) ? $data['problems'] : $r['problems'];
        $next = array_key_exists('next_steps', $data) ? $data['next_steps'] : $r['next_steps'];
        $data = array_merge($data, array('appr_progress' => $prog, 'appr_content' => $done, 'appr_problems' => $prob, 'appr_next' => $next, 'appr_at' => now()));
        if (DB::table('performance_reports')->where('id', (int) $r['id'])->where('status', 'submitted')->where('version', (int) $r['version'])->increment('version', 1, $data) === 0) {
            throw HttpException::conflict('error.stale');
        }
    }

    /** Tell the person about the review result; the reviewers' messages about it are done. */
    private static function tellPerson(Request $req, array $r, $type, array $extra = array())
    {
        $uid = Notify::userOfPerson((int) $r['person_id']);
        Notify::markRead($req->userId(), 'performance', (int) $r['id']);
        Notify::retract('performance', (int) $r['id'], array('perf_submitted', 'remind_review'));
        if ($uid !== null) {
            Notify::send(array($uid), $type, array_merge(array('by' => $req->user['name'], 'title' => (string) $r['plan_title']), $extra),
                '/plans/mine?plan=' . (int) $r['plan_id'], 'performance', (int) $r['id'], $req->userId(), $type === 'perf_returned' ? 'warn' : 'info');
        }
    }

    /** Approve several submitted results at once. */
    public static function approve(Request $req, array $in)
    {
        $ids = self::ids($in);
        $rows = array();
        foreach ($ids as $id) {
            $rows[] = self::reviewTarget($req, $id, array('submitted'));
        }
        $uid = $req->userId();
        DB::transaction(function () use ($rows, $uid) {
            foreach ($rows as $r) {
                self::approveRow($r, array('review_comment' => null, 'score' => null, 'reviewer_edited' => 0), $uid);
                self::addVersion((int) $r['id'], 'approve', $uid);
            }
        });
        foreach ($rows as $r) {
            Audit::change($req, 'perf', 'perf_approved', 'performance', (int) $r['id'], $r['person_name'] . ' · ' . $r['plan_title'], array('status' => 'submitted'), array('status' => 'approved'));
            self::tellPerson($req, $r, 'perf_approved');
        }
        return array('approved' => count($rows));
    }

    /** Edit the submitted result and approve it; the submitted text stays in the history. */
    public static function editApprove(Request $req, $id, array $in)
    {
        $r = self::reviewTarget($req, $id, array('submitted'));
        $v = self::validate($in, true, true);
        if (isset($v['version']) && $v['version'] !== null && (int) $v['version'] !== (int) $r['version']) {
            throw HttpException::conflict('error.stale');
        }
        $before = array('progress' => (string) self::num($r['progress']), 'done' => str_or_empty($r['done_content']), 'problems' => str_or_empty($r['problems']), 'next' => str_or_empty($r['next_steps']));
        $after = array('progress' => (string) self::num($v['progress']), 'done' => (string) $v['done'], 'problems' => (string) self::val($v, 'problems'), 'next' => (string) self::val($v, 'next'));
        list($b, $a) = Audit::diff($before, $after);
        $uid = $req->userId();
        DB::transaction(function () use ($r, $v, $a, $uid) {
            self::approveRow($r, array('progress' => self::val($v, 'progress'), 'done_content' => self::val($v, 'done'), 'problems' => self::val($v, 'problems'),
                'next_steps' => self::val($v, 'next'), 'score' => self::val($v, 'score'), 'review_comment' => self::val($v, 'comment'), 'reviewer_edited' => $a ? 1 : 0), $uid);
            self::addVersion((int) $r['id'], $a ? 'edit_approve' : 'approve', $uid);
        });
        $edited = (bool) $a;
        $b['status'] = 'submitted';
        $a['status'] = 'approved';
        if (self::val($v, 'score') !== null) {
            $a['score'] = self::val($v, 'score');
        }
        Audit::change($req, 'perf', $edited ? 'perf_edit_approved' : 'perf_approved', 'performance', (int) $r['id'], $r['person_name'] . ' · ' . $r['plan_title'], $b, $a);
        self::tellPerson($req, $r, $edited ? 'perf_edit_approved' : 'perf_approved');
        return array('ok' => true);
    }

    /** Return submitted (or approved) results with a reason; an approved result loses its approval. */
    public static function returnMany(Request $req, array $in)
    {
        $ids = self::ids($in);
        $v = Validator::check($in, array('reason' => 'required|string|max:500'), array('reason' => 'perf.field.reason'));
        $rows = array();
        foreach ($ids as $id) {
            $rows[] = self::reviewTarget($req, $id, array('submitted', 'approved'));
        }
        $uid = $req->userId();
        DB::transaction(function () use ($rows, $v, $uid) {
            foreach ($rows as $r) {
                $data = array('status' => 'returned', 'review_comment' => $v['reason'], 'reviewed_at' => now(), 'reviewed_by' => $uid, 'updated_at' => now(), 'updated_by' => $uid);
                if ((string) $r['status'] === 'approved') {
                    $data = array_merge($data, array('appr_progress' => null, 'appr_content' => null, 'appr_problems' => null, 'appr_next' => null, 'appr_at' => null));
                }
                if (DB::table('performance_reports')->where('id', (int) $r['id'])->where('version', (int) $r['version'])->increment('version', 1, $data) === 0) {
                    throw HttpException::conflict('error.stale');
                }
                self::addVersion((int) $r['id'], 'return', $uid);
            }
        });
        foreach ($rows as $r) {
            Audit::change($req, 'perf', 'perf_returned', 'performance', (int) $r['id'], $r['person_name'] . ' · ' . $r['plan_title'], array('status' => (string) $r['status']),
                array('status' => 'returned', 'reason' => $v['reason']));
            self::tellPerson($req, $r, 'perf_returned', array('reason' => (string) $v['reason']));
        }
        return array('returned' => count($rows));
    }
}
