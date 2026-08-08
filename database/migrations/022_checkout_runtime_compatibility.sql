-- Completa de forma repetible las piezas de entrega que el checkout consulta
-- antes de redirigir al TPV. No crea pedidos, cobros ni entradas.
-- Es segura tanto en instalaciones nuevas como en despliegues que no aplicaron
-- las migraciones históricas 006 y 008.

ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER user_agent,
  ADD COLUMN IF NOT EXISTS environment ENUM('production','sandbox') NOT NULL DEFAULT 'production' AFTER is_test,
  ADD COLUMN IF NOT EXISTS order_status ENUM('draft','pending_payment','confirmed','cancelled','expired') NOT NULL DEFAULT 'draft' AFTER environment,
  ADD COLUMN IF NOT EXISTS payment_status ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending' AFTER order_status,
  ADD COLUMN IF NOT EXISTS delivery_status ENUM('pending','generated','partially_sent','sent','failed') NOT NULL DEFAULT 'pending' AFTER payment_status,
  ADD COLUMN IF NOT EXISTS test_session_id VARCHAR(96) NULL AFTER delivery_status,
  ADD COLUMN IF NOT EXISTS test_reference VARCHAR(32) NULL AFTER test_session_id,
  ADD INDEX IF NOT EXISTS idx_ticket_orders_checkout_runtime (is_test, environment, created_at);

CREATE TABLE IF NOT EXISTS ticket_delivery_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('email','whatsapp') NOT NULL,
  status ENUM('not_configured','pending','queued','sent','delivered','read','failed') NOT NULL DEFAULT 'pending',
  recipient VARCHAR(190) NOT NULL,
  payload MEDIUMTEXT NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_delivery_logs_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  INDEX idx_ticket_delivery_logs_order (order_id, channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE ticket_delivery_logs SET status = 'not_configured' WHERE status = 'simulated';

ALTER TABLE ticket_delivery_logs
  MODIFY COLUMN status ENUM('not_configured','pending','queued','sent','delivered','read','failed') NOT NULL DEFAULT 'pending';

ALTER TABLE tickets
  ADD COLUMN IF NOT EXISTS qr_token_ciphertext VARCHAR(512) NULL AFTER qr_token_hash;
