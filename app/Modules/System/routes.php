<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\\Modules\\System\\Controllers\\';

// sign in / out
$router->get('/login', $c . 'AuthController@loginPage', array('auth' => false));
$router->post('/api/v1/auth/login', $c . 'AuthController@login', array('auth' => false));
$router->post('/api/v1/auth/logout', $c . 'AuthController@logout', array('allow_must_change' => true));
$router->post('/api/v1/lang', $c . 'AuthController@setLang', array('auth' => false));
$router->get('/password', $c . 'AuthController@passwordPage', array('allow_must_change' => true));
$router->post('/api/v1/me/password', $c . 'AuthController@changePassword', array('allow_must_change' => true));

$router->get('/', $c . 'HomeController@index');
$router->get('/api/v1/workbench', $c . 'HomeController@data');

// users
$router->get('/system/users', $c . 'UserController@page', array('perm' => 'users.view'));
$router->group(array('perm' => 'users.view'), function ($r) use ($c) {
    $r->get('/api/v1/users', $c . 'UserController@index');
    $r->get('/api/v1/users/options', $c . 'UserController@options');
});
$router->post('/api/v1/users', $c . 'UserController@store', array('perm' => 'users.add'));
$router->put('/api/v1/users/{id}', $c . 'UserController@update', array('perm' => 'users.edit'));
$router->post('/api/v1/users/{id}/reset-password', $c . 'UserController@resetPassword', array('perm' => 'users.edit'));
$router->post('/api/v1/users/{id}/unlock', $c . 'UserController@unlock', array('perm' => 'users.edit'));
$router->post('/api/v1/users/{id}/force-logout', $c . 'UserController@forceLogout', array('perm' => 'users.edit'));
$router->delete('/api/v1/users/{id}', $c . 'UserController@destroy', array('perm' => 'users.del'));

// roles and permissions (super admin)
$router->group(array('perm' => 'system.roles'), function ($r) use ($c) {
    $r->get('/system/roles', $c . 'RoleController@page');
    $r->get('/api/v1/roles', $c . 'RoleController@index');
    $r->post('/api/v1/roles', $c . 'RoleController@store');
    $r->put('/api/v1/roles/{id}', $c . 'RoleController@update');
    $r->put('/api/v1/roles/{id}/permissions', $c . 'RoleController@permissions');
    $r->delete('/api/v1/roles/{id}', $c . 'RoleController@destroy');
});

// settings
$router->group(array('perm' => 'system.settings'), function ($r) use ($c) {
    $r->get('/system/settings', $c . 'SettingsController@page');
    $r->get('/api/v1/settings', $c . 'SettingsController@show');
    $r->put('/api/v1/settings', $c . 'SettingsController@update');
});

// logs
$router->group(array('perm' => 'system.logs'), function ($r) use ($c) {
    $r->get('/system/logs', $c . 'LogController@page');
    $r->get('/api/v1/logs/{type}', $c . 'LogController@index');
});

// menu management (super admin)
$router->group(array('perm' => 'system.menus'), function ($r) use ($c) {
    $r->get('/system/menus', $c . 'AdminController@menusPage');
    $r->get('/api/v1/menus', $c . 'AdminController@menus');
    $r->put('/api/v1/menus', $c . 'AdminController@saveMenus');
    $r->post('/api/v1/menus/reset', $c . 'AdminController@resetMenus');
});

// languages and translations (super admin)
$router->group(array('perm' => 'system.i18n'), function ($r) use ($c) {
    $r->get('/system/i18n', $c . 'AdminController@i18nPage');
    $r->get('/api/v1/languages', $c . 'AdminController@languages');
    $r->delete('/api/v1/missing-translations', $c . 'AdminController@clearMissing');
    $r->post('/api/v1/languages', $c . 'AdminController@addLanguage');
    $r->put('/api/v1/languages/{code}', $c . 'AdminController@updateLanguage');
    $r->delete('/api/v1/languages/{code}', $c . 'AdminController@deleteLanguage');
    $r->get('/api/v1/languages/{code}/entries', $c . 'AdminController@entries');
    $r->put('/api/v1/languages/{code}/entries', $c . 'AdminController@saveEntries');
    $r->get('/api/v1/languages/{code}/export', $c . 'AdminController@exportEntries');
    $r->post('/api/v1/languages/{code}/import', $c . 'AdminController@importEntries');
});

// backups (super admin)
$router->group(array('perm' => 'system.backup'), function ($r) use ($c) {
    $r->get('/system/backup', $c . 'AdminController@backupPage');
    $r->get('/api/v1/backups', $c . 'AdminController@backups');
    $r->post('/api/v1/backups', $c . 'AdminController@createBackup');
    $r->put('/api/v1/backups/settings', $c . 'AdminController@backupSettings');
    $r->get('/api/v1/backups/{name}/download', $c . 'AdminController@downloadBackup');
    $r->get('/api/v1/backups/{name}/inspect', $c . 'AdminController@inspectBackup');
    $r->post('/api/v1/backups/{name}/restore', $c . 'AdminController@restoreBackup');
    $r->delete('/api/v1/backups/{name}', $c . 'AdminController@deleteBackup');
});
