ALTER TABLE bookings
    ADD COLUMN privacy_accepted_at DATETIME NULL AFTER notes,
    ADD COLUMN privacy_policy_version VARCHAR(100) NULL AFTER privacy_accepted_at,
    ADD COLUMN privacy_policy_url VARCHAR(2048) NULL AFTER privacy_policy_version,
    ADD INDEX idx_bookings_privacy_policy_version (privacy_policy_version),
    ADD CONSTRAINT chk_booking_privacy_snapshot
        CHECK ((privacy_accepted_at IS NULL AND privacy_policy_version IS NULL AND privacy_policy_url IS NULL)
            OR (privacy_accepted_at IS NOT NULL AND privacy_policy_version IS NOT NULL AND privacy_policy_url IS NOT NULL));

-- Existing bookings intentionally retain a NULL triple: the migration must not
-- manufacture evidence that a historical guest accepted a particular document.
