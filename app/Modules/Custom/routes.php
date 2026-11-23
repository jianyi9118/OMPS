<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\Modules\Custom\Controllers\CustomController@';

// logo and login pictures are shown before sign-in; only the configured pictures are served
$router->get('/brand/logo', $c . 'logo', array('auth' => false));
$router->get('/brand/banner/{id}', $c . 'banner', array('auth' => false));
// everyone: own light / dark choice
$router->post('/api/v1/me/theme', $c . 'ownTheme');
// everyone who may download a document: the template names for the download menu
$router->get('/api/v1/doc-templates/choices', $c . 'docChoices');

$router->group(array('perm' => 'system.custom'), function ($r) use ($c) {
    $r->get('/system/custom', $c . 'page');
    $r->get('/api/v1/custom-fields', $c . 'fields');
    $r->post('/api/v1/custom-fields', $c . 'createField');
    $r->post('/api/v1/custom-fields/order', $c . 'orderFields');
    $r->put('/api/v1/custom-fields/{id}', $c . 'updateField');
    $r->delete('/api/v1/custom-fields/{id}', $c . 'deleteField');
    $r->get('/api/v1/stat-pages', $c . 'statPages');
    $r->post('/api/v1/stat-pages', $c . 'createStatPage');
    $r->post('/api/v1/stat-pages/preview', $c . 'previewWidget');
    $r->get('/api/v1/stat-pages/{id}', $c . 'statPage');
    $r->put('/api/v1/stat-pages/{id}', $c . 'updateStatPage');
    $r->delete('/api/v1/stat-pages/{id}', $c . 'deleteStatPage');
    $r->get('/api/v1/theme', $c . 'theme');
    $r->put('/api/v1/theme', $c . 'saveTheme');
    $r->post('/api/v1/theme/logo', $c . 'uploadLogo');
    $r->delete('/api/v1/theme/logo', $c . 'removeLogo');
    $r->post('/api/v1/theme/banners', $c . 'uploadBanner');
    $r->put('/api/v1/theme/banners', $c . 'saveBanners');
    $r->get('/api/v1/doc-templates', $c . 'docTemplates');
    $r->post('/api/v1/doc-templates', $c . 'uploadDocTemplate');
    $r->post('/api/v1/doc-templates/default', $c . 'defaultDocTemplate');
    $r->get('/api/v1/doc-templates/sample', $c . 'sampleDocTemplate');
    $r->get('/api/v1/doc-templates/test', $c . 'testDocTemplate');
    $r->get('/api/v1/doc-templates/{id}/file', $c . 'docTemplateFile');
    $r->delete('/api/v1/doc-templates/{id}', $c . 'deleteDocTemplate');
});
