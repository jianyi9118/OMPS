<?php
/** Sign-in page. Submitted by assets/js/pages/login.js. */
use Omps\Core\Lang;
use Omps\Core\Settings;

$unitName = (string) Settings::get('unit_name', '');
// login picture (Banner 管理): one of the enabled pictures, with its caption in this language
// logo: the uploaded one (系统主题), else the built-in picture
$banner = null;
$logo = '';
if (class_exists('Omps\\Modules\\Custom\\Services\\ThemeService')) {
    $list = \Omps\Modules\Custom\Services\ThemeService::banners(true);
    $banner = $list ? $list[array_rand($list)] : null;
    $logo = (string) \Omps\Modules\Custom\Services\ThemeService::shell(null)['logo'];
}
?>
<div class="login">
  <div class="lg-ban<?= $banner ? ' pic' : '' ?>"<?= $banner ? ' style="background-image:url(\'' . e($banner['url']) . '\')"' : '' ?>>
    <?php if (!$banner): ?><div class="grid"></div><?php endif; ?>
    <div style="position:relative">
      <h1><?= e($unitName !== '' ? $unitName : __('app.name')) ?></h1>
      <p><?= e($banner && $banner['caption'] !== '' ? $banner['caption'] : __('auth.banner')) ?></p>
    </div>
    <div class="ft">OMPS v<?= e((string) config('app.version')) ?></div>
  </div>
  <form class="lg-box" id="loginForm" autocomplete="on" novalidate>
    <div class="lg-logo<?= $logo === '' ? ' def' : '' ?>"><img alt="" src="<?= e($logo !== '' ? $logo : asset('img/logo.png')) ?>"><b><?= e($unitName !== '' ? $unitName : __('app.name')) ?></b></div>
    <h2><?= e(__('auth.sign_in')) ?></h2>
    <div class="fld"><label for="lg_user"><?= e(__('auth.username')) ?></label><input id="lg_user" name="username" autocomplete="username" maxlength="50" required autofocus></div>
    <div class="fld"><label for="lg_pass"><?= e(__('auth.password')) ?></label><input id="lg_pass" name="password" type="password" autocomplete="current-password" maxlength="128" required></div>
    <div class="note bad" id="lg_err" hidden></div>
    <button class="btn pri" type="submit" id="lg_btn"><?= e(__('auth.sign_in')) ?></button>
    <div class="fld"><label for="lg_lang"><?= e(__('layout.language')) ?></label>
      <select id="lg_lang">
        <?php foreach (Lang::languages() as $l): if ((int) $l['enabled'] !== 1) { continue; } ?>
          <option value="<?= e($l['code']) ?>"<?= (string) $l['code'] === Lang::locale() ? ' selected' : '' ?>><?= e($l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="muted" style="font-size:12px;margin:0"><?= e(__('auth.forgot_hint')) ?></p>
  </form>
</div>
