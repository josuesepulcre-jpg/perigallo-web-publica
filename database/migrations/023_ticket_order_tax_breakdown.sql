-- Conserva el desglose fiscal aplicado al comprar. No debe reconstruirse desde
-- ticket_types porque el IVA, el precio o los gastos de una entrada pueden
-- cambiar después de haberse cobrado un pedido.
ALTER TABLE ticket_order_items
  ADD COLUMN IF NOT EXISTS unit_base_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_price_cents,
  ADD COLUMN IF NOT EXISTS unit_tax_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_base_cents,
  ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER unit_tax_cents,
  ADD COLUMN IF NOT EXISTS unit_fee_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_rate;

-- Los pedidos históricos no permiten reconstruir con certeza el IVA aplicado.
-- Se preserva su importe cobrado como base sin impuesto para que una eventual
-- sincronización posterior no añada IVA por segunda vez.
UPDATE ticket_order_items
SET unit_base_cents = unit_price_cents,
    unit_tax_cents = 0,
    tax_rate = 0.00,
    unit_fee_cents = 0
WHERE unit_base_cents = 0
  AND unit_tax_cents = 0
  AND tax_rate = 0.00
  AND unit_fee_cents = 0;
