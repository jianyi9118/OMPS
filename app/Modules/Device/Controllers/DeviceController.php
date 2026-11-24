<?php
namespace Omps\Modules\Device\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Modules\Device\Services\CollectorService;
use Omps\Modules\Device\Services\DeviceImport;
use Omps\Modules\Device\Services\DeviceService;
use Omps\Modules\Device\Services\RepairService;

class DeviceController extends Controller
{
    // ---------- pages ----------
    public function archivePage(Request $req)
    {
        return $this->renderPage($req, 'Device::page', 'menu.dev_archive', array(), array('device', 'devices'));
    }

    public function monitorPage(Request $req)
    {
        return $this->renderPage($req, 'Device::page', 'menu.dev_monitor', array(), array('device', 'monitor'));
    }

    public function repairPage(Request $req)
    {
        return $this->renderPage($req, 'Device::page', 'menu.dev_repair', array(), array('device', 'repair'));
    }

    public function legacy(Request $req)
    {
        return Response::redirect(url('/device/archive'));
    }

    // ---------- records ----------
    public function meta(Request $req)
    {
        return DeviceService::meta($req);
    }

    private function filters(Request $req)
    {
        return array_merge($this->filterArgs($req), array('category' => $req->str('category'), 'status' => $req->str('status'), 'dept_id' => $req->int('dept_id')));
    }

    public function index(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return DeviceService::search($req, $this->filters($req), $page, $size);
    }

    public function show(Request $req)
    {
        return DeviceService::detail($req, $this->idParam($req));
    }

    public function store(Request $req)
    {
        return DeviceService::create($req, $req->body);
    }

    public function update(Request $req)
    {
        return DeviceService::update($req, $this->idParam($req), $req->body);
    }

    public function destroy(Request $req)
    {
        return DeviceService::delete($req, $this->idParam($req));
    }

    public function holder(Request $req)
    {
        return DeviceService::holder($req, $this->idParam($req), $req->body);
    }

    public function addPart(Request $req)
    {
        return DeviceService::addPart($req, $this->idParam($req), $req->body);
    }

    public function replacePart(Request $req)
    {
        return DeviceService::replacePart($req, $this->idParam($req), $this->idParam($req, 'cid'), $req->body);
    }

    public function removePart(Request $req)
    {
        return DeviceService::dropPart($req, $this->idParam($req), $this->idParam($req, 'cid'), $req->body);
    }

    public function addLicense(Request $req)
    {
        return DeviceService::addLicense($req, $this->idParam($req), $req->body);
    }

    public function updateLicense(Request $req)
    {
        return DeviceService::updateLicense($req, $this->idParam($req), $req->body);
    }

    public function deleteLicense(Request $req)
    {
        return DeviceService::deleteLicense($req, $this->idParam($req));
    }

    public function upload(Request $req)
    {
        return DeviceService::upload($req, $this->idParam($req));
    }

    // ---------- import / export ----------
    public function template(Request $req)
    {
        return DeviceImport::template($req);
    }

    public function importCheck(Request $req)
    {
        return DeviceImport::upload($req);
    }

    public function importRun(Request $req)
    {
        return DeviceImport::run($req, $req->str('token'));
    }

    public function export(Request $req)
    {
        return DeviceImport::export($req, $this->filters($req));
    }

    // ---------- faults and repairs ----------
    public function faultOptions(Request $req)
    {
        return RepairService::options($req, $req->str('kw'));
    }

    public function report(Request $req)
    {
        return RepairService::report($req, $req->body);
    }

    public function faults(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return RepairService::index($req, $req->str('status'), $this->filterArgs($req), $page, $size);
    }

    public function fault(Request $req)
    {
        return RepairService::show($req, $this->idParam($req));
    }

    public function assign(Request $req)
    {
        return RepairService::assign($req, $this->idParam($req), $req->body);
    }

    public function finish(Request $req)
    {
        return RepairService::finish($req, $this->idParam($req), $req->body);
    }

    public function cancel(Request $req)
    {
        return RepairService::cancel($req, $this->idParam($req), $req->body);
    }

    public function repairs(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return RepairService::repairs($req, $this->filterArgs($req), $page, $size);
    }

    // ---------- monitoring ----------
    public function usage(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return CollectorService::usage($req, $this->filterArgs($req), $req->int('device_id'), $page, $size);
    }

    public function anomalies(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return CollectorService::anomalies($req, $req->str('status'), $this->filterArgs($req), $page, $size);
    }

    public function confirm(Request $req)
    {
        return CollectorService::confirm($req, $this->idParam($req), $req->body);
    }

    public function ignore(Request $req)
    {
        return CollectorService::ignore($req, $this->idParam($req), $req->body);
    }

    public function collectors(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return CollectorService::index($req, $req->str('status'), $this->filterArgs($req), $page, $size);
    }

    public function enroll(Request $req)
    {
        $r = CollectorService::enroll($req, $this->idParam($req));
        $r['server'] = rtrim((string) (($req->isSecure() ? 'https://' : 'http://') . $req->header('host', 'localhost')), '/') . \Omps\Core\Url::basePath();
        return $r;
    }

    public function revoke(Request $req)
    {
        return CollectorService::revoke($req, $this->idParam($req));
    }

    public function scope(Request $req)
    {
        return array('items' => CollectorService::scope(), 'all' => CollectorService::ITEMS, 'files' => CollectorService::CLIENT_FILES);
    }

    public function saveScope(Request $req)
    {
        return CollectorService::setScope($req, $req->body);
    }

    public function clientFile(Request $req)
    {
        return CollectorService::clientFile($req, (string) $req->param('name', ''));
    }

    // ---------- client API ----------
    public function register(Request $req)
    {
        return CollectorService::register($req);
    }

    public function collect(Request $req)
    {
        return CollectorService::report($req);
    }
}
