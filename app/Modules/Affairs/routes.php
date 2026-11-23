<?php
/** @var \Omps\Core\Router $router */
$c = 'Omps\Modules\Affairs\Controllers\AffairsController@';

// every signed-in account: own to-dos, notifications, notebook, received items
$router->get('/affairs/todo', $c . 'todoPage');
$router->get('/affairs/notifications', $c . 'notifyPage');
$router->get('/affairs/notes', $c . 'notesPage');
$router->get('/affairs/issues', $c . 'issuesPage');

$router->get('/api/v1/me/pulse', $c . 'pulse');
$router->get('/api/v1/me/prefs', $c . 'prefs');
$router->put('/api/v1/me/prefs', $c . 'savePrefs');
$router->get('/api/v1/todo', $c . 'todo');

$router->get('/api/v1/notifications', $c . 'notifications');
$router->post('/api/v1/notifications/read-all', $c . 'readAll');
$router->post('/api/v1/notifications/{id}/read', $c . 'readOne');

// notebook: own notes, or notes submitted to me (checked in NoteService)
$router->get('/api/v1/notes/meta', $c . 'notesMeta');
$router->get('/api/v1/notes', $c . 'notes');
$router->post('/api/v1/notes', $c . 'createNote');
$router->get('/api/v1/notes/{id}', $c . 'note');
$router->put('/api/v1/notes/{id}', $c . 'updateNote');
$router->delete('/api/v1/notes/{id}', $c . 'deleteNote');
$router->post('/api/v1/notes/{id}/submit', $c . 'submitNote');
$router->post('/api/v1/notes/{id}/withdraw', $c . 'withdrawNote');
$router->post('/api/v1/notes/{id}/read', $c . 'readNote');
$router->post('/api/v1/notes/{id}/copy', $c . 'copyNote');

// issued items: everyone receives; sending needs issue.send (checked in IssueService)
$router->get('/api/v1/issues/meta', $c . 'issuesMeta');
$router->get('/api/v1/issues', $c . 'issues');
$router->post('/api/v1/issues', $c . 'createIssue', array('perm' => 'issue.send'));
$router->get('/api/v1/issues/{id}', $c . 'issue');
$router->post('/api/v1/issues/{id}/read', $c . 'readIssue');
$router->post('/api/v1/issues/{id}/reply', $c . 'replyIssue');
$router->post('/api/v1/issues/{id}/nudge', $c . 'nudgeIssue', array('perm' => 'issue.send'));
$router->post('/api/v1/issues/{id}/withdraw', $c . 'withdrawIssue', array('perm' => 'issue.send'));

// reminder rules (system management)
$router->group(array('perm' => 'notify.rules'), function ($r) use ($c) {
    $r->get('/system/reminders', $c . 'rulesPage');
    $r->get('/api/v1/reminder-rules', $c . 'rules');
    $r->post('/api/v1/reminder-rules/preview', $c . 'previewRule');
    $r->put('/api/v1/reminder-rules/{id}', $c . 'updateRule');
});
