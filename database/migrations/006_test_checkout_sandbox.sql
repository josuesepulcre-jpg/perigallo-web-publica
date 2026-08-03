-- Pedidos de prueba aislados del aforo, la facturación y la numeración comercial.
-- Ejecutar una sola vez después de 005_configure_la_perigalla_01_publication.sql.

ALTER TABLE ticket_orders
  ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER user_agent,
  ADD COLUMN environment ENUM('production','sandbox') NOT NULL DEFAULT 'production' AFTER is_test,
  ADD COLUMN order_status ENUM('draft','pending_payment','confirmed','cancelled','expired') NOT NULL DEFAULT 'draft' AFTER environment,
  ADD COLUMN payment_status ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending' AFTER order_status,
  ADD COLUMN delivery_status ENUM('pending','generated','partially_sent','sent','failed') NOT NULL DEFAULT 'pending' AFTER payment_status,
  ADD COLUMN test_session_id VARCHAR(96) NULL AFTER delivery_status,
  ADD COLUMN test_reference VARCHAR(32) NULL AFTER test_session_id,
  ADD INDEX idx_ticket_orders_test (is_test, environment, created_at),
  ADD UNIQUE KEY uq_ticket_orders_test_session (test_session_id),
  ADD UNIQUE KEY uq_ticket_orders_test_reference (test_reference);

CREATE TABLE IF NOT EXISTS ticket_delivery_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('email','whatsapp') NOT NULL,
  status ENUM('pending','sent','simulated','failed') NOT NULL DEFAULT 'pending',
  recipient VARCHAR(190) NOT NULL,
  payload MEDIUMTEXT NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_delivery_logs_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  INDEX idx_ticket_delivery_logs_order (order_id, channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
