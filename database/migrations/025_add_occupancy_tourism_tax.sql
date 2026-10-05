ALTER TABLE occupancy_pricing_configuration
ADD COLUMN tourism_tax_per_person_per_night DECIMAL(12,2) NOT NULL DEFAULT 0.00;
