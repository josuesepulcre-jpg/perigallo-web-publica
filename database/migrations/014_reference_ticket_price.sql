-- Valor comercial de referencia para una entrada.
-- price_cents y final_price_cents continúan siendo el único importe cobrable.

ALTER TABLE ticket_types
  ADD COLUMN IF NOT EXISTS reference_price_cents INT UNSIGNED NULL AFTER price_cents,
  ADD COLUMN IF NOT EXISTS promotional_label VARCHAR(190) NULL AFTER reference_price_cents,
  ADD COLUMN IF NOT EXISTS show_reference_price TINYINT(1) NOT NULL DEFAULT 0 AFTER promotional_label;

-- Conserva el contexto comercial de cada compra aunque el evento se edite después.
ALTER TABLE ticket_order_items
  ADD COLUMN IF NOT EXISTS reference_unit_price_cents INT UNSIGNED NULL AFTER unit_price_cents,
  ADD COLUMN IF NOT EXISTS reference_total_cents INT UNSIGNED NULL AFTER reference_unit_price_cents,
  ADD COLUMN IF NOT EXISTS promotional_label VARCHAR(190) NULL AFTER reference_total_cents,
  ADD COLUMN IF NOT EXISTS show_reference_price TINYINT(1) NOT NULL DEFAULT 0 AFTER promotional_label;

-- Configuración inaugural solicitada. No altera el precio efectivo de 58,00 €.
UPDATE ticket_types tt
JOIN events e ON e.id = tt.event_id
SET tt.reference_price_cents = 9000,
    tt.promotional_label = 'Precio especial de lanzamiento',
    tt.show_reference_price = 1,
    tt.updated_at = NOW()
WHERE e.id = 1
  AND e.title = 'La Perigalla 01'
  AND tt.name = 'Entrada general'
  AND tt.price_cents = 5800
  AND tt.reference_price_cents IS NULL;
