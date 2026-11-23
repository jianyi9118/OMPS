<?php
/**
 * Organization and staff permissions. For staff records: add = add people,
 * del = remove people (leave / retire / move out; whole-unit roles may also delete),
 * edit = edit records. The role's data scope decides whom they apply to.
 */
return array(
    array('code' => 'org.view', 'group' => 'org', 'action' => 'view'),
    array('code' => 'org.add', 'group' => 'org', 'action' => 'add'),
    array('code' => 'org.edit', 'group' => 'org', 'action' => 'edit'),
    array('code' => 'org.del', 'group' => 'org', 'action' => 'del'),
    array('code' => 'staff.view', 'group' => 'staff', 'action' => 'view'),
    array('code' => 'staff.add', 'group' => 'staff', 'action' => 'add'),
    array('code' => 'staff.edit', 'group' => 'staff', 'action' => 'edit'),
    array('code' => 'staff.del', 'group' => 'staff', 'action' => 'del'),
    array('code' => 'staff.export', 'group' => 'staff', 'action' => 'export'),
    array('code' => 'staff.audit', 'group' => 'staff', 'action' => 'audit'),
    array('code' => 'staff.sensitive', 'group' => 'staff', 'action' => 'sensitive', 'type' => 'sensitive'),
    array('code' => 'family.view', 'group' => 'family', 'action' => 'view'),
    array('code' => 'family.edit', 'group' => 'family', 'action' => 'edit'),
    array('code' => 'family.sensitive', 'group' => 'family', 'action' => 'sensitive', 'type' => 'sensitive'),
);
