ALTER TABLE bookings
    ADD COLUMN modification_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER updated_at;

CREATE TABLE booking_modifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    changed_by_admin_id BIGINT UNSIGNED NOT NULL,
    idempotency_key_hash BINARY(32) NOT NULL,
    request_hash BINARY(32) NOT NULL,
    before_snapshot JSON NOT NULL,
    after_snapshot JSON NOT NULL,
    unchanged_deposit_amount DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'HUF',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_booking_modifications_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_modifications_admin
        FOREIGN KEY (changed_by_admin_id) REFERENCES admins(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_booking_modifications_version (booking_id, version),
    UNIQUE KEY uq_booking_modifications_idempotency (booking_id, idempotency_key_hash),
    INDEX idx_booking_modifications_created (booking_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_outbox
    ADD COLUMN deduplication_key VARCHAR(64) NOT NULL DEFAULT '' AFTER message_type,
    DROP INDEX uq_email_outbox_booking_type_recipient,
    ADD UNIQUE KEY uq_email_outbox_booking_type_recipient_dedup
        (booking_id, message_type, recipient, deduplication_key);
