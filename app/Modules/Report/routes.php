<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\Modules\Report\Controllers\ReportController@';

// every report belongs to its creator (checked in ReportService); scope limits the conditions
$router->group(array('perm' => 'report.view'), function ($r) use ($c) {
    $r->get('/reports', $c . 'page');
    $r->get('/api/v1/reports/meta', $c . 'meta');
    $r->get('/api/v1/reports', $c . 'index');
    $r->post('/api/v1/reports/generate', $c . 'generate');
    $r->post('/api/v1/reports/manual', $c . 'createManual');
    $r->get('/api/v1/reports/{id}', $c . 'show');
    $r->put('/api/v1/reports/{id}', $c . 'update');
    $r->delete('/api/v1/reports/{id}', $c . 'destroy');
    $r->post('/api/v1/reports/{id}/files', $c . 'upload');
});

$router->group(array('perm' => 'report.export'), function ($r) use ($c) {
    $r->post('/api/v1/reports/{id}/printed', $c . 'printed');
    $r->get('/api/v1/reports/{id}/download', $c . 'download');
});
