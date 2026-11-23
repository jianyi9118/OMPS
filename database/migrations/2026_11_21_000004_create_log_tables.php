<?php
// Operation log, audit log (not deletable), data versions (history snapshots).
return array('up' => array(
    // Actions are stored as codes and translated when viewed (design §16).
    "CREATE TABLE `operation_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `user_id` BIGINT UNSIGNED NULL,
        `username` VARCHAR(50) NULL,
        `module` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `action` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `target_type` VARCHAR(50) CHARACTER SET ascii NULL,
        `target_id` BIGINT UNSIGNED NULL,
        `target_label` VARCHAR(191) NULL,
        `detail` LONGTEXT NULL,
        `ip` VARCHAR(45) CHARACTER SET ascii NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_created` (`created_at`),
        KEY `idx_user` (`user_id`),
        KEY `idx_module` (`module`)
    ) {options}",

    "CREATE TABLE `audit_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `user_id` BIGINT UNSIGNED NULL,
        `username` VARCHAR(50) NULL,
        `module` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `action` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `target_type` VARCHAR(50) CHARACTER SET ascii NULL,
        `target_id` BIGINT UNSIGNED NULL,
        `target_label` VARCHAR(191) NULL,
        `before_data` LONGTEXT NULL,
        `after_data` LONGTEXT NULL,
        `ip` VARCHAR(45) CHARACTER SET ascii NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_created` (`created_at`),
        KEY `idx_user` (`user_id`),
        KEY `idx_action` (`action`)
    ) {options}",

    "CREATE TABLE `data_versions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `entity_type` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `entity_id` BIGINT UNSIGNED NOT NULL,
        `version` INT UNSIGNED NOT NULL,
        `snapshot` LONGTEXT NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_entity` (`entity_type`, `entity_id`)
    ) {options}",
));
