CREATE TABLE person_pricing_configuration (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    pricing_mode VARCHAR(16) NOT NULL DEFAULT 'legacy',
    adult_weekday_price DECIMAL(12,2) NULL,
    adult_weekend_price DECIMAL(12,2) NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_person_pricing_singleton CHECK (id = 1),
    CONSTRAINT chk_person_pricing_mode CHECK (pricing_mode IN ('legacy', 'person')),
    CONSTRAINT chk_person_weekday_whole CHECK (adult_weekday_price IS NULL OR (adult_weekday_price >= 0 AND adult_weekday_price = FLOOR(adult_weekday_price))),
    CONSTRAINT chk_person_weekend_whole CHECK (adult_weekend_price IS NULL OR (adult_weekend_price >= 0 AND adult_weekend_price = FLOOR(adult_weekend_price))),
    CONSTRAINT fk_person_pricing_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO person_pricing_configuration (id) VALUES (1);

CREATE TABLE pricing_child_bands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    min_age TINYINT UNSIGNED NOT NULL,
    max_age TINYINT UNSIGNED NOT NULL,
    weekday_price DECIMAL(12,2) NOT NULL,
    weekend_price DECIMAL(12,2) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT chk_child_band_ages CHECK (min_age <= max_age AND max_age <= 17),
    CONSTRAINT chk_child_weekday_whole CHECK (weekday_price >= 0 AND weekday_price = FLOOR(weekday_price)),
    CONSTRAINT chk_child_weekend_whole CHECK (weekend_price >= 0 AND weekend_price = FLOOR(weekend_price))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repository writes hold the singleton row lock and update bands plus coverage in
-- one transaction. The age primary key independently rejects overlapping coverage.
CREATE TABLE pricing_child_age_coverage (
    age TINYINT UNSIGNED PRIMARY KEY,
    band_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT chk_child_coverage_age CHECK (age <= 17),
    CONSTRAINT fk_child_coverage_band FOREIGN KEY (band_id) REFERENCES pricing_child_bands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
