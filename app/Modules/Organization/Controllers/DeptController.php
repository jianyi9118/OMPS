<?php
namespace Omps\Modules\Organization\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Database\DB;
use Omps\Engines\Permission;
use Omps\Modules\Organization\Services\DeptService;

class DeptController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'Organization::depts', 'menu.depts', array(), array('depts'));
    }

    public function index(Request $req)
    {
        $u = $req->user;
        $people = DB::table('personnel_profiles p')->active()->where('p.status', 'active')->select(array('p.id', 'p.name', 'p.staff_no'));
        Permission::scopePersonnel($people, $u, 'p');
        return array(
            'depts' => DeptService::tree($u),
            'people' => $people->orderBy('p.name')->limit(3000)->get(),
            'caps' => array('add' => Permission::can($u, 'org.add'), 'edit' => Permission::can($u, 'org.edit'), 'del' => Permission::can($u, 'org.del'),
                'topLevel' => Permission::deptIds($u) === null),
        );
    }

    public function store(Request $req)
    {
        return array('id' => DeptService::create($req, $req->body));
    }

    public function update(Request $req)
    {
        DeptService::update($req, $this->idParam($req), $req->body);
        return array('ok' => true);
    }

    public function move(Request $req)
    {
        DeptService::move($req, $this->idParam($req), $req->str('dir') === 'up' ? 'up' : 'down');
        return array('ok' => true);
    }

    public function destroy(Request $req)
    {
        DeptService::delete($req, $this->idParam($req));
        return array('ok' => true);
    }
}
