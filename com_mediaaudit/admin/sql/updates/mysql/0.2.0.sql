--
-- The hash of the path in lower case: pairs a reference with the file that differs by case only.
-- The index is rebuilt by the next scan, so the old rows go.
--
DELETE FROM `#__mediaaudit_files`;
DELETE FROM `#__mediaaudit_refs`;
ALTER TABLE `#__mediaaudit_files` ADD COLUMN `path_ci_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER `path_hash`;
ALTER TABLE `#__mediaaudit_files` ADD KEY `idx_path_ci_hash` (`path_ci_hash`);
ALTER TABLE `#__mediaaudit_refs` ADD COLUMN `path_ci_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER `path_hash`;
ALTER TABLE `#__mediaaudit_refs` ADD KEY `idx_path_ci_hash` (`path_ci_hash`);
