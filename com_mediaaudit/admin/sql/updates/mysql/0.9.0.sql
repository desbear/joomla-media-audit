--
-- Files of the quarantine can be deleted for good: by whom and when.
--
ALTER TABLE `#__mediaaudit_quarantine` ADD COLUMN `deleted_by` int NULL DEFAULT NULL AFTER `restored_at`;
ALTER TABLE `#__mediaaudit_quarantine` ADD COLUMN `deleted_at` datetime NULL DEFAULT NULL AFTER `deleted_by`;
ALTER TABLE `#__mediaaudit_quarantine` ADD KEY `idx_deleted_at` (`deleted_at`);
