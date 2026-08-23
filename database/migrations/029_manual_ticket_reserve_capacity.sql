-- Añade plazas adicionales para emisión manual a cada tipo de entrada.
-- El cupo de venta online y el cupo manual son independientes: el segundo
-- nunca se ofrece en la web y se emite exclusivamente desde la taquilla interna.
-- Compatibilidad: este campo antes se mostraba como "Cupo reservado para venta manual".
ALTER TABLE ticket_types
  ADD COLUMN IF NOT EXISTS manual_reserve_capacity INT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity;
