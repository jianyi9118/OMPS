<?php
/** 数据统计 (phase 7): unit heads and department heads, each within their data scope. */
return array(
    array('code' => 'stat.view', 'group' => 'stat', 'action' => 'view', 'roles' => array('unit_head', 'dept_head')),
    array('code' => 'stat.export', 'group' => 'stat', 'action' => 'export', 'roles' => array('unit_head', 'dept_head')),
);
