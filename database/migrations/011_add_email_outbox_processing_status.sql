ALTER TABLE email_outbox
    DROP CHECK chk_email_outbox_status;

ALTER TABLE email_outbox
    ADD CONSTRAINT chk_email_outbox_status
        CHECK (status IN ('pending', 'processing', 'sent', 'failed'));
