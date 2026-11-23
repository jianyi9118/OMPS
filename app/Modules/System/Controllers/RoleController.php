<?php
namespace Omps\Modules\System\Controllers;

use Omps\Core\Controller;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Modules\System\Services\LanguageService;
use Omps\Modules\System\Services\RoleService;

class RoleController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'System::roles', 'menu.roles', array(), array('roles'));
    }

    public function index(Request $req)
    {
        $langs = array();
        foreach (Lang::enabledCodes() as $c) {
            $langs[] = array('code' => $c, 'name' => LanguageService::name($c));
        }
        return array('roles' => RoleService::all(), 'matrix' => RoleService::matrix(), 'languages' => $langs);
    }

    public function store(Request $req)
    {
        return array('id' => RoleService::create($req, $req->body));
    }

    public function update(Request $req)
    {
        RoleService::update($req, $this->idParam($req), $req->body);
        return array('ok' => true);
    }

    public function permissions(Request $req)
    {
        $codes = $req->input('codes', array());
        RoleService::setPermissions($req, $this->idParam($req), is_array($codes) ? $codes : array());
        return array('ok' => true);
    }

    public function destroy(Request $req)
    {
        RoleService::delete($req, $this->idParam($req));
        return array('ok' => true);
    }
}
