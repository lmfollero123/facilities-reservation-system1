-- Energy facility profile cache: read-only mirror of the Energy system's
-- per-facility EnergyProfile, pulled via GET /api/v1/cprf/facility-profiles
-- inside the existing Sync Now / cron cycle. Safe to re-run.

USE facilities_reservation;

CREATE TABLE IF NOT EXISTS energy_profile_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    electric_meter_no VARCHAR(100) NULL,
    utility_provider VARCHAR(100) NULL,
    contract_account_no VARCHAR(100) NULL,
    main_energy_source VARCHAR(100) NULL,
    backup_power VARCHAR(100) NULL,
    transformer_capacity VARCHAR(100) NULL,
    number_of_meters INT UNSIGNED NULL,
    baseline_kwh DECIMAL(14,2) NULL,
    engineer_approved TINYINT(1) NOT NULL DEFAULT 0,
    baseline_locked TINYINT(1) NOT NULL DEFAULT 0,
    baseline_source VARCHAR(100) NULL,
    energy_updated_at DATETIME NULL,
    synced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_profile_cache_facility (facility_id),
    CONSTRAINT fk_energy_profile_cache_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE energy_sync_state
    ADD COLUMN IF NOT EXISTS last_profile_pull_at DATETIME NULL AFTER last_pull_at;
