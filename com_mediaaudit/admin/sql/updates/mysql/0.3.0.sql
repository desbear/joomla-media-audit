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
