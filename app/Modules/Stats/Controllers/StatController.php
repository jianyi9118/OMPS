<?php
namespace Omps\Modules\Stats\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Stats\Services\StatService;

class StatController extends Controller
{
    public function peoplePage(Request $req)
    {
        return $this->renderPage($req, 'Stats::page', 'menu.stat_people', array('page' => 'people'), array('charts', 'stats'));
    }

    public function deptsPage(Request $req)
    {
        return $this->renderPage($req, 'Stats::page', 'menu.stat_depts', array('page' => 'depts'), array('charts', 'stats'));
    }

    public function customPage(Request $req)
    {
        return $this->renderPage($req, 'Stats::page', 'menu.stat_custom', array('page' => 'custom'), array('charts', 'stats'));
    }

    public function people(Request $req)
    {
        return StatService::people($req);
    }

    public function depts(Request $req)
    {
        return StatService::depts($req);
    }

    public function pages(Request $req)
    {
        return StatService::pages($req);
    }

    public function page(Request $req)
    {
        return StatService::page($req, $this->idParam($req));
    }

    public function export(Request $req)
    {
        $which = in_array($req->str('which'), array('people', 'depts', 'page'), true) ? $req->str('which') : 'people';
        return StatService::export($req, $which, $req->int('id'));
    }
}
