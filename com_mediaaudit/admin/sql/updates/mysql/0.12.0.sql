--
-- The images that were written as AVIF or WebP, and the ones that were tried. The image itself
-- stays where it is.
--
CREATE TABLE IF NOT EXISTS `#__mediaaudit_conversions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `source_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_sha1` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_size` bigint unsigned NOT NULL DEFAULT 0,
  `target_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
  `target_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `target_size` bigint unsigned NOT NULL DEFAULT 0,
  `format` varchar(10) NOT NULL,
  `quality` smallint NOT NULL DEFAULT 0,
  `encoder` varchar(20) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL,
  `error` varchar(255) NOT NULL DEFAULT '',
  `converted_at` datetime NOT NULL,
  `converted_by` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_source_format` (`source_hash`, `format`),
  KEY `idx_target_hash` (`target_hash`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
