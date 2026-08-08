-- Preparación de facturación Holded. No crea documentos ni altera pagos existentes.
-- Ejecutar una sola vez después de 019_public_lead_form.sql.

ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS billing_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER user_agent,
  ADD COLUMN IF NOT EXISTS billing_name VARCHAR(255) NULL AFTER billing_requested,
  ADD COLUMN IF NOT EXISTS billing_tax_id VARCHAR(64) NULL AFTER billing_name,
  ADD COLUMN IF NOT EXISTS billing_address VARCHAR(255) NULL AFTER billing_tax_id,
  ADD COLUMN IF NOT EXISTS billing_postal_code VARCHAR(24) NULL AFTER billing_address,
  ADD COLUMN IF NOT EXISTS billing_city VARCHAR(120) NULL AFTER billing_postal_code,
  ADD COLUMN IF NOT EXISTS billing_province VARCHAR(120) NULL AFTER billing_city,
  ADD COLUMN IF NOT EXISTS billing_country CHAR(2) NULL AFTER billing_province,
  ADD COLUMN IF NOT EXISTS billing_email VARCHAR(190) NULL AFTER billing_country,
  ADD COLUMN IF NOT EXISTS holded_status ENUM('not_required','pending','processing','synced','error','requires_review') NOT NULL DEFAULT 'not_required' AFTER billing_email,
  ADD COLUMN IF NOT EXISTS holded_document_type ENUM('invoice','salesreceipt','creditnote') NULL AFTER holded_status,
  ADD COLUMN IF NOT EXISTS holded_contact_id VARCHAR(80) NULL AFTER holded_document_type,
  ADD COLUMN IF NOT EXISTS holded_document_id VARCHAR(80) NULL AFTER holded_contact_id,
  ADD COLUMN IF NOT EXISTS holded_document_number VARCHAR(100) NULL AFTER holded_document_id,
  ADD COLUMN IF NOT EXISTS holded_payment_id VARCHAR(80) NULL AFTER holded_document_number,
  ADD COLUMN IF NOT EXISTS holded_pdf_available TINYINT(1) NOT NULL DEFAULT 0 AFTER holded_payment_id,
  ADD COLUMN IF NOT EXISTS holded_sync_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER holded_pdf_available,
  ADD COLUMN IF NOT EXISTS holded_last_attempt_at DATETIME NULL AFTER holded_sync_attempts,
  ADD COLUMN IF NOT EXISTS holded_next_attempt_at DATETIME NULL AFTER holded_last_attempt_at,
  ADD COLUMN IF NOT EXISTS holded_synced_at DATETIME NULL AFTER holded_next_attempt_at,
  ADD COLUMN IF NOT EXISTS holded_last_error VARCHAR(500) NULL AFTER holded_synced_at,
  ADD COLUMN IF NOT EXISTS holded_refund_document_id VARCHAR(80) NULL AFTER holded_last_error,
  ADD INDEX IF NOT EXISTS idx_ticket_orders_holded_queue (holded_status, holded_next_attempt_at),
  ADD INDEX IF NOT EXISTS idx_ticket_orders_holded_document (holded_document_id);

CREATE TABLE IF NOT EXISTS holded_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holded_contact_id VARCHAR(80) NOT NULL,
  tax_id_hash CHAR(64) NULL,
  email_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_holded_contacts_tax_hash (tax_id_hash),
  UNIQUE KEY uq_holded_contacts_email_hash (email_hash),
  UNIQUE KEY uq_holded_contacts_external (holded_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holded_sync_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  operation VARCHAR(48) NOT NULL,
  status ENUM('planned','started','succeeded','retry_scheduled','requires_review','failed') NOT NULL,
  attempt INT UNSIGNED NOT NULL DEFAULT 0,
  http_status SMALLINT UNSIGNED NULL,
  external_id VARCHAR(80) NULL,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_holded_sync_logs_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  INDEX idx_holded_sync_logs_order (order_id, created_at),
  INDEX idx_holded_sync_logs_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelo preparado para abonos futuros; no ejecuta reembolsos ni notas rectificativas automáticamente.
CREATE TABLE IF NOT EXISTS holded_refund_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('pending','requires_review','synced','cancelled') NOT NULL DEFAULT 'pending',
  holded_credit_note_id VARCHAR(80) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_holded_refund_requests_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  INDEX idx_holded_refund_requests_order (order_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
