<?php
/** Layout for pages shown before login (sign-in). */
use Omps\Core\Lang;
use Omps\Core\Settings;
use Omps\Core\Url;

$unitName = (string) Settings::get('unit_name', '');
$langs = array();
foreach (Lang::languages() as $l) {
    if ((int) $l['enabled'] === 1) {
        $langs[] = array('code' => (string) $l['code'], 'name' => (string) $l['name']);
    }
}
$theme = class_exists('Omps\\Modules\\Custom\\Services\\ThemeService') ? \Omps\Modules\Custom\Services\ThemeService::shell(null)
    : array('mode' => 'auto', 'theme_attr' => '', 'classes' => '', 'css' => '', 'logo' => '');
$boot = array('base' => Url::basePath(), 'rewrite' => Url::rewrite(), 'csrf' => $csrf, 'lang' => Lang::locale(), 'languages' => $langs, 'i18n' => Lang::pack(), 'user' => null);
?><!doctype html>
<html lang="<?= e(Lang::locale()) ?>"<?= $theme['theme_attr'] !== '' ? ' data-theme="' . e($theme['theme_attr']) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($unitName !== '' ? $unitName : __('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/omps.css')) ?>">
<?php if ($theme['css'] !== ''): ?><style><?= $theme['css'] ?></style><?php endif; ?>
</head>
<body>
<div id="root"<?= $theme['classes'] !== '' ? ' class="' . e($theme['classes']) . '"' : '' ?>><?= $content ?></div>
<div id="mhost"></div><div id="ddhost"></div>
<script type="application/json" id="omps-boot"><?= json_for_script($boot) ?></script>
<script src="<?= e(asset('js/icons.js')) ?>"></script>
<script src="<?= e(asset('js/omps.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset('js/pages/' . $s . '.js')) ?>"></script>
<?php endforeach; ?>
</body>
</html>
