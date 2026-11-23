<?php
// Roles, permissions, menus, users, sessions, login logs.
return array('up' => array(
    "CREATE TABLE `roles` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `name_key` VARCHAR(100) CHARACTER SET ascii NULL,
        `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
        `role_rank` INT NOT NULL DEFAULT 0,
        `scope` ENUM('self','dept','unit','all') NOT NULL DEFAULT 'self',
        `description` VARCHAR(200) NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) {options}",

    "CREATE TABLE `permissions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(100) CHARACTER SET ascii NOT NULL,
        `module` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `action` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `type` ENUM('action','sensitive','technical') NOT NULL DEFAULT 'action',
        `sort` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) {options}",

    "CREATE TABLE `role_permissions` (
        `role_id` BIGINT UNSIGNED NOT NULL,
        `permission_id` BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (`role_id`, `permission_id`),
        CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
    ) {options}",

    "CREATE TABLE `menus` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `parent_id` BIGINT UNSIGNED NULL,
        `code` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `title_key` VARCHAR(100) CHARACTER SET ascii NOT NULL,
        `icon` VARCHAR(30) CHARACTER SET ascii NULL,
        `route` VARCHAR(191) CHARACTER SET ascii NULL,
        `permission_code` VARCHAR(100) CHARACTER SET ascii NULL,
        `requires_device_admin` TINYINT(1) NOT NULL DEFAULT 0,
        `module` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`),
        KEY `idx_parent` (`parent_id`)
    ) {options}",

    "CREATE TABLE `users` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `person_id` BIGINT UNSIGNED NULL,
        `username` VARCHAR(50) NOT NULL,
        `display_name` VARCHAR(50) NOT NULL,
        `password_hash` VARCHAR(255) CHARACTER SET ascii NOT NULL,
        `role_id` BIGINT UNSIGNED NOT NULL,
        `is_device_admin` TINYINT(1) NOT NULL DEFAULT 0,
        `lang` VARCHAR(20) CHARACTER SET ascii NULL,
        `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
        `session_version` INT UNSIGNED NOT NULL DEFAULT 1,
        `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `locked_until` DATETIME NULL,
        `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
        `password_changed_at` DATETIME NULL,
        `last_login_at` DATETIME NULL,
        `last_login_ip` VARCHAR(45) CHARACTER SET ascii NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        `deleted_at` DATETIME NULL,
        `deleted_by` BIGINT UNSIGNED NULL,
        `version` INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_username` (`username`),
        KEY `idx_person` (`person_id`),
        KEY `idx_role` (`role_id`),
        CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
    ) {options}",

    // id = SHA-256 of the random cookie token; the raw token is never stored.
    "CREATE TABLE `user_sessions` (
        `id` CHAR(64) CHARACTER SET ascii NOT NULL,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `session_version` INT UNSIGNED NOT NULL,
        `csrf_token` CHAR(64) CHARACTER SET ascii NOT NULL,
        `ip` VARCHAR(45) CHARACTER SET ascii NULL,
        `user_agent` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL,
        `last_active` DATETIME NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `revoked_at` DATETIME NULL,
        `revoke_reason` VARCHAR(50) CHARACTER SET ascii NULL,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`)
    ) {options}",

    "CREATE TABLE `login_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `user_id` BIGINT UNSIGNED NULL,
        `username` VARCHAR(50) NOT NULL,
        `ip` VARCHAR(45) CHARACTER SET ascii NULL,
        `user_agent` VARCHAR(255) NULL,
        `result` ENUM('success','fail','locked','disabled','ip_blocked','logout') NOT NULL,
        `reason` VARCHAR(50) CHARACTER SET ascii NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_created` (`created_at`)
    ) {options}",

    "CREATE TABLE `login_attempts` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ip` VARCHAR(45) CHARACTER SET ascii NOT NULL,
        `username` VARCHAR(50) NOT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_ip_time` (`ip`, `created_at`)
    ) {options}",
));
