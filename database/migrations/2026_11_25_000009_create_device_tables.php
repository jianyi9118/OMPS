<?php
// Phase 6: devices (design §7.5, §14): records, holders, parts with history, licences, faults and repairs,
// collector clients with hardware snapshots and anomalies, usage records.
return array('up' => array(
    "CREATE TABLE `devices` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `asset_no` VARCHAR(50) NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `category` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `brand` VARCHAR(50) NULL,
        `model` VARCHAR(100) NULL,
        `serial_no` VARCHAR(100) NULL,
        `dept_id` BIGINT UNSIGNED NULL,
        `holder_person_id` BIGINT UNSIGNED NULL,
        `location` VARCHAR(100) NULL,
        `purchase_date` DATE NULL,
        `received_date` DATE NULL,
        `price` DECIMAL(12,2) NULL,
        `warranty_until` DATE NULL,
        `run_status` ENUM('normal','fault','repairing','disabled','scrapped') NOT NULL DEFAULT 'normal',
        `remark` VARCHAR(500) NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        `deleted_at` DATETIME NULL,
        `deleted_by` BIGINT UNSIGNED NULL,
        `version` INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_asset` (`org_id`, `asset_no`),
        KEY `idx_dept` (`dept_id`),
        KEY `idx_holder` (`holder_person_id`),
        KEY `idx_status` (`run_status`)
    ) {options}",

    // who used the device, and when (a change of holder closes the open row)
    "CREATE TABLE `device_holders` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `person_id` BIGINT UNSIGNED NULL,
        `dept_id` BIGINT UNSIGNED NULL,
        `start_date` DATE NOT NULL,
        `end_date` DATE NULL,
        `note` VARCHAR(200) NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`),
        KEY `idx_person` (`person_id`)
    ) {options}",

    // parts; replacing = close the old row (removed_on) + new row + history, nothing is overwritten
    "CREATE TABLE `device_components` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `ctype` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `model` VARCHAR(150) NULL,
        `serial_no` VARCHAR(100) NULL,
        `spec` VARCHAR(100) NULL,
        `installed_on` DATE NULL,
        `removed_on` DATE NULL,
        `source` ENUM('manual','collector') NOT NULL DEFAULT 'manual',
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`, `removed_on`)
    ) {options}",

    "CREATE TABLE `device_component_history` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `component_id` BIGINT UNSIGNED NOT NULL,
        `action` ENUM('install','replace','remove') NOT NULL,
        `replaced_component_id` BIGINT UNSIGNED NULL,
        `repair_id` BIGINT UNSIGNED NULL,
        `anomaly_id` BIGINT UNSIGNED NULL,
        `note` VARCHAR(200) NULL,
        `happened_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`)
    ) {options}",

    "CREATE TABLE `device_licenses` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `license_no` VARCHAR(200) NULL,
        `seats` INT UNSIGNED NULL,
        `expires_on` DATE NULL,
        `remark` VARCHAR(200) NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`),
        KEY `idx_expires` (`expires_on`)
    ) {options}",

    // faults: reported by anyone (报修), assigned to a repairer, fixed or cancelled
    "CREATE TABLE `device_faults` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `reported_by` BIGINT UNSIGNED NOT NULL,
        `reported_at` DATETIME NOT NULL,
        `description` TEXT NOT NULL,
        `status` ENUM('open','repairing','fixed','cancelled') NOT NULL DEFAULT 'open',
        `closed_at` DATETIME NULL,
        `cancel_reason` VARCHAR(200) NULL,
        `version` INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`),
        KEY `idx_status` (`status`),
        KEY `idx_reporter` (`reported_by`)
    ) {options}",

    "CREATE TABLE `device_repairs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `fault_id` BIGINT UNSIGNED NOT NULL,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `repairer_user_id` BIGINT UNSIGNED NULL,
        `repairer_name` VARCHAR(100) NULL,
        `started_at` DATETIME NOT NULL,
        `fixed_at` DATETIME NULL,
        `cost` DECIMAL(12,2) NULL,
        `result` TEXT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_fault` (`fault_id`),
        KEY `idx_device` (`device_id`),
        KEY `idx_repairer` (`repairer_user_id`)
    ) {options}",

    // collector clients: one-time registration code, then a device token (both stored as SHA-256)
    "CREATE TABLE `device_collectors` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `enroll_hash` CHAR(64) CHARACTER SET ascii NULL,
        `enroll_expires` DATETIME NULL,
        `token_hash` CHAR(64) CHARACTER SET ascii NULL,
        `status` ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
        `computer_name` VARCHAR(100) NULL,
        `os` VARCHAR(150) NULL,
        `client_version` VARCHAR(20) NULL,
        `registered_at` DATETIME NULL,
        `last_seen` DATETIME NULL,
        `last_ip` VARCHAR(45) CHARACTER SET ascii NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_token` (`token_hash`),
        UNIQUE KEY `uk_enroll` (`enroll_hash`),
        KEY `idx_device` (`device_id`)
    ) {options}",

    "CREATE TABLE `device_hw_snapshots` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `collector_id` BIGINT UNSIGNED NOT NULL,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `collected_at` DATETIME NOT NULL,
        `data` LONGTEXT NOT NULL,
        `data_hash` CHAR(64) CHARACTER SET ascii NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`, `id`)
    ) {options}",

    "CREATE TABLE `device_anomalies` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `snapshot_id` BIGINT UNSIGNED NOT NULL,
        `diff` LONGTEXT NOT NULL,
        `status` ENUM('pending','confirmed','ignored') NOT NULL DEFAULT 'pending',
        `note` VARCHAR(200) NULL,
        `created_at` DATETIME NOT NULL,
        `handled_by` BIGINT UNSIGNED NULL,
        `handled_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`),
        KEY `idx_status` (`status`)
    ) {options}",

    // usage records: registration and every check-in of the collector (time, address, computer name)
    "CREATE TABLE `device_usage_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` BIGINT UNSIGNED NOT NULL,
        `collector_id` BIGINT UNSIGNED NULL,
        `event` ENUM('register','boot','daily','manual') NOT NULL,
        `ip` VARCHAR(45) CHARACTER SET ascii NULL,
        `computer_name` VARCHAR(100) NULL,
        `changed` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_device` (`device_id`, `created_at`),
        KEY `idx_created` (`created_at`)
    ) {options}",
));
