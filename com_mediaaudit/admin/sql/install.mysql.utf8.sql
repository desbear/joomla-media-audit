--
-- Paths are compared byte for byte: a reference that differs only by case is a broken image on Linux.
--
CREATE TABLE IF NOT EXISTS `#__mediaaudit_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `path_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `path_ci_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `size` bigint unsigned NOT NULL DEFAULT 0,
  `mtime` datetime NULL DEFAULT NULL,
  `sha1` char(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `status` enum('active','quarantined') NOT NULL DEFAULT 'active',
  `scanned_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_path_hash` (`path_hash`),
  KEY `idx_path_ci_hash` (`path_ci_hash`),
  KEY `idx_sha1` (`sha1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mediaaudit_refs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `path_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `path_ci_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `raw` text NULL,
  `is_external` tinyint NOT NULL DEFAULT 0,
  `source_type` varchar(50) NOT NULL,
  `source_id` int NOT NULL DEFAULT 0,
  `field` varchar(100) NOT NULL DEFAULT '',
  `source_state` tinyint NULL DEFAULT NULL,
  `exists_local` tinyint NULL DEFAULT NULL,
  `http_status` smallint NULL DEFAULT NULL,
  `checked_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_path_hash` (`path_hash`),
  KEY `idx_path_ci_hash` (`path_ci_hash`),
  KEY `idx_source` (`source_type`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mediaaudit_quarantine` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `original_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `quarantine_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `sha1` char(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `moved_by` int NOT NULL DEFAULT 0,
  `moved_at` datetime NOT NULL,
  `restored_by` int NULL DEFAULT NULL,
  `restored_at` datetime NULL DEFAULT NULL,
  `deleted_by` int NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `note` text NULL,
  PRIMARY KEY (`id`),
  KEY `idx_restored_at` (`restored_at`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

--
-- What is known of an external URL. A scan does not empty this table.
--
CREATE TABLE IF NOT EXISTS `#__mediaaudit_urls` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `url` text NOT NULL,
  `url_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `host` varchar(255) NOT NULL DEFAULT '',
  `http_status` smallint NULL DEFAULT NULL,
  `error` varchar(255) NOT NULL DEFAULT '',
  `checked_at` datetime NULL DEFAULT NULL,
  `local_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
  `sha1` char(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `size` bigint unsigned NULL DEFAULT NULL,
  `mime` varchar(100) NOT NULL DEFAULT '',
  `fetched_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_url_hash` (`url_hash`),
  KEY `idx_host` (`host`),
  KEY `idx_sha1` (`sha1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

--
-- The text that a column had before its URLs were replaced by local copies.
--
CREATE TABLE IF NOT EXISTS `#__mediaaudit_rewrites` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(50) NOT NULL,
  `source_id` int NOT NULL DEFAULT 0,
  `field` varchar(100) NOT NULL DEFAULT '',
  `kind` varchar(20) NOT NULL DEFAULT 'localize',
  `original` mediumtext NOT NULL,
  `rewritten_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `replaced` int NOT NULL DEFAULT 0,
  `rewritten_by` int NOT NULL DEFAULT 0,
  `rewritten_at` datetime NOT NULL,
  `reverted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_type`, `source_id`),
  KEY `idx_reverted_at` (`reverted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

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
