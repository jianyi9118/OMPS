<?php
// Phase 5: notifications (bell), notebook, issued items (上级下发), reminder rules, personal preferences.
return array('up' => array(
    // One row per recipient. Text is never stored: msg_key + params are rendered in the viewer's
    // language. dedupe_key (sha1 of rule / object / recipient / trigger point) makes every
    // scheduled reminder happen once, however often the scheduler runs.
    "CREATE TABLE `notifications` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `type` VARCHAR(40) CHARACTER SET ascii NOT NULL,
        `level` ENUM('info','warn','urgent') NOT NULL DEFAULT 'info',
        `params` TEXT NULL,
        `link` VARCHAR(191) CHARACTER SET ascii NULL,
        `biz_type` VARCHAR(50) CHARACTER SET ascii NULL,
        `biz_id` BIGINT UNSIGNED NULL,
        `actor_id` BIGINT UNSIGNED NULL,
        `dedupe_key` CHAR(40) CHARACTER SET ascii NULL,
        `read_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_dedupe` (`dedupe_key`),
        KEY `idx_user_read` (`user_id`, `read_at`),
        KEY `idx_biz` (`biz_type`, `biz_id`)
    ) {options}",

    // Notebook: private until submitted to the superior the system picks; locked after submitting.
    "CREATE TABLE `notes` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `owner_id` BIGINT UNSIGNED NOT NULL,
        `title` VARCHAR(200) NOT NULL,
        `body` MEDIUMTEXT NULL,
        `plan_id` BIGINT UNSIGNED NULL,
        `status` ENUM('private','submitted','read') NOT NULL DEFAULT 'private',
        `submitted_to` BIGINT UNSIGNED NULL,
        `submitted_at` DATETIME NULL,
        `read_at` DATETIME NULL,
        `reply` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        `version` INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        KEY `idx_owner` (`owner_id`, `updated_at`),
        KEY `idx_to` (`submitted_to`, `status`)
    ) {options}",

    // Issued items: tip (read only), document (read only, with files), order (complete and reply by a deadline).
    "CREATE TABLE `issues` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `sender_id` BIGINT UNSIGNED NOT NULL,
        `kind` ENUM('tip','doc','order') NOT NULL,
        `title` VARCHAR(200) NOT NULL,
        `body` TEXT NULL,
        `need_reply` TINYINT(1) NOT NULL DEFAULT 0,
        `due_at` DATETIME NULL,
        `remind_freq` ENUM('auto','day','half','2h') NOT NULL DEFAULT 'auto',
        `status` ENUM('active','withdrawn') NOT NULL DEFAULT 'active',
        `withdrawn_at` DATETIME NULL,
        `overdue_told` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_sender` (`sender_id`, `created_at`),
        KEY `idx_status_due` (`status`, `due_at`)
    ) {options}",

    "CREATE TABLE `issue_recipients` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `issue_id` BIGINT UNSIGNED NOT NULL,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `read_at` DATETIME NULL,
        `done_at` DATETIME NULL,
        `reply` TEXT NULL,
        `nudge_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `nudged_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_issue_user` (`issue_id`, `user_id`),
        KEY `idx_user` (`user_id`, `done_at`),
        CONSTRAINT `fk_ir_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`)
    ) {options}",

    // Reminder rules (design §13.3): one row per rule type, triggers stored as structured rows.
    "CREATE TABLE `reminder_rules` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) CHARACTER SET ascii NOT NULL,
        `level` ENUM('info','warn','urgent') NOT NULL DEFAULT 'info',
        `max_repeat` INT UNSIGNED NOT NULL DEFAULT 0,
        `escalate` TINYINT(1) NOT NULL DEFAULT 0,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `sort` INT NOT NULL DEFAULT 0,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) {options}",

    "CREATE TABLE `reminder_triggers` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `rule_id` BIGINT UNSIGNED NOT NULL,
        `mode` VARCHAR(10) CHARACTER SET ascii NOT NULL,
        `amount` INT UNSIGNED NOT NULL DEFAULT 0,
        `unit` VARCHAR(5) CHARACTER SET ascii NULL,
        `time_of_day` CHAR(5) CHARACTER SET ascii NULL,
        `weekday` TINYINT UNSIGNED NULL,
        `month_day` TINYINT UNSIGNED NULL,
        `repeat_mode` VARCHAR(5) CHARACTER SET ascii NULL,
        `sort` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_rule` (`rule_id`),
        CONSTRAINT `fk_rt_rule` FOREIGN KEY (`rule_id`) REFERENCES `reminder_rules` (`id`) ON DELETE CASCADE
    ) {options}",

    // Personal preferences (quiet hours, pop-up notices).
    "CREATE TABLE `user_prefs` (
        `user_id` BIGINT UNSIGNED NOT NULL,
        `pkey` VARCHAR(40) CHARACTER SET ascii NOT NULL,
        `pvalue` VARCHAR(500) NULL,
        PRIMARY KEY (`user_id`, `pkey`)
    ) {options}",
));
