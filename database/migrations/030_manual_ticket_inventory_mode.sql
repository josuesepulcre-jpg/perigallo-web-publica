-- Distingue las nuevas entradas emitidas desde el cupo manual adicional.
-- Los pedidos ya existentes se mantienen como "standard", por lo que no
-- consumen retroactivamente las plazas manuales recién configuradas.
ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS inventory_mode ENUM('standard','manual_reserve') NOT NULL DEFAULT 'standard' AFTER sales_channel,
  ADD INDEX IF NOT EXISTS idx_ticket_orders_inventory_mode (inventory_mode, created_at);
