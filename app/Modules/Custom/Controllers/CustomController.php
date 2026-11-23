<?php
namespace Omps\Modules\Custom\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Engines\Documents;
use Omps\Modules\Custom\Services\DocTemplateService;
use Omps\Modules\Custom\Services\FieldService;
use Omps\Modules\Custom\Services\ThemeService;
use Omps\Modules\Stats\Services\StatService;

class CustomController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'Custom::page', 'menu.custom', array(), array('charts', 'custom'));
    }

    // ---------- fields ----------
    public function fields(Request $req)
    {
        return FieldService::index($req->str('entity'));
    }

    public function createField(Request $req)
    {
        return FieldService::save($req, null, $req->body);
    }

    public function updateField(Request $req)
    {
        return FieldService::save($req, $this->idParam($req), $req->body);
    }

    public function deleteField(Request $req)
    {
        return FieldService::delete($req, $this->idParam($req));
    }

    public function orderFields(Request $req)
    {
        return FieldService::order($req, $req->body);
    }

    // ---------- statistics pages ----------
    public function statPages(Request $req)
    {
        return StatService::designList();
    }

    public function statPage(Request $req)
    {
        return StatService::designShow($this->idParam($req));
    }

    public function createStatPage(Request $req)
    {
        return StatService::designSave($req, null, $req->body);
    }

    public function updateStatPage(Request $req)
    {
        return StatService::designSave($req, $this->idParam($req), $req->body);
    }

    public function deleteStatPage(Request $req)
    {
        return StatService::designDelete($req, $this->idParam($req));
    }

    public function previewWidget(Request $req)
    {
        return StatService::preview($req, $req->body);
    }

    // ---------- theme and banners ----------
    public function theme(Request $req)
    {
        return ThemeService::show();
    }

    public function saveTheme(Request $req)
    {
        return ThemeService::save($req, $req->body);
    }

    public function uploadLogo(Request $req)
    {
        return ThemeService::uploadLogo($req);
    }

    public function removeLogo(Request $req)
    {
        return ThemeService::removeLogo($req);
    }

    public function uploadBanner(Request $req)
    {
        return ThemeService::uploadBanner($req);
    }

    public function saveBanners(Request $req)
    {
        return ThemeService::saveBanners($req, $req->body);
    }

    public function ownTheme(Request $req)
    {
        return ThemeService::own($req, $req->body);
    }

    public function logo(Request $req)
    {
        return ThemeService::serve($req, 'logo');
    }

    public function banner(Request $req)
    {
        return ThemeService::serve($req, 'banner', $this->idParam($req));
    }

    // ---------- document templates ----------
    public function docChoices(Request $req)
    {
        return array('choices' => Documents::choices($req->str('type')));
    }

    public function docTemplates(Request $req)
    {
        return DocTemplateService::index();
    }

    public function uploadDocTemplate(Request $req)
    {
        return DocTemplateService::upload($req, $req->str('type'));
    }

    public function defaultDocTemplate(Request $req)
    {
        return DocTemplateService::setDefault($req, $req->str('type'), $req->int('id'));
    }

    public function sampleDocTemplate(Request $req)
    {
        return DocTemplateService::sample($req->str('type'));
    }

    public function testDocTemplate(Request $req)
    {
        return DocTemplateService::test($req->str('type'), $req->int('id'));
    }

    public function docTemplateFile(Request $req)
    {
        return DocTemplateService::file($req, $this->idParam($req));
    }

    public function deleteDocTemplate(Request $req)
    {
        return DocTemplateService::delete($req, $this->idParam($req));
    }
}
