<?php
/**
 * Plan permissions (phase 3). 'roles' = built-in roles that receive the permission when it first appears.
 * assign = manage the whole plan tree (create main plans, assign people); members add and edit only
 * their own sub-plans under tasks given to them.
 */
return array(
    array('code' => 'plan.view', 'group' => 'plan', 'action' => 'view', 'roles' => array('unit_head', 'dept_head', 'member')),
    array('code' => 'plan.add', 'group' => 'plan', 'action' => 'add', 'roles' => array('unit_head', 'dept_head', 'member')),
    array('code' => 'plan.edit', 'group' => 'plan', 'action' => 'edit', 'roles' => array('unit_head', 'dept_head', 'member')),
    array('code' => 'plan.del', 'group' => 'plan', 'action' => 'del', 'roles' => array('unit_head', 'dept_head', 'member')),
    array('code' => 'plan.assign', 'group' => 'plan', 'action' => 'assign', 'roles' => array('unit_head', 'dept_head')),
    array('code' => 'plan.export', 'group' => 'plan', 'action' => 'export', 'roles' => array('unit_head', 'dept_head')),
);
