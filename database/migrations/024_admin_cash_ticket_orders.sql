-- Operativa privada de taquilla: ventas y reservas en efectivo creadas desde
-- el backoffice. Los pedidos siguen usando ticket_orders para que el aforo,
-- las ventas globales y la facturación compartan la misma fuente de verdad.
ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS sales_channel ENUM('web','cash') NOT NULL DEFAULT 'web' AFTER environment,
  ADD COLUMN IF NOT EXISTS cash_payment_status ENUM('not_applicable','reserved','paid') NOT NULL DEFAULT 'not_applicable' AFTER sales_channel,
  ADD COLUMN IF NOT EXISTS cash_payment_notes VARCHAR(1000) NULL AFTER cash_payment_status,
  ADD COLUMN IF NOT EXISTS cash_payment_recorded_by VARCHAR(190) NULL AFTER cash_payment_notes,
  ADD COLUMN IF NOT EXISTS cash_payment_recorded_at DATETIME NULL AFTER cash_payment_recorded_by,
  ADD INDEX IF NOT EXISTS idx_ticket_orders_cash_status (sales_channel, cash_payment_status, created_at);
