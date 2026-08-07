ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS age_requirement_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER phone,
  ADD COLUMN IF NOT EXISTS age_requirement_accepted_at DATETIME NULL AFTER age_requirement_accepted,
  ADD COLUMN IF NOT EXISTS dress_code_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER age_requirement_accepted_at,
  ADD COLUMN IF NOT EXISTS dress_code_accepted_at DATETIME NULL AFTER dress_code_accepted,
  ADD COLUMN IF NOT EXISTS dress_code_version VARCHAR(64) NULL AFTER dress_code_accepted_at,
  ADD KEY IF NOT EXISTS idx_ticket_orders_access_conditions (age_requirement_accepted, dress_code_accepted);
