<?php
namespace Omps\Modules\Personnel\Controllers;

use Omps\Core\Controller;
use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;
use Omps\Engines\Permission;
use Omps\Modules\Organization\Services\StaffService;
use Omps\Modules\Personnel\Services\ChangeRequests;
use Omps\Modules\Personnel\Services\FamilyService;
use Omps\Modules\Personnel\Services\Fields;
use Omps\Modules\Personnel\Services\ImportExport;
use Omps\Modules\Personnel\Services\PersonnelService;

class PersonnelController extends Controller
{
    const FILE_GROUPS = array('photo', 'education', 'title', 'achievement', 'other');

    public function meta(Request $req)
    {
        $caps = StaffService::caps($req->user);
        return array_merge(Fields::meta(), array(
            'canImport' => $caps['add'], 'canExport' => Permission::can($req->user, 'staff.export'),
            'canReview' => Permission::can($req->user, 'staff.audit'), 'pending' => ChangeRequests::pendingCount($req),
            'maxUploadMb' => (int) config('app.upload_max_mb', 50),
        ));
    }

    public function show(Request $req)
    {
        return PersonnelService::get($req, $this->idParam($req));
    }

    public function update(Request $req)
    {
        return PersonnelService::saveAdmin($req, $this->idParam($req), $req->body);
    }

    public function revealId(Request $req)
    {
        return PersonnelService::revealId($req, $this->idParam($req));
    }

    public function history(Request $req)
    {
        return PersonnelService::history($req, $this->idParam($req));
    }

    /** Upload attachments (or the photo). Administrators of the record, or the person themself. */
    public function upload(Request $req)
    {
        $id = $this->idParam($req);
        list($p, $ctx) = PersonnelService::access($req, $id);
        if (!$ctx['admin_edit'] && !$ctx['own']) {
            throw HttpException::forbidden();
        }
        $group = $req->str('group');
        if (!in_array($group, self::FILE_GROUPS, true)) {
            throw HttpException::bad('error.bad_request');
        }
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        $row = DB::table('personnel_profiles')->find($p['id']);
        $folders = array(Files::folder('file.folder.personnel'));
        if ($row['dept_id'] !== null) {
            $chain = array();
            $d = DB::table('departments')->find((int) $row['dept_id']);
            foreach (array_filter(explode('/', trim((string) $d['path'], '/'))) as $pid) {
                $chain[] = (string) DB::value('SELECT name FROM departments WHERE id = ?', array((int) $pid));
            }
            $chain[] = (string) $d['name'];
            $folders = array_merge($folders, $chain);
        }
        $folders[] = $row['name'];
        $folders[] = Files::folder('file.group.' . $group);
        $out = array();
        foreach ($group === 'photo' ? array_slice($list, 0, 1) : $list as $up) {
            $r = Files::store($req, $up, 'personnel', (int) $p['id'], $group, $folders, (int) $p['id'], $group === 'photo');
            $out[] = $r;
            if ($group === 'photo') {
                $old = int_or_null($row['photo_file_id']);
                DB::table('personnel_profiles')->where('id', (int) $p['id'])->update(array('photo_file_id' => $r['file_id'], 'updated_at' => now(), 'updated_by' => $req->userId()));
                Audit::change($req, 'staff', 'photo_changed', 'person', $p['id'], $row['name'] . ' (' . $row['staff_no'] . ')', array('photo_file_id' => $old), array('photo_file_id' => $r['file_id']));
            }
        }
        return array('files' => $out, 'duplicates' => count(array_filter($out, function ($x) { return $x['duplicate']; })));
    }

    /** Download or show a file; the business record decides who may see it. */
    public function download(Request $req)
    {
        $rel = Files::relation($this->idParam($req));
        $this->checkRecordAccess($req, $rel);
        return Files::download($req, $rel, $req->int('inline') === 1);
    }

    public function deleteFile(Request $req)
    {
        $rel = Files::relation($this->idParam($req));
        $this->checkRecordAccess($req, $rel, true);
        Files::detach($req, $rel);
        if ((string) $rel['biz_type'] === 'personnel' && (string) $rel['biz_group'] === 'photo') {
            DB::table('personnel_profiles')->where('id', (int) $rel['biz_id'])->where('photo_file_id', (int) $rel['file_id'])->update(array('photo_file_id' => null));
        }
        return array('ok' => true);
    }

    /** Each business record type decides who may read or remove its attachments. */
    private function checkRecordAccess(Request $req, array $rel, $write = false)
    {
        $type = (string) $rel['biz_type'];
        if ($type === 'personnel') {
            PersonnelService::access($req, (int) $rel['biz_id']);
            return;
        }
        if ($type === 'performance' && class_exists('Omps\Modules\Performance\Services\PerformanceService')) {
            \Omps\Modules\Performance\Services\PerformanceService::fileAccess($req, (int) $rel['biz_id'], $write);
            return;
        }
        if ($type === 'report' && class_exists('Omps\Modules\Report\Services\ReportService')) {
            \Omps\Modules\Report\Services\ReportService::fileAccess($req, (int) $rel['biz_id'], $write);
            return;
        }
        if ($type === 'device' && class_exists('Omps\Modules\Device\Services\DeviceService')) {
            \Omps\Modules\Device\Services\DeviceService::fileAccess($req, (int) $rel['biz_id'], $write);
            return;
        }
        if ($type === 'device_fault' && class_exists('Omps\Modules\Device\Services\RepairService')) {
            \Omps\Modules\Device\Services\RepairService::fileAccess($req, (int) $rel['biz_id'], $write);
            return;
        }
        if (($type === 'issue' || $type === 'issue_reply') && class_exists('Omps\Modules\Affairs\Services\IssueService')) {
            \Omps\Modules\Affairs\Services\IssueService::fileAccess($req, $type, (int) $rel['biz_id'], $write);
            return;
        }
        throw HttpException::notFound();
    }

    // ---------- own profile ----------
    public function profilePage(Request $req)
    {
        return $this->renderPage($req, 'Personnel::profile', 'menu.profile', array(), array('person', 'profile'));
    }

    public function myProfile(Request $req)
    {
        if ($req->user['person_id'] === null) {
            return array('none' => true);
        }
        return PersonnelService::get($req, (int) $req->user['person_id']);
    }

    /** Registration form (Word); ?tpl= template id, 0 = built-in, absent = the default. */
    public function document(Request $req)
    {
        return PersonnelService::document($req, $this->idParam($req), self::tpl($req));
    }

    public function myDocument(Request $req)
    {
        if ($req->user['person_id'] === null) {
            throw \Omps\Core\HttpException::notFound();
        }
        return PersonnelService::document($req, (int) $req->user['person_id'], self::tpl($req));
    }

    private static function tpl(Request $req)
    {
        return $req->str('tpl') === '' ? null : $req->int('tpl');
    }

    public function saveMyProfile(Request $req)
    {
        return PersonnelService::saveSelf($req, $req->body);
    }

    public function myRequests(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return ChangeRequests::mine($req, $this->filterArgs($req), $page, $size);
    }

    public function withdraw(Request $req)
    {
        ChangeRequests::withdraw($req, $this->idParam($req));
        return array('ok' => true);
    }

    // ---------- review ----------
    public function reviews(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return ChangeRequests::reviewable($req, $req->str('status', 'pending'), $this->filterArgs($req), $page, $size);
    }

    public function approve(Request $req)
    {
        $ids = $req->input('ids', array());
        return ChangeRequests::approve($req, is_array($ids) ? array_map('intval', $ids) : array());
    }

    public function returnRequest(Request $req)
    {
        ChangeRequests::returnRequest($req, $this->idParam($req), $req->str('comment'));
        return array('ok' => true);
    }

    // ---------- family ----------
    public function familyPage(Request $req)
    {
        return $this->renderPage($req, 'Personnel::family', 'menu.family', array(), array('person', 'family'));
    }

    public function family(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return FamilyService::search($req, array_merge($this->filterArgs($req), array('dept_id' => $req->int('dept_id'))), $page, $size);
    }

    // ---------- import / export ----------
    public function template(Request $req)
    {
        return ImportExport::template($req);
    }

    public function importCheck(Request $req)
    {
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        list($token, $path, $ext) = ImportExport::stash($list[0]);
        $res = ImportExport::check($req, $path, $ext);
        foreach ($res['rows'] as &$r) {
            unset($r['clean']);
        }
        unset($r);
        $res['token'] = $token;
        return $res;
    }

    public function importRun(Request $req)
    {
        return ImportExport::import($req, $req->str('token'));
    }

    public function export(Request $req)
    {
        return ImportExport::export($req, array_merge($this->filterArgs($req), array('dept_id' => $req->int('dept_id'), 'status' => $req->str('status'))));
    }
}
