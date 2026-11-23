<?php
/** Menu entries (code, parent, title key, icon, route, permission). Synced by `php omps seed`. */
return array(
    array('code' => 'dashboard', 'parent' => null, 'title' => 'menu.dashboard', 'icon' => 'home', 'route' => '/', 'perm' => null, 'sort' => 10),
    array('code' => 'g-sys', 'parent' => null, 'title' => 'menu.g_sys', 'icon' => 'sys', 'route' => null, 'perm' => null, 'sort' => 900),
    array('code' => 'sys-users', 'parent' => 'g-sys', 'title' => 'menu.users', 'icon' => null, 'route' => '/system/users', 'perm' => 'users.view', 'sort' => 10),
    array('code' => 'sys-roles', 'parent' => 'g-sys', 'title' => 'menu.roles', 'icon' => null, 'route' => '/system/roles', 'perm' => 'system.roles', 'sort' => 20),
    array('code' => 'sys-menus', 'parent' => 'g-sys', 'title' => 'menu.menus', 'icon' => null, 'route' => '/system/menus', 'perm' => 'system.menus', 'sort' => 25),
    array('code' => 'sys-i18n', 'parent' => 'g-sys', 'title' => 'menu.i18n', 'icon' => null, 'route' => '/system/i18n', 'perm' => 'system.i18n', 'sort' => 75),
    array('code' => 'sys-backup', 'parent' => 'g-sys', 'title' => 'menu.backup', 'icon' => null, 'route' => '/system/backup', 'perm' => 'system.backup', 'sort' => 92),
    array('code' => 'sys-settings', 'parent' => 'g-sys', 'title' => 'menu.settings', 'icon' => null, 'route' => '/system/settings', 'perm' => 'system.settings', 'sort' => 95),
    array('code' => 'sys-logs', 'parent' => 'g-sys', 'title' => 'menu.logs', 'icon' => null, 'route' => '/system/logs', 'perm' => 'system.logs', 'sort' => 90),
);
