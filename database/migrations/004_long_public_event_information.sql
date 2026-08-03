-- Permite textos legales y contenido informativo extenso sin truncados.
-- Ejecutar después de 003_suite_experience_integration.sql.
-- LONGTEXT admite hasta 4 GiB por campo y conserva el contenido existente.

ALTER TABLE events
  MODIFY COLUMN included_text LONGTEXT NULL,
  MODIFY COLUMN access_conditions LONGTEXT NULL,
  MODIFY COLUMN minor_policy LONGTEXT NULL,
  MODIFY COLUMN refund_policy LONGTEXT NULL,
  MODIFY COLUMN contact_info LONGTEXT NULL,
  MODIFY COLUMN recommendations LONGTEXT NULL,
  MODIFY COLUMN dress_code LONGTEXT NULL,
  MODIFY COLUMN accessibility_info LONGTEXT NULL;
