<?php
namespace Omps\Modules\Organization\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Organization\Services\DeptService;
use Omps\Modules\Organization\Services\StaffService;

class StaffController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'Organization::staff', 'menu.staff', array(), array('person', 'staff'));
    }

    public function meta(Request $req)
    {
        return array('caps' => StaffService::caps($req->user), 'depts' => DeptService::options($req->user), 'allDepts' => DeptService::options(array_merge($req->user, array('role_scope' => 'unit'))),
            'custom' => \Omps\Engines\CustomFields::defs('personnel'));
    }

    public function index(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return StaffService::search($req, array('kw' => $req->str('kw'), 'dept_id' => $req->int('dept_id'), 'status' => $req->str('status'), 'unassigned' => $req->int('unassigned')), $page, $size);
    }

    public function show(Request $req)
    {
        return StaffService::present(StaffService::scoped($req, $this->idParam($req)));
    }

    public function store(Request $req)
    {
        return array('id' => StaffService::create($req, $req->body));
    }

    public function update(Request $req)
    {
        StaffService::update($req, $this->idParam($req), $req->body);
        return array('ok' => true);
    }

    public function transfer(Request $req)
    {
        StaffService::transfer($req, $this->idParam($req), $req->body);
        return array('ok' => true);
    }

    public function remove(Request $req)
    {
        StaffService::remove($req, $this->idParam($req), $req->body);
        return array('ok' => true);
    }

    public function destroy(Request $req)
    {
        StaffService::delete($req, $this->idParam($req));
        return array('ok' => true);
    }

    public function deleteImpact(Request $req)
    {
        return StaffService::deleteImpact($req, $this->idParam($req));
    }

    public function removeImpact(Request $req)
    {
        return StaffService::removeImpact($req, $this->idParam($req));
    }

    public function history(Request $req)
    {
        return StaffService::history($req, $this->idParam($req));
    }

    public function transfers(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return StaffService::transfers($req, array('type' => $req->str('type'), 'kw' => $req->str('kw')), $page, $size);
    }
}
