<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\Modules\Device\Controllers\DeviceController@';

// collector clients: no session, a one-time code or the device token (checked in CollectorService)
$router->post('/api/v1/collector/register', $c . 'register', array('auth' => false, 'csrf' => false));
$router->post('/api/v1/collector/report', $c . 'collect', array('auth' => false, 'csrf' => false));

// everyone can report a fault (报修) from the workbench
$router->get('/api/v1/device-faults/options', $c . 'faultOptions');
$router->post('/api/v1/device-faults/report', $c . 'report');
// 故障与维修: device viewers see the requests in their scope, everyone else the ones they reported
$router->get('/device/repair', $c . 'repairPage');
$router->get('/api/v1/device-faults', $c . 'faults');
$router->get('/api/v1/device-faults/{id}', $c . 'fault');

$router->get('/device', $c . 'legacy', array('perm' => 'device.view'));
$router->group(array('perm' => 'device.view'), function ($r) use ($c) {
    $r->get('/device/archive', $c . 'archivePage');
    $r->get('/device/monitor', $c . 'monitorPage');
    $r->get('/api/v1/devices/meta', $c . 'meta');
    $r->get('/api/v1/devices', $c . 'index');
    $r->get('/api/v1/devices/{id}', $c . 'show');
    $r->get('/api/v1/device-repairs', $c . 'repairs');
    $r->get('/api/v1/device-usage', $c . 'usage');
    $r->get('/api/v1/device-anomalies', $c . 'anomalies');
    $r->get('/api/v1/device-collectors', $c . 'collectors');
    $r->get('/api/v1/collect-scope', $c . 'scope');
});

$router->group(array('perm' => 'device.add'), function ($r) use ($c) {
    $r->post('/api/v1/devices', $c . 'store');
    $r->get('/api/v1/device-import/template', $c . 'template');
    $r->post('/api/v1/device-import/check', $c . 'importCheck');
    $r->post('/api/v1/device-import/run', $c . 'importRun');
});

$router->group(array('perm' => 'device.edit'), function ($r) use ($c) {
    $r->put('/api/v1/devices/{id}', $c . 'update');
    $r->post('/api/v1/devices/{id}/holder', $c . 'holder');
    $r->post('/api/v1/devices/{id}/parts', $c . 'addPart');
    $r->post('/api/v1/devices/{id}/parts/{cid}/replace', $c . 'replacePart');
    $r->post('/api/v1/devices/{id}/parts/{cid}/remove', $c . 'removePart');
    $r->post('/api/v1/devices/{id}/licenses', $c . 'addLicense');
    $r->put('/api/v1/device-licenses/{id}', $c . 'updateLicense');
    $r->delete('/api/v1/device-licenses/{id}', $c . 'deleteLicense');
    $r->post('/api/v1/devices/{id}/files', $c . 'upload');
    $r->post('/api/v1/devices/{id}/collector-code', $c . 'enroll');
    $r->post('/api/v1/device-collectors/{id}/revoke', $c . 'revoke');
    $r->post('/api/v1/device-faults/{id}/assign', $c . 'assign');
    $r->post('/api/v1/device-faults/{id}/finish', $c . 'finish');
    $r->post('/api/v1/device-faults/{id}/cancel', $c . 'cancel');
    $r->post('/api/v1/device-anomalies/{id}/confirm', $c . 'confirm');
    $r->post('/api/v1/device-anomalies/{id}/ignore', $c . 'ignore');
    $r->put('/api/v1/collect-scope', $c . 'saveScope');
    $r->get('/api/v1/collector-client/{name}', $c . 'clientFile');
});

$router->delete('/api/v1/devices/{id}', $c . 'destroy', array('perm' => 'device.del'));
$router->get('/api/v1/device-export', $c . 'export', array('perm' => 'device.export'));
