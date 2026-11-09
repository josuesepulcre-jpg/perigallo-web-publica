-- Reservas internas: metadatos sobre el pedido y el inventario transaccional existentes.
-- No confirma cobros, no emite entradas y no envía comunicaciones.
ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS internal_reservation_event_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS reservation_operation_key VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS reservation_request_hash CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS reservation_payment_requested_at DATETIME NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_internal_reservation_operation (reservation_operation_key),
  ADD INDEX IF NOT EXISTS idx_internal_reservation_event (internal_reservation_event_id, id);
