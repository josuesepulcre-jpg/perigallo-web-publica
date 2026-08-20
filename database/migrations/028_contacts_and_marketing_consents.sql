-- Base de datos central de contactos y consentimientos comerciales.
-- Los consentimientos transaccionales (p. ej. entrega de entradas) no se reutilizan.

ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS marketing_email_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_consent_version,
  ADD COLUMN IF NOT EXISTS marketing_whatsapp_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER marketing_email_consent,
  ADD COLUMN IF NOT EXISTS marketing_email_consent_version VARCHAR(64) NULL AFTER marketing_whatsapp_consent,
  ADD COLUMN IF NOT EXISTS marketing_whatsapp_consent_version VARCHAR(64) NULL AFTER marketing_email_consent_version;

CREATE TABLE IF NOT EXISTS contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(120) NULL,
  last_name VARCHAR(160) NULL,
  full_name VARCHAR(255) NOT NULL,
  email VARCHAR(190) NULL,
  email_normalized VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  phone_normalized VARCHAR(24) NULL,
  initial_source VARCHAR(80) NOT NULL,
  last_source VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_contacts_email_normalized (email_normalized),
  UNIQUE KEY uq_contacts_phone_normalized (phone_normalized),
  KEY idx_contacts_name (full_name),
  KEY idx_contacts_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_order_links (
  contact_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (contact_id, order_id),
  UNIQUE KEY uq_contact_order_order (order_id),
  CONSTRAINT fk_contact_order_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_contact_order_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_lead_links (
  contact_id BIGINT UNSIGNED NOT NULL,
  lead_request_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (contact_id, lead_request_id),
  UNIQUE KEY uq_contact_lead_request (lead_request_id),
  CONSTRAINT fk_contact_lead_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_contact_lead_request FOREIGN KEY (lead_request_id) REFERENCES lead_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_consent_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('email','whatsapp') NOT NULL,
  status ENUM('granted','revoked') NOT NULL,
  source VARCHAR(80) NOT NULL,
  consent_text_version VARCHAR(64) NULL,
  event_id BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NULL,
  recorded_by VARCHAR(190) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_contact_consent_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_contact_consent_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT fk_contact_consent_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE SET NULL,
  KEY idx_contact_consents_current (contact_id, channel, id),
  KEY idx_contact_consents_channel_status (channel, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
