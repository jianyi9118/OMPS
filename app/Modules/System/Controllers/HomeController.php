<?php
namespace Omps\Modules\System\Controllers;

use Omps\Core\Controller;
use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Database\DB;
use Omps\Engines\Permission;
use Omps\Modules\System\Services\WorkbenchService;

/**
 * 工作台 (design §17). Unit heads, department heads and the super admin get the workbench of their
 * role (assets/js/pages/home.js); members go straight to 我的任务. Everything stays inside the
 * user's data scope.
 */
class HomeController extends Controller
{
    public function index(Request $req)
    {
        $u = $req->user;
        $canReport = class_exists('Omps\\Modules\\Device\\Services\\RepairService');
        $scripts = $canReport ? array('device') : array();
        if (!WorkbenchService::has($u)) {
            if (class_exists('Omps\\Modules\\Plan\\Services\\PlanService') && Permission::can($u, 'plan.view')) {
                return Response::redirect(url('/plans/mine'));
            }
        } else {
            $scripts[] = 'home';
        }
        $last = DB::selectOne('SELECT created_at, ip FROM login_logs WHERE user_id = ? AND result = ? ORDER BY id DESC LIMIT 1 OFFSET 1', array($u['id'], 'success'));
        return $this->renderPage($req, 'System::home', 'menu.dashboard', array('last' => $last, 'canReport' => $canReport, 'workbench' => WorkbenchService::has($u)), $scripts);
    }

    public function data(Request $req)
    {
        if (!WorkbenchService::has($req->user)) {
            throw HttpException::forbidden();
        }
        return WorkbenchService::build($req->user);
    }
}
