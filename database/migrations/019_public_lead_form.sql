CREATE TABLE IF NOT EXISTS lead_form_settings (
  id TINYINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  title VARCHAR(190) NOT NULL,
  subtitle VARCHAR(500) NOT NULL,
  recipient_email VARCHAR(190) NOT NULL,
  confirmation_message VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lead_form_settings (id, enabled, title, subtitle, recipient_email, confirmation_message, created_at, updated_at)
VALUES (1, 1, 'Cuéntanos vuestra historia', 'Una primera conversación para empezar a imaginar vuestra celebración.', 'hola@perigallo.com', 'Gracias. Hemos recibido vuestra solicitud y revisaremos la información con mucho cuidado antes de contactar con vosotros.', NOW(), NOW())
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS lead_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_reference VARCHAR(32) NOT NULL,
  source VARCHAR(80) NOT NULL,
  name VARCHAR(190) NOT NULL,
  partner_name VARCHAR(190) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  event_type VARCHAR(190) NOT NULL,
  event_date VARCHAR(32) NULL,
  guest_count VARCHAR(80) NULL,
  answers_json JSON NOT NULL,
  status ENUM('new','contacted','follow_up','proposal_sent','closed','discarded') NOT NULL DEFAULT 'new',
  privacy_accepted TINYINT(1) NOT NULL DEFAULT 0,
  privacy_accepted_at DATETIME NULL,
  privacy_version VARCHAR(64) NULL,
  email_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  email_error VARCHAR(500) NULL,
  email_sent_at DATETIME NULL,
  ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lead_requests_reference (public_reference),
  KEY idx_lead_requests_status (status, created_at),
  KEY idx_lead_requests_email_status (email_status, created_at),
  KEY idx_lead_requests_ip_rate (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
