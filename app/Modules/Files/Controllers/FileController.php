<?php
namespace Omps\Modules\Files\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Files\Services\FileManagerService;

class FileController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'Files::page', 'menu.files', array(), array('files'));
    }

    public function index(Request $req)
    {
        return FileManagerService::index($req);
    }

    public function duplicates(Request $req)
    {
        return FileManagerService::duplicates($req);
    }

    public function trash(Request $req)
    {
        return FileManagerService::trash($req);
    }

    public function download(Request $req)
    {
        return FileManagerService::download($req, $this->idParam($req), $req->int('inline') === 1);
    }

    public function restore(Request $req)
    {
        return FileManagerService::restore($req, $this->idParam($req));
    }

    public function merge(Request $req)
    {
        return FileManagerService::merge($req, $this->idParam($req));
    }

    public function destroy(Request $req)
    {
        return FileManagerService::delete($req, $this->idParam($req));
    }
}
