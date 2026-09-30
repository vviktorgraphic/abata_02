ALTER TABLE external_calendar_events
    ADD COLUMN missing_since DATETIME NULL,
    ADD INDEX idx_external_events_reconciliation (calendar_source_id, status, missing_since);

ALTER TABLE calendar_sync_logs
    ADD COLUMN updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN inactive_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN grace_inactive_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN recovered_run_count INT UNSIGNED NOT NULL DEFAULT 0;
