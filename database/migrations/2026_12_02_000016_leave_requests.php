<?php
// Leave is applied for by the person (我的事务 → 我的请假) and approved by the leader who reviews them
// (审核与统计 → 请假). Leave recorded by a leader is approved at once. Only approved leave counts.
return array('up' => array(
    "ALTER TABLE `person_leaves`
        ADD COLUMN `status` ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'approved' AFTER `note`,
        ADD COLUMN `reviewed_by` BIGINT UNSIGNED NULL AFTER `status`,
        ADD COLUMN `reviewed_at` DATETIME NULL AFTER `reviewed_by`,
        ADD COLUMN `review_comment` VARCHAR(200) NULL AFTER `reviewed_at`,
        ADD KEY `idx_status` (`status`)",
));
