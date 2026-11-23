<?php
// Phase 7: custom fields (personnel, devices, plans) and statistics pages (design §7.6, §15).
// Labels, option names and page titles are stored per language in `translations`
// (namespaces cf, cfo, stat), so they follow the language like every other text.
return array('up' => array(
    "CREATE TABLE `custom_fields` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entity` ENUM('personnel','device','plan') NOT NULL,
        `field_key` VARCHAR(40) CHARACTER SET ascii NOT NULL,
        `data_type` ENUM('text','textarea','int','decimal','date','select','bool') NOT NULL,
        `is_required` TINYINT(1) NOT NULL DEFAULT 0,
        `searchable` TINYINT(1) NOT NULL DEFAULT 0,
        `statable` TINYINT(1) NOT NULL DEFAULT 0,
        `in_list` TINYINT(1) NOT NULL DEFAULT 0,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_entity_key` (`entity`, `field_key`)
    ) {options}",

    "CREATE TABLE `custom_field_options` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `field_id` BIGINT UNSIGNED NOT NULL,
        `opt_key` VARCHAR(40) CHARACTER SET ascii NOT NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_field_opt` (`field_id`, `opt_key`),
        CONSTRAINT `fk_cfo_field` FOREIGN KEY (`field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE
    ) {options}",

    // typed storage: values compare, sort and sum correctly; no table is ever altered
    "CREATE TABLE `custom_field_values` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entity` ENUM('personnel','device','plan') NOT NULL,
        `entity_id` BIGINT UNSIGNED NOT NULL,
        `field_id` BIGINT UNSIGNED NOT NULL,
        `v_string` VARCHAR(500) NULL,
        `v_text` MEDIUMTEXT NULL,
        `v_int` BIGINT NULL,
        `v_decimal` DECIMAL(18,4) NULL,
        `v_date` DATE NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_value` (`entity`, `entity_id`, `field_id`),
        KEY `idx_field_string` (`field_id`, `v_string`(100)),
        KEY `idx_field_int` (`field_id`, `v_int`),
        KEY `idx_field_date` (`field_id`, `v_date`),
        CONSTRAINT `fk_cfv_field` FOREIGN KEY (`field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE
    ) {options}",

    // statistics pages: a list of widgets, each a controlled query definition (never SQL)
    "CREATE TABLE `stat_pages` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sort` INT NOT NULL DEFAULT 0,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`)
    ) {options}",

    "CREATE TABLE `stat_widgets` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `page_id` BIGINT UNSIGNED NOT NULL,
        `chart` ENUM('bar','pie','line','table','number') NOT NULL,
        `def` TEXT NOT NULL,
        `sort` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_page` (`page_id`, `sort`),
        CONSTRAINT `fk_sw_page` FOREIGN KEY (`page_id`) REFERENCES `stat_pages` (`id`) ON DELETE CASCADE
    ) {options}",
));
