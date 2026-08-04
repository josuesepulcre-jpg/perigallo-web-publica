-- Entrega real de entradas y QR verificable.
-- Ejecutar una sola vez despues de 007_la_perigalla_total_white_dress_code.sql.

ALTER TABLE tickets
  ADD COLUMN qr_token_ciphertext VARCHAR(512) NULL AFTER qr_token_hash,
  MODIFY COLUMN status ENUM('issued','used','cancelled','refunded','blocked') NOT NULL DEFAULT 'issued';

ALTER TABLE ticket_scans
  MODIFY COLUMN result ENUM('valida','ya_utilizada','cancelada','reembolsada','bloqueada','inexistente','otro_evento','revertida') NOT NULL,
  ADD COLUMN device_reference VARCHAR(190) NULL AFTER ip_address,
  ADD COLUMN metadata JSON NULL AFTER device_reference;

ALTER TABLE ticket_delivery_logs
  MODIFY COLUMN status ENUM('not_configured','pending','queued','sent','delivered','read','failed') NOT NULL DEFAULT 'pending';

UPDATE ticket_delivery_logs SET status = 'not_configured' WHERE status = 'simulated';
