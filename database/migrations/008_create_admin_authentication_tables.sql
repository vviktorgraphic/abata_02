CREATE TABLE admin_login_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    invalidated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_login_codes_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    CONSTRAINT chk_admin_login_codes_attempt_count CHECK (attempt_count <= 5),
    CONSTRAINT chk_admin_login_codes_expiry CHECK (expires_at > sent_at),
    INDEX idx_admin_login_codes_active (admin_id, used_at, invalidated_at, expires_at),
    INDEX idx_admin_login_codes_sent (admin_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    session_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    auth_level VARCHAR(32) NOT NULL DEFAULT 'two_factor_pending',
    created_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL COMMENT 'Sliding idle expiry; refreshed on authenticated activity',
    revoked_at DATETIME NULL,
    CONSTRAINT fk_admin_sessions_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    CONSTRAINT uq_admin_sessions_token_hash UNIQUE (session_token_hash),
    CONSTRAINT chk_admin_sessions_auth_level
        CHECK (auth_level IN ('two_factor_pending', 'authenticated')),
    CONSTRAINT chk_admin_sessions_expiry CHECK (expires_at > created_at),
    INDEX idx_admin_sessions_admin_active (admin_id, revoked_at, expires_at),
    INDEX idx_admin_sessions_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    target_type VARCHAR(100) NULL,
    target_id VARCHAR(190) NULL,
    outcome VARCHAR(32) NOT NULL,
    correlation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    user_agent VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_audit_logs_event_created (event_type, created_at),
    INDEX idx_audit_logs_admin_created (admin_id, created_at),
    INDEX idx_audit_logs_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_type VARCHAR(100) NOT NULL,
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempted_at DATETIME NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    locked_until DATETIME NULL,
    INDEX idx_login_attempts_bucket_window (bucket_type, bucket_hash, attempted_at),
    INDEX idx_login_attempts_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
