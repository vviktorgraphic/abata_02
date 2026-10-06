ALTER TABLE admins
    ADD COLUMN receives_booking_notifications BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE email_outbox
    DROP INDEX uq_email_outbox_booking_type,
    ADD UNIQUE KEY uq_email_outbox_booking_type_recipient
        (booking_id, message_type, recipient);
