-- Entrega transaccional de entradas por email y WhatsApp.
-- Esta migración no envía mensajes: únicamente prepara consentimiento,
-- documentos protegidos, trabajos idempotentes y trazabilidad de Meta.

ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS whatsapp_phone_input VARCHAR(60) NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS whatsapp_phone_e164 VARCHAR(20) NULL AFTER whatsapp_phone_input,
  ADD COLUMN IF NOT EXISTS whatsapp_country_code CHAR(2) NULL AFTER whatsapp_phone_e164,
  ADD COLUMN IF NOT EXISTS whatsapp_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_country_code,
  ADD COLUMN IF NOT EXISTS whatsapp_consent_at DATETIME NULL AFTER whatsapp_consent,
  ADD COLUMN IF NOT EXISTS whatsapp_consent_source VARCHAR(80) NULL AFTER whatsapp_consent_at,
  ADD COLUMN IF NOT EXISTS whatsapp_consent_version VARCHAR(40) NULL AFTER whatsapp_consent_source,
  ADD INDEX IF NOT EXISTS idx_ticket_orders_whatsapp_consent (whatsapp_consent, paid_at);

CREATE TABLE IF NOT EXISTS ticket_delivery_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(40) NOT NULL DEFAULT 'tickets',
  document_version VARCHAR(24) NOT NULL DEFAULT 'v1',
  filename VARCHAR(190) NOT NULL,
  content_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
  content LONGBLOB NOT NULL,
  content_sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_delivery_documents_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ticket_delivery_documents_version (order_id, document_type, document_version),
  INDEX idx_ticket_delivery_documents_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_delivery_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('email','whatsapp') NOT NULL,
  communication_type VARCHAR(40) NOT NULL DEFAULT 'tickets',
  document_version VARCHAR(24) NOT NULL DEFAULT 'v1',
  idempotency_key VARCHAR(190) NOT NULL,
  status ENUM('queued','processing','retry','blocked','sent','failed','not_authorized') NOT NULL DEFAULT 'queued',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  available_at DATETIME NOT NULL,
  locked_at DATETIME NULL,
  locked_by VARCHAR(120) NULL,
  requested_by VARCHAR(190) NULL,
  last_error VARCHAR(1000) NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_delivery_jobs_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ticket_delivery_jobs_idempotency (idempotency_key),
  INDEX idx_ticket_delivery_jobs_due (status, available_at),
  INDEX idx_ticket_delivery_jobs_order (order_id, channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_deliveries
  ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(190) NULL AFTER order_id,
  ADD COLUMN IF NOT EXISTS provider_message_id VARCHAR(190) NULL AFTER error_message,
  ADD COLUMN IF NOT EXISTS document_version VARCHAR(24) NULL AFTER provider_message_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_email_deliveries_idempotency (idempotency_key);

ALTER TABLE ticket_delivery_logs
  ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(190) NULL AFTER order_id,
  ADD COLUMN IF NOT EXISTS provider_message_id VARCHAR(190) NULL AFTER recipient,
  ADD COLUMN IF NOT EXISTS template_name VARCHAR(190) NULL AFTER provider_message_id,
  ADD COLUMN IF NOT EXISTS template_language VARCHAR(20) NULL AFTER template_name,
  ADD COLUMN IF NOT EXISTS document_version VARCHAR(24) NULL AFTER template_language,
  ADD COLUMN IF NOT EXISTS error_code VARCHAR(80) NULL AFTER error_message,
  ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL AFTER error_code,
  ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL AFTER sent_at,
  ADD COLUMN IF NOT EXISTS read_at DATETIME NULL AFTER delivered_at,
  ADD COLUMN IF NOT EXISTS last_status_at DATETIME NULL AFTER read_at,
  ADD UNIQUE KEY IF NOT EXISTS uq_ticket_delivery_logs_idempotency (idempotency_key),
  ADD UNIQUE KEY IF NOT EXISTS uq_ticket_delivery_logs_message (provider_message_id),
  MODIFY COLUMN status ENUM('not_authorized','not_configured','pending','queued','blocked','sent','delivered','read','failed') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS ticket_delivery_status_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_log_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL,
  provider_timestamp DATETIME NULL,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(1000) NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_delivery_status_events_log FOREIGN KEY (delivery_log_id) REFERENCES ticket_delivery_logs(id) ON DELETE CASCADE,
  INDEX idx_ticket_delivery_status_events_log (delivery_log_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
