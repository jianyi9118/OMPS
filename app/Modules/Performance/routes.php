<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\Modules\Performance\Controllers\PerformanceController@';

// results on a plan: visibility of the plan and of each person is checked in PerformanceService
$router->group(array('perm' => 'plan.view'), function ($r) use ($c) {
    $r->get('/api/v1/perf/plans/{id}', $c . 'forPlan');
    $r->get('/api/v1/perf/reports/{id}/history', $c . 'history');
});

// my own result on a task assigned to me
$router->group(array('perm' => 'perf.edit'), function ($r) use ($c) {
    $r->get('/api/v1/perf/plans/{id}/mine', $c . 'mine');
    $r->put('/api/v1/perf/plans/{id}/mine', $c . 'save');
    $r->post('/api/v1/perf/plans/{id}/mine/files', $c . 'upload');
    $r->post('/api/v1/perf/reports/{id}/withdraw', $c . 'withdraw');
});

// review (fixed chain: scope covers the person, never oneself)
$router->group(array('perm' => 'perf.audit'), function ($r) use ($c) {
    $r->get('/performance/review', $c . 'reviewPage');
    $r->get('/api/v1/perf/reviews', $c . 'reviews');
    $r->post('/api/v1/perf/reviews/approve', $c . 'approve');
    $r->post('/api/v1/perf/reviews/return', $c . 'returnMany');
    $r->get('/api/v1/perf/reviews/{id}', $c . 'reviewDetail');
    $r->post('/api/v1/perf/reviews/{id}/approve', $c . 'editApprove');
    // statistics of the people under me: assigned plans executed, daily to annual reports submitted
    $r->get('/api/v1/perf/stats', $c . 'stats');
    $r->get('/api/v1/perf/stats/items', $c . 'statItems');
    $r->get('/api/v1/perf/stats/export', $c . 'statExport');
    // leave of the people under me (no daily report expected on those days)
    $r->get('/api/v1/perf/leaves', $c . 'leaves');
    $r->post('/api/v1/perf/leaves', $c . 'leaveStore');
    $r->put('/api/v1/perf/leaves/{id}', $c . 'leaveUpdate');
    $r->delete('/api/v1/perf/leaves/{id}', $c . 'leaveDestroy');
    $r->post('/api/v1/perf/leaves/{id}/approve', $c . 'leaveApprove');
    $r->post('/api/v1/perf/leaves/{id}/reject', $c . 'leaveReject');
});

// my own leave: apply, change while pending, withdraw (approved by whoever reviews my results)
$router->group(array('perm' => 'leave.apply'), function ($r) use ($c) {
    $r->get('/affairs/leave', $c . 'leavePage');
    $r->get('/api/v1/leave/mine', $c . 'leaveMine');
    $r->post('/api/v1/leave/mine', $c . 'leaveApply');
    $r->put('/api/v1/leave/mine/{id}', $c . 'leaveApplyUpdate');
    $r->post('/api/v1/leave/mine/{id}/withdraw', $c . 'leaveWithdraw');
});
