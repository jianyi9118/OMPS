<?php
namespace Omps\Modules\Report\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Report\Services\ReportService;

class ReportController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'Report::reports', 'menu.reports', array(), array('plan', 'reports'));
    }

    public function meta(Request $req)
    {
        return ReportService::meta($req);
    }

    public function index(Request $req)
    {
        return ReportService::index($req, array('per' => $req->str('per'), 'start' => $req->str('start'), 'end' => $req->str('end'), 'kw' => $req->str('kw')));
    }

    public function generate(Request $req)
    {
        return ReportService::generate($req, $req->body);
    }

    public function createManual(Request $req)
    {
        return ReportService::createManual($req, $req->body);
    }

    public function show(Request $req)
    {
        return ReportService::show($req, $this->idParam($req), $req->int('v') ?: null);
    }

    public function update(Request $req)
    {
        return ReportService::update($req, $this->idParam($req), $req->body);
    }

    public function destroy(Request $req)
    {
        return ReportService::delete($req, $this->idParam($req));
    }

    public function upload(Request $req)
    {
        return ReportService::upload($req, $this->idParam($req));
    }

    public function printed(Request $req)
    {
        return ReportService::printed($req, $this->idParam($req), $req->int('v') ?: null);
    }

    public function download(Request $req)
    {
        return ReportService::download($req, $this->idParam($req), $req->int('v') ?: null, $req->str('format') === 'xlsx' ? 'xlsx' : 'docx', $req->str('tpl'));
    }
}
