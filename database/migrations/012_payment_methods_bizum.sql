-- MariaDB 10.11+: la sintaxis IF NOT EXISTS permite ejecutar esta actualización
-- con seguridad si una instalación ya tiene la columna por un despliegue previo.
-- Guarda el método elegido en cada intento; no almacena teléfono, PIN ni datos Bizum.

ALTER TABLE payment_attempts
  ADD COLUMN IF NOT EXISTS payment_method ENUM('card','bizum') NOT NULL DEFAULT 'card' AFTER signature_version,
  ADD INDEX IF NOT EXISTS idx_payment_attempts_method (payment_method, status);
