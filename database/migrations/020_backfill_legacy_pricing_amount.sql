-- Repair pricing rows created by older development/maintenance writers after migration 013.
-- Runtime hydration retains the same fallback for installations that apply code before migrations.
UPDATE pricing_rules
SET amount = nightly_price
WHERE amount IS NULL;
