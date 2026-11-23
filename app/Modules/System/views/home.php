<?php /** 工作台: welcome line, then the workbench of the role (assets/js/pages/home.js). */ ?>
<div class="card"><div class="card-b" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
  <b><?= e(__('home.welcome', array('name' => $req->user['name']))) ?></b>
  <?php if ($last): ?><span class="muted"><?= e(__('home.last_login', array('time' => (string) $last['created_at'], 'ip' => (string) $last['ip']))) ?></span><?php endif; ?>
  <?php if (!empty($canReport)): ?><span style="flex:1"></span><button type="button" class="btn" data-a="reportFault" title="<?= e(__('device.report.hint')) ?>"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true" data-icon="wrench"></svg> <?= e(__('device.report.title')) ?></button><?php endif; ?>
</div></div>
<?php if (!empty($workbench)): ?>
<div id="view" data-page="home" style="display:grid;gap:12px"></div>
<?php endif; ?>
