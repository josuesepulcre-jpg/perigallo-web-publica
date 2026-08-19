-- Clasifica explícitamente los eventos de prueba.
-- Solo estos eventos podrán eliminarse definitivamente desde administración.

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER visible,
  ADD INDEX IF NOT EXISTS idx_events_test_mode (is_test, status, created_at);
