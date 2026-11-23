<?php
/**
 * Base data synced by `php omps seed` (idempotent). No accounts or passwords here:
 * the first super admin is created with `php omps admin:create`.
 *
 * Default permissions apply only when a built-in role is created for the first time;
 * later changes made in Roles & permissions are never overwritten.
 */
return array(
    'languages' => array(
        array('code' => 'zh-CN', 'name' => '简体中文', 'enabled' => 1, 'is_default' => 1, 'sort' => 1),
        array('code' => 'en-US', 'name' => 'English', 'enabled' => 1, 'is_default' => 0, 'sort' => 2),
    ),
    'roles' => array(
        array('code' => 'super', 'rank' => 4, 'scope' => 'all', 'sort' => 1, 'perms' => array()),
        array('code' => 'unit_head', 'rank' => 3, 'scope' => 'unit', 'sort' => 2, 'perms' => array('*')),
        array('code' => 'dept_head', 'rank' => 2, 'scope' => 'dept', 'sort' => 3, 'perms' => array(
            'org.view', 'staff.view', 'staff.add', 'staff.edit', 'staff.del', 'staff.audit')),
        array('code' => 'member', 'rank' => 1, 'scope' => 'self', 'sort' => 4, 'perms' => array('staff.view')),
    ),
    'dictionaries' => array(
        'gender' => array('male', 'female'),
        'person_status' => array('active', 'disabled', 'left', 'retired'),
        'change_type' => array('join', 'transfer', 'move_out', 'promote', 'leave', 'retire'),
        'id_type' => array('resident_id', 'passport', 'hk_macao_pass', 'other'),
        'degree_level' => array('high_school', 'junior_college', 'bachelor', 'postgraduate'),
        'degree' => array('none', 'bachelor', 'master', 'doctor'),
        'achievement_type' => array('paper', 'patent', 'software', 'award', 'other'),
        'relation' => array('spouse', 'father', 'mother', 'son', 'daughter', 'other'),
        'device_category' => array('pc', 'laptop', 'server', 'printer', 'scanner', 'network', 'monitor', 'projector', 'phone', 'other'),
    ),
    /*
     * What a person may change in their own record (phase 2). forbid = only administrators;
     * direct = takes effect at once; review = goes to the reviewer first.
     * '*' covers a whole sub-record type. Only inserted when missing; later edits are kept.
     */
    'field_policies' => array(
        array('profile', 'name', 'forbid'), array('profile', 'staff_no', 'forbid'), array('profile', 'dept_id', 'forbid'),
        array('profile', 'position', 'forbid'), array('profile', 'hire_date', 'forbid'), array('profile', 'status', 'forbid'),
        array('profile', 'remark', 'forbid'),
        array('profile', 'gender', 'review'), array('profile', 'birth_date', 'review'), array('profile', 'ethnicity', 'review'),
        array('profile', 'id_type', 'review'), array('profile', 'id_number', 'review'), array('profile', 'phone', 'review'),
        array('profile', 'hukou_address', 'review'), array('profile', 'photo_file_id', 'direct'),
        array('profile', 'email', 'direct'), array('profile', 'address', 'direct'),
        array('education', '*', 'review'), array('work', '*', 'review'), array('title', '*', 'review'), array('achievement', '*', 'review'),
        array('family', '*', 'direct'),
    ),
);
