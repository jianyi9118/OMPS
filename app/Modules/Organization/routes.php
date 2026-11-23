<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\\Modules\\Organization\\Controllers\\';

// departments
$router->get('/org/depts', $c . 'DeptController@page', array('perm' => 'org.view'));
$router->get('/api/v1/depts', $c . 'DeptController@index', array('perm' => 'org.view'));
$router->post('/api/v1/depts', $c . 'DeptController@store', array('perm' => 'org.add'));
$router->put('/api/v1/depts/{id}', $c . 'DeptController@update', array('perm' => 'org.edit'));
$router->post('/api/v1/depts/{id}/move', $c . 'DeptController@move', array('perm' => 'org.edit'));
$router->delete('/api/v1/depts/{id}', $c . 'DeptController@destroy', array('perm' => 'org.del'));

// staff records (fine-grained checks by permission + data scope happen in StaffService)
$router->group(array('perm' => 'staff.view'), function ($r) use ($c) {
    $r->get('/org/staff', $c . 'StaffController@page');
    $r->get('/api/v1/staff/meta', $c . 'StaffController@meta');
    $r->get('/api/v1/staff', $c . 'StaffController@index');
    $r->get('/api/v1/staff/transfers', $c . 'StaffController@transfers');
    $r->get('/api/v1/staff/{id}', $c . 'StaffController@show');
    $r->get('/api/v1/staff/{id}/history', $c . 'StaffController@history');
    $r->get('/api/v1/staff/{id}/delete-impact', $c . 'StaffController@deleteImpact');
    $r->get('/api/v1/staff/{id}/remove-impact', $c . 'StaffController@removeImpact');
    $r->post('/api/v1/staff', $c . 'StaffController@store');
    $r->put('/api/v1/staff/{id}', $c . 'StaffController@update');
    $r->post('/api/v1/staff/{id}/transfer', $c . 'StaffController@transfer');
    $r->post('/api/v1/staff/{id}/remove', $c . 'StaffController@remove');
    $r->delete('/api/v1/staff/{id}', $c . 'StaffController@destroy');
});
