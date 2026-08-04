-- Presencia y reentrada de asistentes. Ejecutar una única vez después de 008.
-- MariaDB 10.11 (Plesk) admite IF NOT EXISTS para que el despliegue sea repetible.

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS allow_reentry TINYINT(1) NOT NULL DEFAULT 1 AFTER capacity,
  ADD COLUMN IF NOT EXISTS maximum_reentries INT UNSIGNED NULL AFTER allow_reentry,
  ADD COLUMN IF NOT EXISTS reentry_until DATETIME NULL AFTER maximum_reentries,
  ADD COLUMN IF NOT EXISTS require_manual_confirmation_for_reentry TINYINT(1) NOT NULL DEFAULT 1 AFTER reentry_until;

ALTER TABLE tickets
  ADD COLUMN IF NOT EXISTS access_status ENUM('not_entered','inside','outside') NOT NULL DEFAULT 'not_entered' AFTER status,
  ADD COLUMN IF NOT EXISTS first_entry_at DATETIME NULL AFTER used_at,
  ADD COLUMN IF NOT EXISTS last_entry_at DATETIME NULL AFTER first_entry_at,
  ADD COLUMN IF NOT EXISTS last_exit_at DATETIME NULL AFTER last_entry_at,
  ADD COLUMN IF NOT EXISTS entry_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_exit_at,
  ADD COLUMN IF NOT EXISTS exit_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER entry_count,
  ADD COLUMN IF NOT EXISTS last_access_action ENUM('entry','exit','reentry','reversal') NULL AFTER exit_count,
  ADD COLUMN IF NOT EXISTS last_access_by VARCHAR(190) NULL AFTER last_access_action,
  ADD INDEX IF NOT EXISTS idx_tickets_event_access_status (event_id, access_status);

ALTER TABLE ticket_scans
  MODIFY COLUMN result ENUM('valida','ya_utilizada','cancelada','reembolsada','bloqueada','inexistente','otro_evento','revertida','sin_acceder','dentro','fuera','reentrada_no_permitida') NOT NULL;

-- Las validaciones anteriores equivalen a personas dentro. A partir de aquí
-- `status` vuelve a expresar el derecho administrativo de la entrada.
UPDATE tickets
SET access_status = 'inside',
    first_entry_at = COALESCE(first_entry_at, used_at),
    last_entry_at = COALESCE(last_entry_at, used_at),
    entry_count = GREATEST(entry_count, 1),
    last_access_action = COALESCE(last_access_action, 'entry')
WHERE status = 'used';

UPDATE tickets SET status = 'issued' WHERE status = 'used';

CREATE TABLE IF NOT EXISTS ticket_access_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  action ENUM('entry','exit','reentry','reversal') NOT NULL,
  previous_access_status ENUM('not_entered','inside','outside') NOT NULL,
  new_access_status ENUM('not_entered','inside','outside') NOT NULL,
  method ENUM('qr','manual') NOT NULL DEFAULT 'qr',
  performed_by VARCHAR(190) NULL,
  device_reference VARCHAR(190) NULL,
  notes VARCHAR(500) NULL,
  reversal_of_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_ticket_access_movements_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_access_movements_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_access_movements_reversal FOREIGN KEY (reversal_of_id) REFERENCES ticket_access_movements(id) ON DELETE RESTRICT,
  INDEX idx_ticket_access_movements_event (event_id, created_at),
  INDEX idx_ticket_access_movements_ticket (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conserva una trazabilidad mínima de los accesos registrados por la versión
-- anterior, que solo guardaba `tickets.status = used` y `used_at`.
INSERT INTO ticket_access_movements (
  ticket_id, event_id, action, previous_access_status, new_access_status,
  method, performed_by, device_reference, notes, reversal_of_id, created_at
)
SELECT
  t.id, t.event_id, 'entry', 'not_entered', 'inside', 'manual',
  t.last_access_by, NULL, 'Migrado desde validación anterior', NULL,
  COALESCE(t.first_entry_at, t.used_at, NOW())
FROM tickets t
WHERE t.access_status = 'inside'
  AND t.entry_count > 0
  AND NOT EXISTS (
    SELECT 1 FROM ticket_access_movements m WHERE m.ticket_id = t.id
  );
