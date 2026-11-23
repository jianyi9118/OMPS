<?php
/** Comprehensive reports (phase 4). Each person sees only the reports they created. */
return array(
    array('code' => 'report.view', 'group' => 'report', 'action' => 'view', 'roles' => array('unit_head', 'dept_head', 'member')),
    array('code' => 'report.export', 'group' => 'report', 'action' => 'export', 'roles' => array('unit_head', 'dept_head', 'member')),
);
