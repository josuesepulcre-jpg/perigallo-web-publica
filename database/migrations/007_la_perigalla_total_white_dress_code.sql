-- Resumen público del código de vestimenta de La Perigalla 01.
-- La explicación ampliada puede gestionarse desde el editor del evento.
UPDATE events
SET dress_code = 'TOTAL WHITE · Obligatorio ir de blanco para acceder',
    updated_at = NOW()
WHERE id = 1
  AND title = 'La Perigalla 01';
