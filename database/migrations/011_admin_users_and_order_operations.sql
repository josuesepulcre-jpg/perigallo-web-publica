-- Gestión de usuarios del panel y auditoría de acciones sensibles.
-- Ejecutar una vez después de 010_order_access_recovery.sql.

CREATE TABLE IF NOT EXISTS ticket_admin_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','control_acceso') NOT NULL DEFAULT 'control_acceso',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_ticket_admin_users_username (username),
  INDEX idx_ticket_admin_users_active (is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_admin_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(190) NOT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  context_json JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_ticket_admin_audit_logs_entity (entity_type, entity_id, created_at),
  INDEX idx_ticket_admin_audit_logs_actor (actor, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
