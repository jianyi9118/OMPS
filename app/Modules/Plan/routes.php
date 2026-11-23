<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\\Modules\\Plan\\Controllers\\PlanController@';

$router->get('/plans/tree', $c . 'treePage', array('perm' => 'plan.assign'));

// fine-grained rules (scope, assignee, creator) are checked in PlanService
$router->group(array('perm' => 'plan.view'), function ($r) use ($c) {
    $r->get('/plans/mine', $c . 'minePage');
    $r->get('/api/v1/plans/meta', $c . 'meta');
    $r->get('/api/v1/plans', $c . 'index');
    $r->get('/api/v1/plans/{id}', $c . 'show');
    $r->get('/api/v1/plans/{id}/delete-impact', $c . 'deleteImpact');
    $r->post('/api/v1/plans', $c . 'store');
    $r->put('/api/v1/plans/{id}', $c . 'update');
    $r->delete('/api/v1/plans/{id}', $c . 'destroy');
});
