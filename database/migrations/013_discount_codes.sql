-- Códigos de descuento para la venta de entradas.
-- MariaDB 10.11+: esta migración es segura de ejecutar una sola vez en producción.

CREATE TABLE IF NOT EXISTS discount_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  code_normalized VARCHAR(64) NOT NULL,
  internal_description VARCHAR(500) NULL,
  discount_type ENUM('percent','fixed') NOT NULL,
  percent_basis_points INT UNSIGNED NULL,
  fixed_amount_cents INT UNSIGNED NULL,
  maximum_discount_cents INT UNSIGNED NULL,
  application_scope ENUM('order','per_ticket','ticket_types') NOT NULL DEFAULT 'order',
  event_scope ENUM('all','included','excluded') NOT NULL DEFAULT 'all',
  minimum_order_cents INT UNSIGNED NULL,
  minimum_ticket_quantity INT UNSIGNED NULL,
  maximum_discounted_ticket_quantity INT UNSIGNED NULL,
  maximum_total_uses INT UNSIGNED NULL,
  maximum_uses_per_customer INT UNSIGNED NULL,
  starts_at DATETIME NULL,
  expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  is_combinable TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(190) NULL,
  updated_by VARCHAR(190) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_discount_codes_normalized (code_normalized),
  KEY idx_discount_codes_status (is_active, is_archived, starts_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discount_code_events (
  discount_code_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (discount_code_id, event_id),
  CONSTRAINT fk_discount_code_events_code FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_discount_code_events_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discount_code_ticket_types (
  discount_code_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (discount_code_id, ticket_type_id),
  CONSTRAINT fk_discount_code_ticket_types_code FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_discount_code_ticket_types_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discount_code_usages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  discount_code_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  customer_email VARCHAR(190) NULL,
  customer_phone VARCHAR(60) NULL,
  subtotal_cents INT UNSIGNED NOT NULL,
  discount_cents INT UNSIGNED NOT NULL,
  total_cents INT UNSIGNED NOT NULL,
  status ENUM('reserved','consumed','cancelled','refunded') NOT NULL DEFAULT 'reserved',
  reservation_expires_at DATETIME NULL,
  reserved_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_discount_code_order (discount_code_id, order_id),
  KEY idx_discount_usages_code_status (discount_code_id, status, reservation_expires_at),
  KEY idx_discount_usages_customer_email (discount_code_id, customer_email),
  KEY idx_discount_usages_customer_phone (discount_code_id, customer_phone),
  CONSTRAINT fk_discount_usages_code FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_discount_usages_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_discount_usages_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS discount_code_id BIGINT UNSIGNED NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS discount_code VARCHAR(64) NULL AFTER discount_code_id,
  ADD COLUMN IF NOT EXISTS discount_type ENUM('percent','fixed') NULL AFTER discount_code,
  ADD COLUMN IF NOT EXISTS discount_value_snapshot VARCHAR(64) NULL AFTER discount_type,
  ADD COLUMN IF NOT EXISTS discount_amount_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER subtotal_cents,
  ADD COLUMN IF NOT EXISTS discount_snapshot JSON NULL AFTER discount_amount_cents,
  ADD COLUMN IF NOT EXISTS discount_applied_at DATETIME NULL AFTER discount_snapshot,
  ADD COLUMN IF NOT EXISTS discount_consumed_at DATETIME NULL AFTER discount_applied_at,
  ADD KEY IF NOT EXISTS idx_ticket_orders_discount_code (discount_code_id);
