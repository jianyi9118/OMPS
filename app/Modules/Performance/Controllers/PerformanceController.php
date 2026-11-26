<?php
namespace Omps\Modules\Performance\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Performance\Services\PerfStatsService;
use Omps\Modules\Performance\Services\PerformanceService;

class PerformanceController extends Controller
{
    public function reviewPage(Request $req)
    {
        return $this->renderPage($req, 'Performance::review', 'menu.perf_review', array(), array('plan', 'perf', 'perfstats', 'review'));
    }

    public function mine(Request $req)
    {
        return PerformanceService::mine($req, $this->idParam($req));
    }

    public function save(Request $req)
    {
        return PerformanceService::save($req, $this->idParam($req), $req->body);
    }

    public function upload(Request $req)
    {
        return PerformanceService::upload($req, $this->idParam($req));
    }

    public function forPlan(Request $req)
    {
        return PerformanceService::forPlan($req, $this->idParam($req));
    }

    public function withdraw(Request $req)
    {
        return PerformanceService::withdraw($req, $this->idParam($req));
    }

    public function history(Request $req)
    {
        return PerformanceService::history($req, $this->idParam($req));
    }

    public function reviews(Request $req)
    {
        $tab = $req->str('tab') === 'done' ? 'done' : 'pending';
        list($page, $size) = $this->pageArgs($req);
        return PerformanceService::reviews($req, $tab, $req->int('person_id'), $this->filterArgs($req), $page, $size);
    }

    public function reviewDetail(Request $req)
    {
        return PerformanceService::reviewDetail($req, $this->idParam($req));
    }

    public function approve(Request $req)
    {
        return PerformanceService::approve($req, $req->body);
    }

    public function editApprove(Request $req)
    {
        return PerformanceService::editApprove($req, $this->idParam($req), $req->body);
    }

    public function returnMany(Request $req)
    {
        return PerformanceService::returnMany($req, $req->body);
    }

    /** Statistics filters from the query string. */
    private function statArgs(Request $req)
    {
        $f = $this->filterArgs($req);
        return array('from' => $f['from'], 'to' => $f['to'], 'kw' => $f['kw'], 'dept_id' => $req->int('dept_id'), 'person_id' => $req->int('person_id'), 'gaps' => $req->int('gaps') === 1);
    }

    public function stats(Request $req)
    {
        return PerfStatsService::summary($req, $this->statArgs($req));
    }

    public function statItems(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return PerfStatsService::items($req, $this->statArgs($req), $req->str('kind'), $req->str('state'), $page, $size);
    }

    public function statExport(Request $req)
    {
        return PerfStatsService::export($req, $this->statArgs($req));
    }
}
