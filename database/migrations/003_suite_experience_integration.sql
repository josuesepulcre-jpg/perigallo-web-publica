-- Fuente única de verdad para POP-UP: Perigallo.com conserva experiencias,
-- entradas, inventario, pedidos, pagos y QR. Suite se conecta por API privada.
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS canonical_id CHAR(36) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS event_type VARCHAR(64) NOT NULL DEFAULT 'perigallo_experience' AFTER category,
  ADD COLUMN IF NOT EXISTS origin_app VARCHAR(32) NOT NULL DEFAULT 'perigallo_web' AFTER event_type,
  ADD COLUMN IF NOT EXISTS source_updated_at DATETIME NULL AFTER origin_app;

UPDATE events
SET canonical_id = UUID()
WHERE canonical_id IS NULL OR canonical_id = '';

ALTER TABLE events
  MODIFY COLUMN canonical_id CHAR(36) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_events_canonical_id ON events(canonical_id);
CREATE INDEX IF NOT EXISTS idx_events_event_type_updated ON events(event_type, updated_at);

CREATE TABLE IF NOT EXISTS experience_sync_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  canonical_id CHAR(36) NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  ticket_type_id BIGINT UNSIGNED NULL,
  source_app VARCHAR(32) NOT NULL,
  destination_app VARCHAR(32) NOT NULL,
  action VARCHAR(64) NOT NULL,
  status ENUM('success','failed') NOT NULL,
  idempotency_key VARCHAR(128) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_experience_sync_log_idempotency (idempotency_key),
  INDEX idx_experience_sync_logs_event (event_id),
  INDEX idx_experience_sync_logs_canonical (canonical_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
