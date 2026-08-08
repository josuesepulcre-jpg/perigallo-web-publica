-- Entrega privada de facturas nominales creadas en Holded.
-- Ejecutar una sola vez después de 020_holded_fiscal_sync.sql.

ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS holded_invoice_delivery_status ENUM('not_required','pending','sent','failed') NOT NULL DEFAULT 'not_required' AFTER holded_pdf_available,
  ADD COLUMN IF NOT EXISTS holded_invoice_delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER holded_invoice_delivery_status,
  ADD COLUMN IF NOT EXISTS holded_invoice_delivery_sent_at DATETIME NULL AFTER holded_invoice_delivery_attempts,
  ADD COLUMN IF NOT EXISTS holded_invoice_delivery_next_attempt_at DATETIME NULL AFTER holded_invoice_delivery_sent_at,
  ADD COLUMN IF NOT EXISTS holded_invoice_delivery_last_error VARCHAR(500) NULL AFTER holded_invoice_delivery_next_attempt_at,
  ADD INDEX IF NOT EXISTS idx_ticket_orders_holded_invoice_delivery (holded_invoice_delivery_status, holded_invoice_delivery_next_attempt_at);
