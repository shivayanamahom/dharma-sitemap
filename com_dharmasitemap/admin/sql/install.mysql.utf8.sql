CREATE TABLE IF NOT EXISTS `#__dharmasitemap_state` (
  `id` tinyint unsigned NOT NULL,
  `active_build_id` bigint unsigned NULL,
  `lock_token` char(36) NULL,
  `lock_until` datetime NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `#__dharmasitemap_state` (`id`, `active_build_id`) VALUES (1, NULL);

CREATE TABLE IF NOT EXISTS `#__dharmasitemap_builds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(16) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `url_count` int unsigned NOT NULL DEFAULT 0,
  `duplicate_count` int unsigned NOT NULL DEFAULT 0,
  `report` mediumtext NULL,
  `error` varchar(1000) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_started` (`status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__dharmasitemap_urls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `build_id` bigint unsigned NOT NULL,
  `url_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `url` text NOT NULL,
  `source_id` varchar(100) NOT NULL,
  `identity_key` varchar(190) NOT NULL,
  `lastmod` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_build_url` (`build_id`, `url_hash`),
  UNIQUE KEY `uq_build_identity` (`build_id`, `identity_key`),
  KEY `idx_build_id` (`build_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
