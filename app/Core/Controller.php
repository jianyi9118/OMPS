<?php
namespace Omps\Core;

use Omps\Engines\Permission;

/**
 * Base controller with small helpers.
 */
abstract class Controller
{
    protected function renderPage(Request $req, $view, $titleKey, array $data = array(), array $scripts = array())
    {
        $content = View::render($view, array_merge($data, array('req' => $req)));
        return Response::html(View::render('layouts/app', array(
            'req' => $req, 'title' => __($titleKey), 'content' => $content, 'scripts' => $scripts,
            'activeRoute' => $req->path,
        )));
    }

    protected function requirePerm(Request $req, $code)
    {
        if (!Permission::can($req->user, $code)) {
            \Omps\Engines\Audit::denied($req, 'permission', array('perm' => $code));
            throw HttpException::forbidden();
        }
    }

    protected function idParam(Request $req, $name = 'id')
    {
        $v = (string) $req->param($name, '');
        if (!preg_match('/^\d{1,18}$/', $v)) {
            throw HttpException::notFound();
        }
        return (int) $v;
    }

    /** Pagination parameters from the query string. */
    protected function pageArgs(Request $req)
    {
        return array(max(1, $req->int('page', 1)), min(100, max(5, $req->int('size', 20))));
    }
}
