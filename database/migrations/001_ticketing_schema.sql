CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(140) NOT NULL UNIQUE,
  title VARCHAR(190) NOT NULL,
  subtitle VARCHAR(190) NULL,
  description TEXT NOT NULL,
  image_url VARCHAR(500) NULL,
  location VARCHAR(190) NOT NULL,
  address VARCHAR(255) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  sale_starts_at DATETIME NOT NULL,
  sale_ends_at DATETIME NOT NULL,
  capacity INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('draft','published','sold_out','cancelled','archived') NOT NULL DEFAULT 'draft',
  visible TINYINT(1) NOT NULL DEFAULT 0,
  promoter VARCHAR(190) NOT NULL DEFAULT 'JYD Events, S.L.',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_events_public (visible, status, starts_at),
  INDEX idx_events_sale_window (sale_starts_at, sale_ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  price_cents INT UNSIGNED NOT NULL,
  capacity INT UNSIGNED NOT NULL,
  min_quantity INT UNSIGNED NOT NULL DEFAULT 1,
  max_per_order INT UNSIGNED NOT NULL DEFAULT 10,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_types_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  INDEX idx_ticket_types_event (event_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_token VARCHAR(96) NOT NULL UNIQUE,
  redsys_order VARCHAR(12) NOT NULL UNIQUE,
  first_name VARCHAR(120) NOT NULL,
  last_name VARCHAR(160) NOT NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(60) NOT NULL,
  subtotal_cents INT UNSIGNED NOT NULL,
  total_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT '978',
  status ENUM('pending','payment_processing','paid','denied','expired','cancelled','refunded','manual_review') NOT NULL DEFAULT 'pending',
  reservation_expires_at DATETIME NULL,
  paid_at DATETIME NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_ticket_orders_status (status, reservation_expires_at),
  INDEX idx_ticket_orders_email (email),
  INDEX idx_ticket_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  ticket_type_name VARCHAR(160) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price_cents INT UNSIGNED NOT NULL,
  total_cents INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_items_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_items_ticket_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE RESTRICT,
  INDEX idx_order_items_order (order_id),
  INDEX idx_order_items_ticket_type (ticket_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  redsys_order VARCHAR(12) NOT NULL UNIQUE,
  environment ENUM('test','production') NOT NULL DEFAULT 'test',
  amount_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT '978',
  signature_version VARCHAR(40) NOT NULL,
  response_code VARCHAR(8) NULL,
  authorisation_code VARCHAR(32) NULL,
  status ENUM('created','accepted','denied','invalid','manual_review') NOT NULL DEFAULT 'created',
  notification_hash CHAR(64) NULL,
  notification_received_at DATETIME NULL,
  raw_response JSON NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_payment_attempts_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  INDEX idx_payment_attempts_order (order_id),
  INDEX idx_payment_attempts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  public_code VARCHAR(64) NOT NULL UNIQUE,
  qr_token_hash CHAR(64) NOT NULL UNIQUE,
  status ENUM('issued','used','cancelled','refunded') NOT NULL DEFAULT 'issued',
  issued_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_tickets_order_item FOREIGN KEY (order_item_id) REFERENCES ticket_order_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_ticket_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE RESTRICT,
  INDEX idx_tickets_event_status (event_id, status),
  INDEX idx_tickets_ticket_type (ticket_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_scans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  scanned_code VARCHAR(120) NOT NULL,
  result ENUM('valida','ya_utilizada','cancelada','inexistente','otro_evento') NOT NULL,
  scanned_by VARCHAR(190) NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_scans_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_scans_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  INDEX idx_ticket_scans_event (event_id, created_at),
  INDEX idx_ticket_scans_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NULL,
  recipient_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_email_deliveries_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE SET NULL,
  INDEX idx_email_deliveries_order (order_id),
  INDEX idx_email_deliveries_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
