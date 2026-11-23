<?php
namespace Omps\Modules\System\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Engines\Permission;
use Omps\Modules\System\Services\RoleService;
use Omps\Modules\System\Services\UserService;

class UserController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'System::users', 'menu.users', array(), array('users'));
    }

    public function index(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return UserService::search($req->user, array('kw' => $req->str('kw'), 'role_id' => $req->int('role_id'), 'status' => $req->str('status')), $page, $size);
    }

    public function options(Request $req)
    {
        return array(
            'roles' => RoleService::assignable($req->user),
            'persons' => UserService::personOptions($req, $req->int('include_person')),
            'canAdd' => Permission::can($req->user, 'users.add'),
            'canEdit' => Permission::can($req->user, 'users.edit'),
            'canDel' => Permission::can($req->user, 'users.del'),
        );
    }

    public function store(Request $req)
    {
        return UserService::create($req, $req->body);
    }

    public function update(Request $req)
    {
        UserService::update($req, $this->idParam($req), $req->body);
        return array('ok' => true);
    }

    public function resetPassword(Request $req)
    {
        return array('temp_password' => UserService::resetPassword($req, $this->idParam($req)));
    }

    public function unlock(Request $req)
    {
        UserService::unlock($req, $this->idParam($req));
        return array('ok' => true);
    }

    public function forceLogout(Request $req)
    {
        UserService::forceLogout($req, $this->idParam($req));
        return array('ok' => true);
    }

    public function destroy(Request $req)
    {
        UserService::delete($req, $this->idParam($req));
        return array('ok' => true);
    }
}
