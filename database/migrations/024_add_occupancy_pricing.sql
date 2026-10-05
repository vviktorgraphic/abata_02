CREATE TABLE occupancy_pricing_configuration (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    one_night_surcharge DECIMAL(12,2) NOT NULL DEFAULT 8000.00,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_occupancy_config_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT chk_occupancy_surcharge CHECK (one_night_surcharge >= 0)
);
CREATE TABLE occupancy_stay_length_bands (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    guest_count TINYINT UNSIGNED NOT NULL,
    min_nights SMALLINT UNSIGNED NOT NULL,
    max_nights SMALLINT UNSIGNED NULL,
    nightly_price DECIMAL(12,2) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_admin_id BIGINT UNSIGNED NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_occupancy_band_created FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_occupancy_band_updated FOREIGN KEY (updated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT chk_occupancy_band_guests CHECK (guest_count BETWEEN 1 AND 4),
    CONSTRAINT chk_occupancy_band_range CHECK (max_nights IS NULL OR max_nights >= min_nights),
    CONSTRAINT chk_occupancy_band_price CHECK (nightly_price >= 0)
);
CREATE INDEX idx_occupancy_band_lookup ON occupancy_stay_length_bands (is_active, guest_count, min_nights, max_nights);
CREATE TABLE occupancy_date_overrides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    price_1_guest DECIMAL(12,2) NOT NULL,
    price_2_guests DECIMAL(12,2) NOT NULL,
    price_3_guests DECIMAL(12,2) NOT NULL,
    price_4_guests DECIMAL(12,2) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by_admin_id BIGINT UNSIGNED NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_occupancy_override_created FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_occupancy_override_updated FOREIGN KEY (updated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT chk_occupancy_override_range CHECK (start_date <= end_date),
    CONSTRAINT chk_occupancy_override_prices CHECK (price_1_guest >= 0 AND price_2_guests >= 0 AND price_3_guests >= 0 AND price_4_guests >= 0)
);
INSERT INTO occupancy_pricing_configuration (id, version, one_night_surcharge) VALUES (1, 1, 8000.00);
INSERT INTO occupancy_stay_length_bands (guest_count, min_nights, max_nights, nightly_price, sort_order) VALUES
 (1, 1, NULL, 22000.00, 1), (2, 1, NULL, 27000.00, 2), (3, 1, NULL, 37000.00, 3), (4, 1, NULL, 42000.00, 4);
