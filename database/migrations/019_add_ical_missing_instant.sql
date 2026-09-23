-- Keep the Budapest display timestamp and an unambiguous elapsed-time instant.
-- Do not invent an instant for any historical ambiguous local missing_since.
ALTER TABLE external_calendar_events
    ADD COLUMN missing_since_timestamp BIGINT UNSIGNED NULL AFTER missing_since;
