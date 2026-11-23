<?php
// Phase 4: results written on plans (one per assignee and plan), their history, comprehensive reports.
return array('up' => array(
    // One result per plan and assignee. The appr_* columns keep the last approved values: reports
    // and progress use only these, so a person can update an approved result without losing it.
    "CREATE TABLE `performance_reports` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `plan_id` BIGINT UNSIGNED NOT NULL,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `progress` DECIMAL(5,2) NULL,
        `done_content` TEXT NULL,
        `problems` TEXT NULL,
        `next_steps` TEXT NULL,
        `status` ENUM('draft','submitted','approved','returned','withdrawn') NOT NULL DEFAULT 'draft',
        `submitted_at` DATETIME NULL,
        `submitted_by` BIGINT UNSIGNED NULL,
        `reviewed_at` DATETIME NULL,
        `reviewed_by` BIGINT UNSIGNED NULL,
        `review_comment` VARCHAR(500) NULL,
        `score` DECIMAL(5,2) NULL,
        `reviewer_edited` TINYINT(1) NOT NULL DEFAULT 0,
        `appr_progress` DECIMAL(5,2) NULL,
        `appr_content` TEXT NULL,
        `appr_problems` TEXT NULL,
        `appr_next` TEXT NULL,
        `appr_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        `version` INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_plan_person` (`plan_id`, `person_id`),
        KEY `idx_person_status` (`person_id`, `status`),
        KEY `idx_status` (`status`)
    ) {options}",

    // Every save, submit, approval, edit by the reviewer, return and withdrawal.
    "CREATE TABLE `performance_versions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `report_id` BIGINT UNSIGNED NOT NULL,
        `version_no` INT UNSIGNED NOT NULL,
        `kind` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `snapshot` LONGTEXT NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_report` (`report_id`, `version_no`)
    ) {options}",

    // Comprehensive reports: generated from conditions (gen) or written by hand (manual).
    // Only the creator sees a report. title NULL = the default name built from the period.
    "CREATE TABLE `reports` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `kind` ENUM('gen','manual') NOT NULL,
        `title` VARCHAR(200) NULL,
        `period_start` DATE NULL,
        `period_end` DATE NULL,
        `plan_ids` LONGTEXT NULL,
        `person_ids` LONGTEXT NULL,
        `data_as_of` DATETIME NULL,
        `current_version` INT UNSIGNED NOT NULL DEFAULT 1,
        `created_by` BIGINT UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        `deleted_at` DATETIME NULL,
        `deleted_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_creator` (`created_by`, `deleted_at`)
    ) {options}",

    "CREATE TABLE `report_versions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `report_id` BIGINT UNSIGNED NOT NULL,
        `version_no` INT UNSIGNED NOT NULL,
        `title` VARCHAR(200) NULL,
        `period_start` DATE NULL,
        `period_end` DATE NULL,
        `intro` TEXT NULL,
        `outro` TEXT NULL,
        `content` LONGTEXT NULL,
        `body` LONGTEXT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_report_version` (`report_id`, `version_no`)
    ) {options}",

    "CREATE TABLE `report_exports` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `report_id` BIGINT UNSIGNED NOT NULL,
        `version_no` INT UNSIGNED NOT NULL,
        `format` VARCHAR(10) CHARACTER SET ascii NOT NULL,
        `exported_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_report` (`report_id`)
    ) {options}",
));
