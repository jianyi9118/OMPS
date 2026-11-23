<?php
/**
 * Results (phase 4). 'roles' = built-in roles that receive the permission when it first appears.
 * edit = write results on tasks assigned to me; audit = review results of people in my scope.
 */
return array(
    array('code' => 'perf.edit', 'group' => 'perf', 'action' => 'edit', 'roles' => array('unit_head', 'dept_head', 'member')),
    array('code' => 'perf.audit', 'group' => 'perf', 'action' => 'audit', 'roles' => array('unit_head', 'dept_head')),
);
