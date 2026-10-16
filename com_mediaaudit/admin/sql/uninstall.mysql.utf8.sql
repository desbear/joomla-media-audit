--
-- Only the tables go. Files held in images/_mediaaudit_quarantine/ and the local copies of
-- external images stay on disk.
--
DROP TABLE IF EXISTS `#__mediaaudit_files`;
DROP TABLE IF EXISTS `#__mediaaudit_refs`;
DROP TABLE IF EXISTS `#__mediaaudit_quarantine`;
DROP TABLE IF EXISTS `#__mediaaudit_urls`;
DROP TABLE IF EXISTS `#__mediaaudit_rewrites`;
DROP TABLE IF EXISTS `#__mediaaudit_scans`;
DROP TABLE IF EXISTS `#__mediaaudit_conversions`;
