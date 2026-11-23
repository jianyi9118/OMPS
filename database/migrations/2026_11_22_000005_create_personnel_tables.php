<?php
// Phase 2: full staff records, sub-records, family (sensitive), field policies, change requests, files.
return array('up' => array(
    "ALTER TABLE `personnel_profiles`
        ADD COLUMN `photo_file_id` BIGINT UNSIGNED NULL AFTER `staff_no`,
        ADD COLUMN `birth_date` DATE NULL AFTER `gender`,
        ADD COLUMN `ethnicity` VARCHAR(30) NULL AFTER `birth_date`,
        ADD COLUMN `id_type` VARCHAR(30) CHARACTER SET ascii NULL AFTER `ethnicity`,
        ADD COLUMN `id_number_enc` VARCHAR(255) CHARACTER SET ascii NULL AFTER `id_type`,
        ADD COLUMN `id_number_hash` CHAR(64) CHARACTER SET ascii NULL AFTER `id_number_enc`,
        ADD COLUMN `id_number_mask` VARCHAR(40) NULL AFTER `id_number_hash`,
        ADD COLUMN `email` VARCHAR(100) NULL AFTER `phone`,
        ADD COLUMN `address` VARCHAR(200) NULL AFTER `email`,
        ADD COLUMN `hukou_address` VARCHAR(200) NULL AFTER `address`,
        ADD COLUMN `remark` TEXT NULL AFTER `unassigned`,
        ADD KEY `idx_id_hash` (`id_number_hash`)",

    "CREATE TABLE `personnel_education` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `start_ym` CHAR(7) CHARACTER SET ascii NULL,
        `end_ym` CHAR(7) CHARACTER SET ascii NULL,
        `school` VARCHAR(100) NOT NULL,
        `country_region` VARCHAR(50) NULL,
        `major` VARCHAR(100) NULL,
        `degree_level` VARCHAR(30) CHARACTER SET ascii NULL,
        `degree` VARCHAR(30) CHARACTER SET ascii NULL,
        `graduated` TINYINT(1) NOT NULL DEFAULT 1,
        `certificate_no` VARCHAR(50) NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_person` (`person_id`)
    ) {options}",

    "CREATE TABLE `personnel_work_experience` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `start_ym` CHAR(7) CHARACTER SET ascii NULL,
        `end_ym` CHAR(7) CHARACTER SET ascii NULL,
        `employer` VARCHAR(100) NOT NULL,
        `position` VARCHAR(100) NULL,
        `duties` VARCHAR(500) NULL,
        `leave_reason` VARCHAR(200) NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_person` (`person_id`)
    ) {options}",

    "CREATE TABLE `personnel_titles` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `title` VARCHAR(100) NOT NULL,
        `duty` VARCHAR(100) NULL,
        `level` VARCHAR(50) NULL,
        `obtained_ym` CHAR(7) CHARACTER SET ascii NULL,
        `issuer` VARCHAR(100) NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_person` (`person_id`)
    ) {options}",

    "CREATE TABLE `personnel_achievements` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `type` VARCHAR(30) CHARACTER SET ascii NULL,
        `obtained_date` DATE NULL,
        `issuer` VARCHAR(100) NULL,
        `certificate_no` VARCHAR(50) NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_person` (`person_id`)
    ) {options}",

    // Highly sensitive: phone and address are encrypted.
    "CREATE TABLE `personnel_families` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `name` VARCHAR(50) NOT NULL,
        `relation` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `gender` ENUM('male','female') NULL,
        `birth_date` DATE NULL,
        `employer` VARCHAR(100) NULL,
        `position` VARCHAR(100) NULL,
        `phone_enc` VARCHAR(255) CHARACTER SET ascii NULL,
        `phone_mask` VARCHAR(40) NULL,
        `address_enc` VARCHAR(600) CHARACTER SET ascii NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_person` (`person_id`)
    ) {options}",

    // What a person may change in their own record: forbid / direct / review.
    "CREATE TABLE `profile_field_policies` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entity` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `field_key` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `self_edit` ENUM('forbid','direct','review') NOT NULL DEFAULT 'review',
        `admin_only` TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_entity_field` (`entity`, `field_key`)
    ) {options}",

    "CREATE TABLE `profile_change_requests` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `entity` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `record_id` BIGINT UNSIGNED NULL,
        `action` ENUM('create','update','delete') NOT NULL,
        `before_data` LONGTEXT NULL,
        `after_data` LONGTEXT NULL,
        `status` ENUM('pending','approved','returned','withdrawn') NOT NULL DEFAULT 'pending',
        `submitted_by` BIGINT UNSIGNED NOT NULL,
        `submitted_at` DATETIME NOT NULL,
        `reviewed_by` BIGINT UNSIGNED NULL,
        `reviewed_at` DATETIME NULL,
        `review_comment` VARCHAR(500) NULL,
        PRIMARY KEY (`id`),
        KEY `idx_person` (`person_id`),
        KEY `idx_status` (`status`)
    ) {options}",

    // Files are stored outside the web root in folders that mirror the platform (design §11).
    "CREATE TABLE `files` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `rel_path` VARCHAR(500) NOT NULL,
        `original_name` VARCHAR(200) NOT NULL,
        `ext` VARCHAR(10) CHARACTER SET ascii NOT NULL,
        `mime` VARCHAR(100) CHARACTER SET ascii NOT NULL,
        `size` BIGINT UNSIGNED NOT NULL,
        `sha256` CHAR(64) CHARACTER SET ascii NOT NULL,
        `uploaded_by` BIGINT UNSIGNED NULL,
        `uploaded_at` DATETIME NOT NULL,
        `status` ENUM('normal','trash') NOT NULL DEFAULT 'normal',
        `deleted_at` DATETIME NULL,
        `deleted_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_sha` (`sha256`),
        KEY `idx_uploader` (`uploaded_by`)
    ) {options}",

    "CREATE TABLE `file_relations` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `file_id` BIGINT UNSIGNED NOT NULL,
        `biz_type` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `biz_id` BIGINT UNSIGNED NOT NULL,
        `biz_group` VARCHAR(30) CHARACTER SET ascii NULL,
        `person_id` BIGINT UNSIGNED NULL,
        `is_reference` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_biz` (`biz_type`, `biz_id`),
        KEY `idx_file` (`file_id`)
    ) {options}",
));
