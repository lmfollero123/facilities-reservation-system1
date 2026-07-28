-- Adds two-way recommendation implementation tracking to an existing
-- Facilities Reservation Energy integration installation.
-- Run once after deploying the matching application changes.

USE facilities_reservation;

ALTER TABLE energy_recommendations_cache
    ADD COLUMN implementation_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER target_date,
    ADD COLUMN actual_savings_kwh DECIMAL(14,2) NULL AFTER implementation_status,
    ADD COLUMN implementation_notes TEXT NULL AFTER actual_savings_kwh,
    ADD COLUMN implemented_at DATETIME NULL AFTER implementation_notes,
    ADD COLUMN verified_at DATETIME NULL AFTER implemented_at,
    ADD COLUMN implementation_updated_by INT UNSIGNED NULL AFTER verified_at,
    ADD COLUMN implementation_updated_at DATETIME NULL AFTER implementation_updated_by;
