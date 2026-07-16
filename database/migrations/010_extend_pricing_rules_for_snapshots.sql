ALTER TABLE pricing_rules
    ADD COLUMN base_unit VARCHAR(32) NOT NULL DEFAULT 'person_night' AFTER nightly_price,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'HUF' AFTER base_unit,
    ADD CONSTRAINT chk_pricing_rule_price_non_negative CHECK (nightly_price >= 0),
    ADD CONSTRAINT chk_pricing_rule_base_unit CHECK (base_unit IN ('person_night')),
    ADD CONSTRAINT chk_pricing_rule_currency CHECK (currency = 'HUF');
