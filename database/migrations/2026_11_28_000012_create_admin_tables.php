<?php
// Phase 9: file management, backups, menu management (design §11, §15, §16).
//  - file_trash_relations keeps where a deleted file was attached, so it can be restored from the
//    recycle bin within 30 days.
//  - backup_logs lists database + file backups.
//  - menus.user_sort: order chosen in menu management (module files keep their own sort).
return array('up' => array(
    "CREATE TABLE `file_trash_relations` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `file_id` BIGINT UNSIGNED NOT NULL,
        `biz_type` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `biz_id` BIGINT UNSIGNED NOT NULL,
        `biz_group` VARCHAR(30) CHARACTER SET ascii NULL,
        `person_id` BIGINT UNSIGNED NULL,
        `is_reference` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NULL,
        `removed_by` BIGINT UNSIGNED NULL,
        `removed_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_file` (`file_id`)
    ) {options}",

    "CREATE TABLE `backup_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `file_name` VARCHAR(120) NOT NULL,
        `kind` ENUM('manual','auto','pre_restore','pre_migrate') NOT NULL,
        `status` ENUM('running','ok','failed') NOT NULL,
        `db_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `files_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `files_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `sha256` CHAR(64) CHARACTER SET ascii NULL,
        `message` VARCHAR(500) NULL,
        `started_at` DATETIME NOT NULL,
        `finished_at` DATETIME NULL,
        `created_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_started` (`started_at`)
    ) {options}",

    "ALTER TABLE `menus` ADD COLUMN `user_sort` INT NULL AFTER `sort`",
));
