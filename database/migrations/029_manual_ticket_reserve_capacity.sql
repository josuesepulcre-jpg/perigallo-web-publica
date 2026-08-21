-- Reserva una parte del aforo de cada tipo de entrada para la taquilla interna.
-- La reserva reduce exclusivamente la disponibilidad online; nunca aumenta el
-- aforo total ni permite emitir más entradas de las plazas físicas disponibles.
ALTER TABLE ticket_types
  ADD COLUMN IF NOT EXISTS manual_reserve_capacity INT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity;
