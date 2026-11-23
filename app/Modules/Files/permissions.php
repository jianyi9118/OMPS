<?php
/** 文件管理 (phase 9): everyone; what each person sees follows the rules of the records the files belong to. */
return array(
    array('code' => 'files.view', 'group' => 'files', 'action' => 'view', 'roles' => array('unit_head', 'dept_head', 'member')),
);
