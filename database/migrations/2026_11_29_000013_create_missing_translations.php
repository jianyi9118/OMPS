<?php
// Translations asked for but not found at run time (design §16 "最后保护"): kept so the super
// administrator sees them in the system status and in 多语言管理; each new one notifies them once.
return array('up' => array(
    "CREATE TABLE `missing_translations` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `lang` VARCHAR(20) CHARACTER SET ascii NOT NULL,
        `tkey` VARCHAR(190) NOT NULL,
        `hits` INT UNSIGNED NOT NULL DEFAULT 1,
        `first_seen` DATETIME NOT NULL,
        `last_seen` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_lang_key` (`lang`, `tkey`)
    ) {options}",
));
