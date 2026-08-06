-- Analítica first-party de Perigallo. No almacena IP, nombre, email, teléfono ni user-agent completo.

CREATE TABLE IF NOT EXISTS analytics_visitors (
  visitor_id CHAR(36) NOT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  visit_count INT UNSIGNED NOT NULL DEFAULT 1,
  last_device VARCHAR(16) NULL,
  last_language VARCHAR(16) NULL,
  PRIMARY KEY (visitor_id),
  KEY idx_analytics_visitors_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_sessions (
  session_id CHAR(36) NOT NULL,
  visitor_id CHAR(36) NOT NULL,
  started_at DATETIME NOT NULL,
  last_activity_at DATETIME NOT NULL,
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  event_count INT UNSIGNED NOT NULL DEFAULT 0,
  device VARCHAR(16) NULL,
  language VARCHAR(16) NULL,
  source VARCHAR(80) NULL,
  medium VARCHAR(80) NULL,
  campaign VARCHAR(120) NULL,
  content VARCHAR(120) NULL,
  term VARCHAR(120) NULL,
  referrer_host VARCHAR(190) NULL,
  PRIMARY KEY (session_id),
  KEY idx_analytics_sessions_period (started_at),
  KEY idx_analytics_sessions_activity (last_activity_at),
  KEY idx_analytics_sessions_visitor (visitor_id, started_at),
  CONSTRAINT fk_analytics_sessions_visitor FOREIGN KEY (visitor_id) REFERENCES analytics_visitors(visitor_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_id CHAR(36) NOT NULL,
  session_id CHAR(36) NOT NULL,
  event_name VARCHAR(40) NOT NULL,
  page_path VARCHAR(512) NOT NULL,
  page_title VARCHAR(180) NULL,
  page_type VARCHAR(40) NULL,
  section_id VARCHAR(120) NULL,
  click_id VARCHAR(120) NULL,
  experience_slug VARCHAR(160) NULL,
  scroll_depth TINYINT UNSIGNED NULL,
  device VARCHAR(16) NULL,
  source VARCHAR(80) NULL,
  medium VARCHAR(80) NULL,
  campaign VARCHAR(120) NULL,
  referrer_host VARCHAR(190) NULL,
  occurred_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_analytics_events_period_type (occurred_at, event_name),
  KEY idx_analytics_events_path (page_path(191), occurred_at),
  KEY idx_analytics_events_session (session_id, occurred_at),
  KEY idx_analytics_events_experience (experience_slug, occurred_at),
  KEY idx_analytics_events_click (click_id, occurred_at),
  CONSTRAINT fk_analytics_events_session FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_settings (
  id TINYINT UNSIGNED NOT NULL,
  report_email VARCHAR(255) NULL,
  daily_enabled TINYINT(1) NOT NULL DEFAULT 1,
  weekly_enabled TINYINT(1) NOT NULL DEFAULT 0,
  monthly_enabled TINYINT(1) NOT NULL DEFAULT 0,
  report_hour TINYINT UNSIGNED NOT NULL DEFAULT 8,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Madrid',
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO analytics_settings (id, report_email, daily_enabled, weekly_enabled, monthly_enabled, report_hour, timezone, updated_at)
VALUES (1, NULL, 1, 0, 0, 8, 'Europe/Madrid', NOW())
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS analytics_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_type VARCHAR(16) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  recipient_email VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  summary_json MEDIUMTEXT NULL,
  error_message VARCHAR(255) NULL,
  generated_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_analytics_report_period (report_type, period_start, period_end),
  KEY idx_analytics_reports_status (status, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
