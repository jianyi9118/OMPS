<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\Modules\Stats\Controllers\StatController@';

$router->group(array('perm' => 'stat.view'), function ($r) use ($c) {
    $r->get('/stats/people', $c . 'peoplePage');
    $r->get('/stats/depts', $c . 'deptsPage');
    $r->get('/stats/custom', $c . 'customPage');
    $r->get('/api/v1/stats/people', $c . 'people');
    $r->get('/api/v1/stats/depts', $c . 'depts');
    $r->get('/api/v1/stats/pages', $c . 'pages');
    $r->get('/api/v1/stats/pages/{id}', $c . 'page');
});
$router->get('/api/v1/stats/export', $c . 'export', array('perm' => 'stat.export'));
