<?php
return array(
    array('code' => 'g-org', 'parent' => null, 'title' => 'menu.g_org', 'icon' => 'org', 'route' => null, 'perm' => null, 'sort' => 200),
    array('code' => 'org-depts', 'parent' => 'g-org', 'title' => 'menu.depts', 'icon' => null, 'route' => '/org/depts', 'perm' => 'org.view', 'sort' => 10),
    array('code' => 'org-staff', 'parent' => 'g-org', 'title' => 'menu.staff', 'icon' => null, 'route' => '/org/staff', 'perm' => 'staff.view', 'sort' => 20),
);
