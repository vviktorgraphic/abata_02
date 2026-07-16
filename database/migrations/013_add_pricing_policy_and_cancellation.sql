ALTER TABLE bookings
    ADD COLUMN booking_policy_accepted_at DATETIME NULL AFTER notes,
    ADD COLUMN booking_policy_version VARCHAR(100) NULL AFTER booking_policy_accepted_at,
    ADD COLUMN booking_policy_url VARCHAR(2048) NULL AFTER booking_policy_version,
    ADD COLUMN cancelled_at DATETIME NULL AFTER booking_policy_url,
    ADD COLUMN cancellation_penalty_rate DECIMAL(5,4) NULL AFTER cancelled_at,
    ADD COLUMN cancellation_penalty_amount DECIMAL(12,2) NULL AFTER cancellation_penalty_rate,
    ADD COLUMN cancellation_currency CHAR(3) NULL AFTER cancellation_penalty_amount,
    ADD COLUMN cancellation_rule_version SMALLINT UNSIGNED NULL AFTER cancellation_currency,
    ADD COLUMN cancellation_calculation_snapshot JSON NULL AFTER cancellation_rule_version,
    ADD INDEX idx_bookings_policy_version (booking_policy_version),
    ADD INDEX idx_bookings_cancelled_at (cancelled_at),
    ADD CONSTRAINT chk_booking_policy_snapshot
        CHECK ((booking_policy_accepted_at IS NULL AND booking_policy_version IS NULL AND booking_policy_url IS NULL)
            OR (booking_policy_accepted_at IS NOT NULL AND booking_policy_version IS NOT NULL AND booking_policy_url IS NOT NULL)),
    ADD CONSTRAINT chk_booking_cancellation_snapshot
        CHECK ((cancelled_at IS NULL AND cancellation_penalty_rate IS NULL AND cancellation_penalty_amount IS NULL
                AND cancellation_currency IS NULL AND cancellation_rule_version IS NULL
                AND cancellation_calculation_snapshot IS NULL)
            OR (cancelled_at IS NOT NULL AND cancellation_penalty_rate IS NOT NULL AND cancellation_penalty_amount IS NOT NULL
                AND cancellation_currency = 'HUF' AND cancellation_rule_version IS NOT NULL
                AND cancellation_calculation_snapshot IS NOT NULL)),
    ADD CONSTRAINT chk_booking_cancellation_values
        CHECK (cancellation_penalty_rate IS NULL OR (cancellation_penalty_rate >= 0 AND cancellation_penalty_rate <= 1)),
    ADD CONSTRAINT chk_booking_cancellation_amount
        CHECK (cancellation_penalty_amount IS NULL OR cancellation_penalty_amount >= 0);

ALTER TABLE pricing_rules
    DROP CHECK chk_pricing_rule_base_unit;

UPDATE pricing_rules
SET base_unit = 'per_person_per_night'
WHERE base_unit = 'person_night';

ALTER TABLE pricing_rules
    ADD COLUMN rule_type VARCHAR(32) NOT NULL DEFAULT 'base' AFTER name,
    ADD COLUMN amount DECIMAL(12,2) NULL AFTER nightly_price,
    ADD COLUMN adjustment_mode VARCHAR(16) NOT NULL DEFAULT 'fixed' AFTER amount,
    ADD COLUMN maximum_nights SMALLINT UNSIGNED NULL AFTER minimum_nights,
    ADD COLUMN applicable_weekdays JSON NULL AFTER maximum_nights,
    ADD COLUMN exemption_key VARCHAR(100) NULL AFTER applicable_weekdays,
    ADD COLUMN created_by_admin_id BIGINT UNSIGNED NULL AFTER is_active,
    ADD COLUMN updated_by_admin_id BIGINT UNSIGNED NULL AFTER created_by_admin_id,
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_by_admin_id,
    ADD CONSTRAINT chk_pricing_rule_type CHECK
        (rule_type IN ('stay_length', 'base', 'seasonal', 'weekend', 'fixed_fee', 'tourism_tax', 'exemption')),
    ADD CONSTRAINT chk_pricing_rule_base_unit CHECK
        (base_unit IN ('per_person_per_night', 'per_night', 'per_booking')),
    ADD CONSTRAINT chk_pricing_rule_adjustment_mode CHECK (adjustment_mode IN ('fixed', 'percent')),
    ADD CONSTRAINT chk_pricing_rule_amount_non_negative CHECK (amount IS NULL OR amount >= 0),
    ADD CONSTRAINT chk_pricing_rule_night_range CHECK (maximum_nights IS NULL OR maximum_nights >= minimum_nights),
    ADD CONSTRAINT fk_pricing_rule_created_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_pricing_rule_updated_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    ADD INDEX idx_pricing_rules_active_type_dates_priority
        (is_active, rule_type, valid_from, valid_until, priority),
    ADD INDEX idx_pricing_rules_deleted (deleted_at);

UPDATE pricing_rules
SET amount = nightly_price
WHERE amount IS NULL;
