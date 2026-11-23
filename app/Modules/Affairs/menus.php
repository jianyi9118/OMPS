<?php
/** 我的事务 sits right below the workbench (design §17); each item shows a red pending count. */
return array(
    array('code' => 'g-aff', 'parent' => null, 'title' => 'menu.g_aff', 'icon' => 'msg', 'route' => null, 'perm' => null, 'sort' => 20),
    array('code' => 'aff-todo', 'parent' => 'g-aff', 'title' => 'menu.aff_todo', 'icon' => null, 'route' => '/affairs/todo', 'perm' => null, 'sort' => 10),
    array('code' => 'aff-issues', 'parent' => 'g-aff', 'title' => 'menu.aff_issues', 'icon' => null, 'route' => '/affairs/issues', 'perm' => null, 'sort' => 20),
    array('code' => 'aff-notes', 'parent' => 'g-aff', 'title' => 'menu.aff_notes', 'icon' => null, 'route' => '/affairs/notes', 'perm' => null, 'sort' => 30),
    array('code' => 'aff-notify', 'parent' => 'g-aff', 'title' => 'menu.aff_notify', 'icon' => null, 'route' => '/affairs/notifications', 'perm' => null, 'sort' => 40),
    array('code' => 'sys-reminders', 'parent' => 'g-sys', 'title' => 'menu.reminders', 'icon' => null, 'route' => '/system/reminders', 'perm' => 'notify.rules', 'sort' => 70),
);
