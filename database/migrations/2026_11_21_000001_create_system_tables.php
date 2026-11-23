<?php
// System settings, languages, translations, module registry, dictionaries.
return array('up' => array(
    "CREATE TABLE `system_settings` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `skey` VARCHAR(100) CHARACTER SET ascii NOT NULL,
        `svalue` LONGTEXT NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_org_key` (`org_id`, `skey`)
    ) {options}",

    "CREATE TABLE `languages` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `name` VARCHAR(50) NOT NULL,
        `enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) {options}",

    // Texts produced by configuration (custom role names, menu titles, dictionary items...).
    "CREATE TABLE `translations` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `namespace` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `tkey` VARCHAR(150) CHARACTER SET ascii NOT NULL,
        `lang` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `text` TEXT NOT NULL,
        `updated_at` DATETIME NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_ns_key_lang` (`namespace`, `tkey`, `lang`)
    ) {options}",

    "CREATE TABLE `module_registry` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `version` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `installed_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) {options}",

    "CREATE TABLE `dictionaries` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `name_key` VARCHAR(100) CHARACTER SET ascii NOT NULL,
        `is_builtin` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) {options}",

    "CREATE TABLE `dictionary_items` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `dict_id` BIGINT UNSIGNED NOT NULL,
        `code` VARCHAR(50) CHARACTER SET ascii NOT NULL,
        `name_key` VARCHAR(100) CHARACTER SET ascii NOT NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_dict_code` (`dict_id`, `code`),
        CONSTRAINT `fk_dictitem_dict` FOREIGN KEY (`dict_id`) REFERENCES `dictionaries` (`id`)
    ) {options}",
));
