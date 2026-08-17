-- Las ventas de taquilla cobradas en efectivo se conservan en Perigallo,
-- pero nunca generan documentos ni pagos en Holded.
ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS holded_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER holded_status,
  ADD COLUMN IF NOT EXISTS holded_exclusion_reason VARCHAR(120) NULL AFTER holded_excluded,
  ADD INDEX IF NOT EXISTS idx_ticket_orders_holded_excluded (holded_excluded, holded_status);

UPDATE ticket_orders
SET holded_excluded = 1,
    holded_exclusion_reason = 'cash_sale',
    holded_status = 'not_required',
    holded_next_attempt_at = NULL,
    holded_last_error = NULL,
    updated_at = NOW()
WHERE sales_channel = 'cash' AND holded_status <> 'synced';
