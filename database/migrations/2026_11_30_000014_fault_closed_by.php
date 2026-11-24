<?php
// Who fixed or cancelled a repair request (待办事项 history, the reporter's view). Requests closed
// before this change take it from the operation log (same device, within a few seconds).
// Notifications sent to reporters pointed at the workbench, which members never see: they now
// open the request on 故障与维修, which reporters may view.
return array('up' => array(
    "ALTER TABLE `device_faults` ADD COLUMN `closed_by` BIGINT UNSIGNED NULL AFTER `closed_at`, ADD KEY `idx_closed_by` (`closed_by`)",
    "UPDATE `device_faults` f JOIN `operation_logs` o ON o.`target_type` = 'device' AND o.`target_id` = f.`device_id`
        AND o.`action` = IF(f.`status` = 'cancelled', 'device_fault_cancelled', 'device_repair_finished')
        AND o.`created_at` BETWEEN DATE_SUB(f.`closed_at`, INTERVAL 5 SECOND) AND DATE_ADD(f.`closed_at`, INTERVAL 5 SECOND)
        SET f.`closed_by` = o.`user_id` WHERE f.`status` IN ('fixed', 'cancelled') AND f.`closed_by` IS NULL",
    "UPDATE `notifications` SET `link` = CONCAT('/device/repair?id=', `biz_id`)
        WHERE `type` IN ('device_fault_fixed', 'device_fault_cancelled') AND `link` = '/?repairs=1' AND `biz_id` IS NOT NULL",
));
