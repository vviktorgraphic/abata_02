ALTER TABLE bookings
    ADD COLUMN house_rules_accepted_at DATETIME NULL AFTER booking_policy_url,
    ADD COLUMN house_rules_url VARCHAR(2048) NULL AFTER house_rules_accepted_at;
