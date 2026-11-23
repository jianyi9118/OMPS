<?php
// Phase 3: plans (long-term to daily), closure table, assignees.
return array('up' => array(
    "CREATE TABLE `plans` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `parent_id` BIGINT UNSIGNED NULL,
        `dept_id` BIGINT UNSIGNED NULL,
        `level` ENUM('long','annual','quarter','month','week','day') NOT NULL,
        `period_key` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `code` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `title` VARCHAR(150) NOT NULL,
        `goal` TEXT NULL,
        `content` TEXT NULL,
        `start_date` DATE NOT NULL,
        `end_date` DATE NOT NULL,
        `time_label_mode` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'auto',
        `time_label_text` VARCHAR(20) NULL,
        `local_weight` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        `priority` ENUM('high','medium','low') NULL,
        `status` ENUM('active','done','cancelled') NOT NULL DEFAULT 'active',
        `owner_person_id` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        `deleted_at` DATETIME NULL,
        `deleted_by` BIGINT UNSIGNED NULL,
        `version` INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`),
        KEY `idx_parent` (`parent_id`),
        KEY `idx_dept` (`dept_id`),
        KEY `idx_dates` (`start_date`, `end_date`),
        KEY `idx_period` (`period_key`)
    ) {options}",

    // Ancestor / descendant pairs (including each plan with itself, depth 0).
    "CREATE TABLE `plan_paths` (
        `ancestor_id` BIGINT UNSIGNED NOT NULL,
        `descendant_id` BIGINT UNSIGNED NOT NULL,
        `depth` INT NOT NULL,
        PRIMARY KEY (`ancestor_id`, `descendant_id`),
        KEY `idx_desc` (`descendant_id`)
    ) {options}",

    "CREATE TABLE `plan_assignments` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `plan_id` BIGINT UNSIGNED NOT NULL,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `assigned_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_plan_person` (`plan_id`, `person_id`),
        KEY `idx_person` (`person_id`)
    ) {options}",
));
