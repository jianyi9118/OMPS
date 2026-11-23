<?php
namespace Omps\Modules\Plan\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Plan\Services\PlanService;

class PlanController extends Controller
{
    public function minePage(Request $req)
    {
        // members land here instead of a workbench, so 报修 is offered here too
        $scripts = class_exists('Omps\\Modules\\Device\\Services\\RepairService') ? array('device', 'plan', 'perf', 'plans') : array('plan', 'perf', 'plans');
        return $this->renderPage($req, 'Plan::plans', 'menu.plan_mine', array('mode' => 'mine'), $scripts);
    }

    public function treePage(Request $req)
    {
        return $this->renderPage($req, 'Plan::plans', 'menu.plan_tree', array('mode' => 'tree'), array('plan', 'perf', 'plans'));
    }

    public function meta(Request $req)
    {
        return PlanService::meta($req);
    }

    public function index(Request $req)
    {
        $mode = $req->str('mode') === 'tree' ? 'tree' : 'mine';
        return PlanService::tree($req, $mode, array('per' => $req->str('per'), 'dept_id' => $req->int('dept_id'), 'person_id' => $req->int('person_id'), 'kw' => $req->str('kw')));
    }

    public function show(Request $req)
    {
        return PlanService::detail($req, $this->idParam($req));
    }

    public function store(Request $req)
    {
        return PlanService::create($req, $req->body);
    }

    public function update(Request $req)
    {
        return PlanService::update($req, $this->idParam($req), $req->body);
    }

    public function deleteImpact(Request $req)
    {
        return PlanService::deleteImpact($req, $this->idParam($req));
    }

    public function destroy(Request $req)
    {
        return PlanService::delete($req, $this->idParam($req));
    }
}
