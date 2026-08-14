-- Dietas y necesidades alimentarias no alérgicas por asistente.
-- Mantiene separadas las alergias (información de seguridad) de las
-- preferencias que deben tenerse en cuenta en el servicio gastronómico.
ALTER TABLE ticket_attendees
  ADD COLUMN IF NOT EXISTS dietary_preference VARCHAR(32) NULL AFTER allergy_notes,
  ADD COLUMN IF NOT EXISTS dietary_notes VARCHAR(500) NULL AFTER dietary_preference;
