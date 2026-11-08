-- Additive editorial storage: no change to ticketing, orders or existing events.
CREATE TABLE IF NOT EXISTS experience_archive (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NULL,
  slug VARCHAR(140) NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  draft_json LONGTEXT NOT NULL CHECK (JSON_VALID(draft_json)),
  published_json LONGTEXT NULL CHECK (published_json IS NULL OR JSON_VALID(published_json)),
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY archive_slug (slug), UNIQUE KEY archive_event (event_id),
  KEY archive_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
