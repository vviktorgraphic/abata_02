ALTER TABLE occupancy_date_overrides
    ADD COLUMN min_nights SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER end_date,
    ADD COLUMN max_nights SMALLINT UNSIGNED NULL AFTER min_nights,
    ADD CONSTRAINT chk_occupancy_override_stay_limits
        CHECK (
            min_nights BETWEEN 1 AND 30
            AND (max_nights IS NULL OR (max_nights BETWEEN min_nights AND 30))
        );
