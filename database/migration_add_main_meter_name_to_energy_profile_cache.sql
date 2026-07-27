-- Adds the Energy-selected/approved Main Meter name to the CPRF profile
-- cache. Safe to re-run on existing production databases.

USE facilities_reservation;

ALTER TABLE energy_profile_cache
    ADD COLUMN IF NOT EXISTS main_meter_name VARCHAR(255) NULL AFTER facility_id;
