<?php
return array(
    array('code' => 'g-stat', 'parent' => null, 'title' => 'menu.g_stat', 'icon' => 'stat', 'route' => null, 'perm' => null, 'sort' => 500),
    array('code' => 'stat-people', 'parent' => 'g-stat', 'title' => 'menu.stat_people', 'icon' => null, 'route' => '/stats/people', 'perm' => 'stat.view', 'sort' => 10),
    array('code' => 'stat-depts', 'parent' => 'g-stat', 'title' => 'menu.stat_depts', 'icon' => null, 'route' => '/stats/depts', 'perm' => 'stat.view', 'sort' => 20),
    array('code' => 'stat-custom', 'parent' => 'g-stat', 'title' => 'menu.stat_custom', 'icon' => null, 'route' => '/stats/custom', 'perm' => 'stat.view', 'sort' => 30),
);
