<?php
/** Device management (phase 6): device admins (兼任设备管理员), unit heads, super admin. */
return array(
    array('code' => 'g-dev', 'parent' => null, 'title' => 'menu.g_dev', 'icon' => 'dev', 'route' => null, 'perm' => null, 'sort' => 400),
    array('code' => 'dev-archive', 'parent' => 'g-dev', 'title' => 'menu.dev_archive', 'icon' => null, 'route' => '/device/archive', 'perm' => 'device.view', 'sort' => 10, 'device_admin' => true),
    array('code' => 'dev-monitor', 'parent' => 'g-dev', 'title' => 'menu.dev_monitor', 'icon' => null, 'route' => '/device/monitor', 'perm' => 'device.view', 'sort' => 20, 'device_admin' => true),
    array('code' => 'dev-repair', 'parent' => 'g-dev', 'title' => 'menu.dev_repair', 'icon' => null, 'route' => '/device/repair', 'perm' => 'device.view', 'sort' => 30, 'device_admin' => true),
);
