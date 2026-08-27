-- Las entradas emitidas manualmente desde el backoffice pueden cobrarse con
-- tarjeta a través de un enlace seguro. El pedido conserva el cupo manual
-- hasta que Redsys confirme el pago; nunca se emite por abrir el enlace.
ALTER TABLE ticket_orders
  MODIFY COLUMN sales_channel ENUM('web','cash','manual_card') NOT NULL DEFAULT 'web',
  ADD INDEX IF NOT EXISTS idx_ticket_orders_manual_card (sales_channel, status, reservation_expires_at);
