CREATE TABLE pricing_adult_stay_length_bands (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    min_nights SMALLINT UNSIGNED NOT NULL,
    max_nights SMALLINT UNSIGNED NULL,
    price_per_person_per_night DECIMAL(12,2) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_admin_id BIGINT UNSIGNED NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adult_stay_created_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_adult_stay_updated_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT chk_adult_stay_range CHECK (max_nights IS NULL OR max_nights >= min_nights),
    CONSTRAINT chk_adult_stay_price CHECK (price_per_person_per_night >= 0)
);
CREATE INDEX idx_adult_stay_active_range ON pricing_adult_stay_length_bands (is_active, min_nights, max_nights);
