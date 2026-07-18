CREATE TABLE calendar_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    direction VARCHAR(20) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    sync_token_hash CHAR(64) NULL,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_calendar_source_provider CHECK (provider IN ('google_calendar', 'szallas_hu')),
    CONSTRAINT chk_calendar_source_direction CHECK (direction IN ('import', 'export', 'bidirectional')),
    CONSTRAINT chk_calendar_source_token_hash CHECK (sync_token_hash IS NULL OR CHAR_LENGTH(sync_token_hash) = 64),
    INDEX idx_calendar_sources_enabled_direction (enabled, direction)
);

CREATE TABLE external_calendar_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_source_id BIGINT UNSIGNED NOT NULL,
    external_uid VARCHAR(512) NOT NULL,
    summary VARCHAR(255) NULL,
    description TEXT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    blocked_period_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'imported',
    last_seen_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_external_event_source FOREIGN KEY (calendar_source_id) REFERENCES calendar_sources(id) ON DELETE CASCADE,
    CONSTRAINT fk_external_event_blocked_period FOREIGN KEY (blocked_period_id) REFERENCES blocked_periods(id) ON DELETE SET NULL,
    CONSTRAINT uq_external_event_source_uid UNIQUE (calendar_source_id, external_uid),
    CONSTRAINT chk_external_event_dates CHECK (start_date < end_date),
    CONSTRAINT chk_external_event_payload_hash CHECK (CHAR_LENGTH(payload_hash) = 64),
    CONSTRAINT chk_external_event_status CHECK (status IN ('imported', 'blocked', 'conflict', 'removed')),
    INDEX idx_external_events_dates (start_date, end_date),
    INDEX idx_external_events_blocked_period (blocked_period_id)
);

CREATE TABLE calendar_sync_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_source_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    exported_count INT UNSIGNED NOT NULL DEFAULT 0,
    warnings_json JSON NOT NULL,
    errors_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_calendar_sync_log_source FOREIGN KEY (calendar_source_id) REFERENCES calendar_sources(id) ON DELETE CASCADE,
    CONSTRAINT chk_calendar_sync_log_status CHECK (status IN ('running', 'success', 'warning', 'failed')),
    INDEX idx_calendar_sync_logs_source_started (calendar_source_id, started_at),
    INDEX idx_calendar_sync_logs_status_started (status, started_at)
);

CREATE TABLE calendar_export_tokens (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    rotated_at DATETIME NULL,
    CONSTRAINT chk_calendar_export_token_singleton CHECK (id = 1),
    CONSTRAINT chk_calendar_export_token_hash CHECK (CHAR_LENGTH(token_hash) = 64)
);
