-- Enlaces seguros y revocables para recuperar «Mis entradas».
-- Esta migración es aditiva: no modifica pedidos, QR ni enlaces ya emitidos.

CREATE TABLE IF NOT EXISTS ticket_order_access_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  purpose ENUM('recovery','manual_resend') NOT NULL DEFAULT 'recovery',
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  access_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_access_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_order_access_links_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  INDEX idx_ticket_order_access_links_order (order_id, expires_at),
  INDEX idx_ticket_order_access_links_valid (revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_access_recovery_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  requested_at DATETIME NOT NULL,
  INDEX idx_ticket_access_recovery_identifier (identifier_hash, requested_at),
  INDEX idx_ticket_access_recovery_ip (ip_hash, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
