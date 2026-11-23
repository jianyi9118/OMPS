<?php
// Phase 8: Word templates and generated documents (design §7.7).
// A template is an uploaded .docx (kept in the file store, linked through file_relations) with
// {{placeholders}}; without an uploaded default the built-in layout is used.
return array('up' => array(
    "CREATE TABLE `doc_templates` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `doc_type` ENUM('report','person') NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `relation_id` BIGINT UNSIGNED NOT NULL,
        `names_ok` TEXT NULL,
        `names_unknown` TEXT NULL,
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_type` (`doc_type`, `is_default`)
    ) {options}",

    "CREATE TABLE `generated_documents` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `template_id` BIGINT UNSIGNED NULL,
        `doc_type` ENUM('report','person') NOT NULL,
        `biz_type` VARCHAR(30) CHARACTER SET ascii NOT NULL,
        `biz_id` BIGINT UNSIGNED NOT NULL,
        `format` ENUM('docx','doc') NOT NULL,
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_biz` (`biz_type`, `biz_id`),
        KEY `idx_created` (`created_at`)
    ) {options}",
));
