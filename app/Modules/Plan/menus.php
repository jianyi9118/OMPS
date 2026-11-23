<?php
return array(
    array('code' => 'g-plan', 'parent' => null, 'title' => 'menu.g_plan', 'icon' => 'plan', 'route' => null, 'perm' => null, 'sort' => 300),
    array('code' => 'plan-mine', 'parent' => 'g-plan', 'title' => 'menu.plan_mine', 'icon' => null, 'route' => '/plans/mine', 'perm' => 'plan.view', 'sort' => 10),
    array('code' => 'plan-tree', 'parent' => 'g-plan', 'title' => 'menu.plan_tree', 'icon' => null, 'route' => '/plans/tree', 'perm' => 'plan.assign', 'sort' => 20),
);
