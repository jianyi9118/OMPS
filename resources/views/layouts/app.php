<?php
/** @var \Omps\Core\Request $req */
use Omps\Core\Lang;
use Omps\Core\Settings;
use Omps\Core\Url;
use Omps\Engines\Permission;
use Omps\Modules\System\Services\MenuService;
use Omps\Modules\System\Services\RoleService;

$u = $req->user;
$menu = MenuService::forUser($u);
list($group, $itemTitle) = MenuService::locate($menu, $activeRoute);
$unitName = (string) Settings::get('unit_name', '');
$langs = array();
foreach (Lang::languages() as $l) {
    if ((int) $l['enabled'] === 1) {
        $langs[] = array('code' => (string) $l['code'], 'name' => (string) $l['name']);
    }
}
$boot = array(
    'base' => Url::basePath(), 'rewrite' => Url::rewrite(), 'csrf' => (string) $req->session['csrf_token'],
    'lang' => Lang::locale(), 'userLang' => $u['lang'], 'defaultLang' => Lang::defaultCode(), 'languages' => $langs,
    'i18n' => Lang::pack(),
    'user' => array('id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'role' => RoleService::label($u['role_code'], $u['role_builtin']),
        'scope' => Permission::scope($u), 'perms' => Permission::codesFor($u), 'deptId' => $u['dept_id'], 'personId' => $u['person_id']),
);
$icon = function ($k, $s = 16) {
    return '<svg width="' . (int) $s . '" height="' . (int) $s . '" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true" data-icon="' . e($k) . '"></svg>';
};
// theme (phase 7): main colour, menu / top bar style, light or dark, logo
$theme = class_exists('Omps\\Modules\\Custom\\Services\\ThemeService') ? \Omps\Modules\Custom\Services\ThemeService::shell($u['id'])
    : array('mode' => 'auto', 'theme_attr' => '', 'classes' => '', 'css' => '', 'logo' => '');
$boot['theme'] = $theme['mode'];
?><!doctype html>
<html lang="<?= e(Lang::locale()) ?>"<?= $theme['theme_attr'] !== '' ? ' data-theme="' . e($theme['theme_attr']) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($unitName !== '' ? $unitName : __('app.name')) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/omps.css')) ?>">
<?php if ($theme['css'] !== ''): ?><style><?= $theme['css'] ?></style><?php endif; ?>
</head>
<body>
<div id="root"<?= $theme['classes'] !== '' ? ' class="' . e($theme['classes']) . '"' : '' ?>>
<div class="app" id="app">
  <aside class="side" aria-label="<?= e(__('layout.menu')) ?>">
    <div class="brand"><div class="logo<?= $theme['logo'] === '' ? ' def' : '' ?>"><img alt="" src="<?= e($theme['logo'] !== '' ? $theme['logo'] : asset('img/logo.png')) ?>"></div><div class="bn"><b><?= e($unitName !== '' ? $unitName : __('app.name')) ?></b><small>OMPS</small></div></div>
    <nav class="menu">
      <?php foreach ($menu as $m): ?>
        <?php if ($m['route'] !== ''): ?>
          <a class="mi<?= MenuService::matches($m['route'], $activeRoute) ? ' on' : '' ?>" href="<?= e(url($m['route'])) ?>" title="<?= e($m['title']) ?>" data-code="<?= e($m['code']) ?>"><?= $icon($m['icon']) ?><span class="lbl"><?= e($m['title']) ?></span><em class="mbadge" data-badge="<?= e($m['code']) ?>" hidden></em></a>
        <?php else:
            $open = false;
            foreach ($m['children'] as $k) {
                if (MenuService::matches($k['route'], $activeRoute)) {
                    $open = true;
                }
            } ?>
          <button type="button" class="mi<?= $open ? ' open' : '' ?>" data-a="mgrp" data-code="<?= e($m['code']) ?>" data-href="<?= e(url($m['children'][0]['route'])) ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" title="<?= e($m['title']) ?>"><?= $icon($m['icon']) ?><span class="lbl"><?= e($m['title']) ?></span><em class="mbadge" data-badge="<?= e($m['code']) ?>" hidden></em><span class="caret">▶</span></button>
          <div class="kids"<?= $open ? '' : ' hidden' ?>>
            <?php foreach ($m['children'] as $k): ?>
              <a class="mi<?= MenuService::matches($k['route'], $activeRoute) ? ' on' : '' ?>" href="<?= e(url($k['route'])) ?>" data-code="<?= e($k['code']) ?>"><span class="lbl"><?= e($k['title']) ?></span><em class="mbadge" data-badge="<?= e($k['code']) ?>" hidden></em></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <button type="button" class="collapse" data-a="collapse"><span class="lbl"><?= e(__('layout.collapse')) ?></span></button>
  </aside>
  <div class="main">
    <header class="top">
      <button type="button" class="tbtn hamb" data-a="mnav" aria-label="<?= e(__('layout.menu')) ?>"><?= $icon('menu') ?></button>
      <div class="crumb"><?php if ($group !== ''): ?><span class="gp"><?= e($group) ?> / </span><?php endif; ?><b><?= e($title) ?></b></div>
      <div class="tools">
        <button type="button" class="tbtn" data-a="bell" id="bellBtn" title="<?= e(__('notify.bell')) ?>" aria-label="<?= e(__('notify.bell')) ?>"><?= $icon('bell') ?><em id="bellN"></em></button>
        <button type="button" class="tbtn" data-a="themeMenu" title="<?= e(__('theme.switch')) ?>" aria-label="<?= e(__('theme.switch')) ?>"><?= $icon('theme') ?></button>
        <button type="button" class="tbtn" data-a="langMenu"><?= $icon('globe') ?><span class="hide-s"><?= e(\Omps\Modules\System\Services\LanguageService::name(Lang::locale())) ?></span></button>
        <button type="button" class="tbtn" data-a="userMenu"><span class="av"><?= e(mb_substr($u['name'], 0, 1)) ?></span><span class="hide-s"><?= e($u['name']) ?></span></button>
      </div>
    </header>
    <main class="content"><div class="page" id="page"><div id="urgeHost"></div><?= $content ?></div></main>
    <footer class="status"><span class="msg" id="stMsg" role="status" aria-live="polite"></span>
      <span class="r"><span class="hide-s"><?= e($boot['user']['role']) ?> · <?= e(__('scope.' . $boot['user']['scope'])) ?></span><span class="hide-s mono">v<?= e((string) config('app.version')) ?></span></span></footer>
  </div>
  <div class="mmask" data-a="mnav"></div>
</div>
</div>
<div id="dhost"></div><div id="mhost"></div><div id="ddhost"></div>
<script type="application/json" id="omps-boot"><?= json_for_script($boot) ?></script>
<script src="<?= e(asset('js/icons.js')) ?>"></script>
<script src="<?= e(asset('js/omps.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset('js/pages/' . $s . '.js')) ?>"></script>
<?php endforeach; ?>
</body>
</html>
