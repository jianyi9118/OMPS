<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\\Modules\\Personnel\\Controllers\\PersonnelController@';

// field metadata (also needed by people who only see their own record); before {id} routes
$router->get('/api/v1/personnel/meta', $c . 'meta');

// full staff record (scope checks in PersonnelService)
$router->group(array('perm' => 'staff.view'), function ($r) use ($c) {
    $r->get('/api/v1/personnel/{id}', $c . 'show');
    $r->put('/api/v1/personnel/{id}', $c . 'update');
    $r->get('/api/v1/personnel/{id}/history', $c . 'history');
    $r->get('/api/v1/personnel/{id}/document', $c . 'document');
    $r->get('/api/v1/staff-import/template', $c . 'template');
    $r->post('/api/v1/staff-import/check', $c . 'importCheck');
    $r->post('/api/v1/staff-import/run', $c . 'importRun');
    $r->get('/api/v1/staff-export', $c . 'export');
});

// full ID number: own record or staff.sensitive (checked and audited in the service)
$router->post('/api/v1/personnel/{id}/reveal-id', $c . 'revealId');

// attachments: own record or records the user may see (checked per file)
$router->post('/api/v1/personnel/{id}/files', $c . 'upload');
$router->get('/api/v1/files/{id}', $c . 'download');
$router->delete('/api/v1/files/{id}', $c . 'deleteFile');

// own profile (everyone with a linked staff record)
$router->get('/me/profile', $c . 'profilePage');
$router->get('/api/v1/me/profile', $c . 'myProfile');
$router->put('/api/v1/me/profile', $c . 'saveMyProfile');
$router->get('/api/v1/me/requests', $c . 'myRequests');
$router->get('/api/v1/me/profile/document', $c . 'myDocument');
$router->post('/api/v1/me/requests/{id}/withdraw', $c . 'withdraw');
$router->post('/api/v1/me/profile/files', function (\Omps\Core\Request $req) use ($c) {
    $req->params['id'] = (string) (int) $req->user['person_id'];
    return (new \Omps\Modules\Personnel\Controllers\PersonnelController())->upload($req);
});

// review of submitted changes
$router->group(array('perm' => 'staff.audit'), function ($r) use ($c) {
    $r->get('/api/v1/reviews', $c . 'reviews');
    $r->post('/api/v1/reviews/approve', $c . 'approve');
    $r->post('/api/v1/reviews/{id}/return', $c . 'returnRequest');
});

// family records (highly sensitive)
$router->group(array('perm' => 'family.view'), function ($r) use ($c) {
    $r->get('/org/family', $c . 'familyPage');
    $r->get('/api/v1/family', $c . 'family');
});
