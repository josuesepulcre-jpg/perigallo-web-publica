-- Clarifica el código de vestimenta de La Perigalla 01 en la información pública,
-- las entradas descargables y la operación de acceso.
UPDATE events
SET dress_code = 'TOTAL WHITE. Es obligatorio acudir vestido íntegramente de blanco. No se permitirá el acceso a quienes no cumplan este código de vestimenta.',
    updated_at = NOW()
WHERE id = 1
  AND title = 'La Perigalla 01';
