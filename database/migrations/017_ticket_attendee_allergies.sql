CREATE TABLE IF NOT EXISTS ticket_attendees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  ticket_id BIGINT UNSIGNED NULL,
  ticket_sequence INT UNSIGNED NOT NULL,
  attendee_name VARCHAR(190) NOT NULL,
  has_allergies TINYINT(1) NOT NULL DEFAULT 0,
  severe_allergy TINYINT(1) NOT NULL DEFAULT 0,
  allergy_notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_attendees_item_sequence (order_item_id, ticket_sequence),
  UNIQUE KEY uq_ticket_attendees_ticket (ticket_id),
  KEY idx_ticket_attendees_order (order_id),
  KEY idx_ticket_attendees_allergies (has_allergies, severe_allergy),
  CONSTRAINT fk_ticket_attendees_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_attendees_order_item FOREIGN KEY (order_item_id) REFERENCES ticket_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_attendees_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_attendee_allergens (
  attendee_id BIGINT UNSIGNED NOT NULL,
  allergen_id VARCHAR(32) NOT NULL,
  allergen_label VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (attendee_id, allergen_id),
  KEY idx_ticket_attendee_allergens_id (allergen_id),
  CONSTRAINT fk_ticket_attendee_allergens_attendee FOREIGN KEY (attendee_id) REFERENCES ticket_attendees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
