<?php
/** Plain error page (works even when the user is not logged in). */
use Omps\Core\Lang;
?><!doctype html>
<html lang="<?= e(Lang::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e((string) $status) ?> · <?= e(__('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/omps.css')) ?>">
</head>
<body>
<div id="root" style="display:grid;place-items:center">
  <div class="card" style="max-width:460px;width:calc(100% - 32px)">
    <div class="card-h"><?= e(__('error.title', array('status' => (string) $status))) ?></div>
    <div class="card-b"><p><?= e($message) ?></p><p><a class="btn pri" href="<?= e(url('/')) ?>"><?= e(__('error.back_home')) ?></a></p></div>
  </div>
</div>
</body>
</html>
