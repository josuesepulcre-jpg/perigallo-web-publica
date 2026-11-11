-- Additive: the existing orders, seats, QR codes and full-order payments remain valid.
ALTER TABLE ticket_orders
 MODIFY COLUMN sales_channel ENUM('web','cash','manual_card','reservation') NOT NULL DEFAULT 'web',
 ADD COLUMN IF NOT EXISTS reservation_revision INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS reservation_request_id VARCHAR(80) NULL,
 ADD COLUMN IF NOT EXISTS reservation_request_hash CHAR(64) NULL,
 ADD UNIQUE INDEX IF NOT EXISTS uq_reservation_request (reservation_request_id);

CREATE TABLE IF NOT EXISTS ticket_reservation_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 public_token VARCHAR(96) NOT NULL UNIQUE,
 amount_cents INT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL,
 status ENUM('pending','processing','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
 expires_at DATETIME NOT NULL,
 reservation_expiry_snapshot DATETIME NULL,
 attempt_id BIGINT UNSIGNED NULL UNIQUE,
 idempotency_key VARCHAR(80) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 created_by VARCHAR(190) NOT NULL,
 cancellation_reason VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_reservation_link_request (order_id,idempotency_key),
 INDEX idx_reservation_links_order (order_id,status),
 CONSTRAINT fk_reservation_link_order FOREIGN KEY(order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
 CONSTRAINT fk_reservation_link_attempt FOREIGN KEY(attempt_id) REFERENCES payment_attempts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_attempts
 ADD COLUMN IF NOT EXISTS reservation_link_id BIGINT UNSIGNED NULL,
 ADD UNIQUE INDEX IF NOT EXISTS uq_payment_reservation_link (reservation_link_id);

CREATE TABLE IF NOT EXISTS ticket_reservation_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('receipt','refund') NOT NULL,
 method ENUM('cash','card_present','card_online','bank_transfer','other') NOT NULL,
 amount_cents INT UNSIGNED NOT NULL,
 paid_at DATETIME NOT NULL,
 reference VARCHAR(190) NULL,
 notes VARCHAR(1000) NULL,
 actor VARCHAR(190) NOT NULL,
 idempotency_key VARCHAR(80) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 payment_attempt_id BIGINT UNSIGNED NULL UNIQUE,
 review_required TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_reservation_payment_request (order_id,idempotency_key),
 INDEX idx_reservation_payment_order (order_id,paid_at),
 CONSTRAINT fk_reservation_payment_order FOREIGN KEY(order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
 CONSTRAINT fk_reservation_payment_attempt FOREIGN KEY(payment_attempt_id) REFERENCES payment_attempts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
