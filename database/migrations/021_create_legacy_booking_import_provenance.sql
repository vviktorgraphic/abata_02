-- Legacy imports are historical data migrations, not public booking requests.
-- Keep batch accounting separate from row-level provenance so a re-upload can
-- be previewed and reported without retaining the source CSV itself.
CREATE TABLE legacy_booking_import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id CHAR(36) NOT NULL,
    source_system VARCHAR(64) NOT NULL,
    imported_by_admin_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'preview',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_legacy_import_batch_id UNIQUE (batch_id),
    CONSTRAINT fk_legacy_import_batch_admin FOREIGN KEY (imported_by_admin_id)
        REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT chk_legacy_import_batch_status
        CHECK (status IN ('preview', 'committed', 'failed')),
    INDEX idx_legacy_import_batches_source_created (source_system, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_booking_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_system VARCHAR(64) NOT NULL,
    source_booking_id VARCHAR(128) NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    source_status VARCHAR(32) NOT NULL,
    mapped_status VARCHAR(32) NOT NULL,
    source_calendar_id VARCHAR(128) NULL,
    source_calendar_name VARCHAR(190) NULL,
    source_created_date DATE NULL,
    source_data_hash BINARY(32) NOT NULL,
    source_privacy_evidence_present BOOLEAN NOT NULL DEFAULT FALSE,
    source_booking_policy_evidence_present BOOLEAN NOT NULL DEFAULT FALSE,
    source_house_rules_evidence_present BOOLEAN NOT NULL DEFAULT FALSE,
    pricing_unavailable BOOLEAN NOT NULL DEFAULT TRUE,
    imported_by_admin_id BIGINT UNSIGNED NULL,
    imported_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_legacy_import_source_booking UNIQUE (source_system, source_booking_id),
    CONSTRAINT uq_legacy_import_booking UNIQUE (booking_id),
    CONSTRAINT fk_legacy_import_batch FOREIGN KEY (import_batch_id)
        REFERENCES legacy_booking_import_batches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_legacy_import_booking FOREIGN KEY (booking_id)
        REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_legacy_import_admin FOREIGN KEY (imported_by_admin_id)
        REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT chk_legacy_import_status
        CHECK (mapped_status IN ('pending', 'confirmed', 'rejected', 'cancelled', 'invalidated')),
    INDEX idx_legacy_imports_booking (booking_id),
    INDEX idx_legacy_imports_batch (import_batch_id),
    INDEX idx_legacy_imports_source_status (source_system, source_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
