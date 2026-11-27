ALTER TABLE ticket_orders
 ADD COLUMN IF NOT EXISTS reservation_time TIME NULL;
