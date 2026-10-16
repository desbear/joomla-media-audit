--
-- The summary of every scan, to tell what changed since the one before.
--
CREATE TABLE IF NOT EXISTS `#__mediaaudit_scans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scanned_at` datetime NOT NULL,
  `seconds` decimal(10,2) NOT NULL DEFAULT 0,
  `source` varchar(20) NOT NULL DEFAULT '',
  `summary` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
