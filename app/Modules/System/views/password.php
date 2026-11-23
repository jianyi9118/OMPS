<?php /** Change password. Submitted by assets/js/pages/password.js. */ ?>
<div class="card" style="max-width:520px">
  <div class="card-h"><?= e(__('auth.change_password')) ?></div>
  <form class="card-b" id="pwForm" novalidate style="display:grid;gap:12px">
    <?php if (!empty($forced)): ?><div class="note warn"><?= e(__('auth.must_change_password')) ?></div><?php endif; ?>
    <div class="fld"><label for="pw_old"><?= e(__('auth.old_password')) ?></label><input id="pw_old" name="old_password" type="password" autocomplete="current-password" maxlength="128"><span class="em" data-err="old_password"></span></div>
    <div class="fld"><label for="pw_new"><?= e(__('auth.new_password')) ?></label><input id="pw_new" name="new_password" type="password" autocomplete="new-password" maxlength="128"><span class="hint"><?= e(__('auth.password_rule', array('min' => (int) \Omps\Core\Settings::int('password_min_length')))) ?></span><span class="em" data-err="new_password"></span></div>
    <div class="fld"><label for="pw_confirm"><?= e(__('auth.confirm_password')) ?></label><input id="pw_confirm" name="confirm_password" type="password" autocomplete="new-password" maxlength="128"><span class="em" data-err="confirm_password"></span></div>
    <div><button class="btn pri" type="submit"><?= e(__('common.save')) ?></button></div>
    <p class="muted" style="margin:0;font-size:12px"><?= e(__('auth.password_other_sessions')) ?></p>
  </form>
</div>
