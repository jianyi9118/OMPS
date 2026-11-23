<?php
namespace Omps\Modules\System\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\System\Services\BackupService;
use Omps\Modules\System\Services\I18nService;
use Omps\Modules\System\Services\MenuAdminService;

/** 菜单管理 · 多语言管理 · 数据备份 (phase 9, super administrator). */
class AdminController extends Controller
{
    // ---------- menus ----------
    public function menusPage(Request $req)
    {
        return $this->renderPage($req, 'System::admin', 'menu.menus', array('page' => 'menus'), array('menus'));
    }

    public function menus(Request $req)
    {
        return MenuAdminService::tree();
    }

    public function saveMenus(Request $req)
    {
        return MenuAdminService::save($req, $req->body);
    }

    public function resetMenus(Request $req)
    {
        return MenuAdminService::reset($req);
    }

    // ---------- languages ----------
    public function i18nPage(Request $req)
    {
        return $this->renderPage($req, 'System::admin', 'menu.i18n', array('page' => 'i18n'), array('i18n'));
    }

    public function languages(Request $req)
    {
        return I18nService::languages();
    }

    public function addLanguage(Request $req)
    {
        return I18nService::add($req, $req->body);
    }

    public function updateLanguage(Request $req)
    {
        return I18nService::update($req, $req->params['code'], $req->body);
    }

    public function deleteLanguage(Request $req)
    {
        return I18nService::delete($req, $req->params['code']);
    }

    public function clearMissing(Request $req)
    {
        return I18nService::clearMissing($req);
    }

    public function entries(Request $req)
    {
        return I18nService::entries($req->params['code'], array('module' => $req->str('module'), 'kw' => $req->str('kw'), 'missing' => $req->int('missing') === 1,
            'page' => $req->int('page', 1)));
    }

    public function saveEntries(Request $req)
    {
        return I18nService::save($req, $req->params['code'], isset($req->body['items']) && is_array($req->body['items']) ? $req->body['items'] : array());
    }

    public function exportEntries(Request $req)
    {
        return I18nService::export($req, $req->params['code']);
    }

    public function importEntries(Request $req)
    {
        return I18nService::import($req, $req->params['code'], $req->str('run') === '1');
    }

    // ---------- backups ----------
    public function backupPage(Request $req)
    {
        return $this->renderPage($req, 'System::admin', 'menu.backup', array('page' => 'backup'), array('backup'));
    }

    public function backups(Request $req)
    {
        return BackupService::index();
    }

    public function createBackup(Request $req)
    {
        return BackupService::create($req);
    }

    public function backupSettings(Request $req)
    {
        return BackupService::saveSettings($req, $req->body);
    }

    public function downloadBackup(Request $req)
    {
        return BackupService::download($req, $req->params['name']);
    }

    public function inspectBackup(Request $req)
    {
        return BackupService::inspect($req->params['name']);
    }

    public function restoreBackup(Request $req)
    {
        return BackupService::restore($req, $req->params['name'], $req->body);
    }

    public function deleteBackup(Request $req)
    {
        return BackupService::delete($req, $req->params['name']);
    }
}
