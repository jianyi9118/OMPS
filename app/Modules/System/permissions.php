<?php
/**
 * Permission codes of the System module.
 * Type 'action' entries can be granted in Roles & permissions; 'technical' ones are super-admin only.
 */
return array(
    array('code' => 'users.view', 'group' => 'users', 'action' => 'view'),
    array('code' => 'users.add', 'group' => 'users', 'action' => 'add'),
    array('code' => 'users.edit', 'group' => 'users', 'action' => 'edit'),
    array('code' => 'users.del', 'group' => 'users', 'action' => 'del'),
    array('code' => 'system.roles', 'group' => 'system', 'action' => 'roles', 'type' => 'technical'),
    array('code' => 'system.menus', 'group' => 'system', 'action' => 'menus', 'type' => 'technical'),
    array('code' => 'system.settings', 'group' => 'system', 'action' => 'settings', 'type' => 'technical'),
    array('code' => 'system.i18n', 'group' => 'system', 'action' => 'i18n', 'type' => 'technical'),
    array('code' => 'system.logs', 'group' => 'system', 'action' => 'logs', 'type' => 'technical'),
    array('code' => 'system.backup', 'group' => 'system', 'action' => 'backup', 'type' => 'technical'),
);
