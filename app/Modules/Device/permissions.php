<?php
/**
 * Device module permissions (phase 6). Device admins (兼任设备管理员) get every device.* code
 * automatically; unit heads have them from their role. Reporting a fault needs no permission.
 */
return array(
    array('code' => 'device.view', 'group' => 'device', 'action' => 'view'),
    array('code' => 'device.add', 'group' => 'device', 'action' => 'add'),
    array('code' => 'device.edit', 'group' => 'device', 'action' => 'edit'),
    array('code' => 'device.del', 'group' => 'device', 'action' => 'del'),
    array('code' => 'device.export', 'group' => 'device', 'action' => 'export'),
);
