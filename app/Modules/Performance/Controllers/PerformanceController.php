<?php
namespace Omps\Modules\Performance\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Performance\Services\PerformanceService;

class PerformanceController extends Controller
{
    public function reviewPage(Request $req)
    {
        return $this->renderPage($req, 'Performance::review', 'menu.perf_review', array(), array('plan', 'perf', 'review'));
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
        return PerformanceService::reviews($req, $tab, $req->int('person_id'), $req->str('kw'), max(1, $req->int('page', 1)), 20);
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
}
