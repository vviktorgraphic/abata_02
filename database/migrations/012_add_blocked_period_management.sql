ALTER TABLE blocked_periods
    ADD COLUMN internal_note VARCHAR(500) NULL AFTER reason,
    ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER internal_note,
    ADD COLUMN created_by_admin_id BIGINT UNSIGNED NULL AFTER is_active,
    ADD COLUMN removed_by_admin_id BIGINT UNSIGNED NULL AFTER created_by_admin_id,
    ADD COLUMN removed_at DATETIME NULL AFTER removed_by_admin_id,
    ADD CONSTRAINT fk_blocked_period_created_admin
        FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_blocked_period_removed_admin
        FOREIGN KEY (removed_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    ADD INDEX idx_blocked_period_active_dates (is_active, start_date, end_date);
