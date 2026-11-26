<?php
// Holidays and make-up workdays for the whole unit (系统设置 → 节假日与调休), and leave of each person
// (审核与统计 → 请假登记). The work calendar uses both: reminders skip holidays, statistics expect no
// report on a holiday or on a day the person is on leave.
return array('up' => array(
    "CREATE TABLE `holidays` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `name` VARCHAR(50) NOT NULL,
        `kind` ENUM('off','work') NOT NULL DEFAULT 'off',
        `start_date` DATE NOT NULL,
        `end_date` DATE NOT NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_dates` (`start_date`, `end_date`)
    ) {options}",

    "CREATE TABLE `person_leaves` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `person_id` BIGINT UNSIGNED NOT NULL,
        `kind` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `start_date` DATE NOT NULL,
        `end_date` DATE NOT NULL,
        `note` VARCHAR(200) NULL,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_person` (`person_id`, `start_date`),
        KEY `idx_dates` (`start_date`, `end_date`)
    ) {options}",
));
