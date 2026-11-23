<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\Modules\Files\Controllers\FileController@';

$router->group(array('perm' => 'files.view'), function ($r) use ($c) {
    $r->get('/files', $c . 'page');
    $r->get('/api/v1/file-manager', $c . 'index');
    $r->get('/api/v1/file-manager/duplicates', $c . 'duplicates');
    $r->get('/api/v1/file-manager/trash', $c . 'trash');
    $r->get('/api/v1/file-manager/{id}/download', $c . 'download');
    $r->post('/api/v1/file-manager/{id}/restore', $c . 'restore');
    $r->post('/api/v1/file-manager/{id}/merge', $c . 'merge');
    $r->delete('/api/v1/file-manager/{id}', $c . 'destroy');
});
