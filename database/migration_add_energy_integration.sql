-- LGU Energy Efficiency integration: manual meter readings pushed to the
-- Energy system and engineer-approved recommendations pulled back.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE.

USE facilities_reservation;

CREATE TABLE IF NOT EXISTS energy_facility_map (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    energy_facility_id INT UNSIGNED NOT NULL,
    energy_facility_name VARCHAR(150) NOT NULL DEFAULT '',
    mapped_by INT UNSIGNED NULL,
    mapped_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_map_facility (facility_id),
    CONSTRAINT fk_energy_map_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS energy_meter_readings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    reading_date DATE NOT NULL,
    previous_reading_kwh DECIMAL(14,2) NOT NULL,
    current_reading_kwh DECIMAL(14,2) NOT NULL,
    consumption_kwh DECIMAL(14,2) NOT NULL,
    notes TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    sync_status ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
    synced_at DATETIME NULL,
    sync_error TEXT NULL,
    external_record_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_reading_period (facility_id, year, month),
    KEY idx_energy_readings_sync (sync_status),
    CONSTRAINT fk_energy_reading_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS energy_recommendations_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_recommendation_id INT UNSIGNED NOT NULL,
    energy_facility_id INT UNSIGNED NOT NULL,
    facility_id INT UNSIGNED NULL,
    year SMALLINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    generated_message TEXT NOT NULL,
    engineer_recommendation TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'approved',
    expected_savings_kwh DECIMAL(14,2) NULL,
    target_date DATE NULL,
    reviewed_at DATETIME NULL,
    fetched_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_reco_remote (energy_recommendation_id),
    KEY idx_energy_reco_facility (facility_id),
    CONSTRAINT fk_energy_reco_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS energy_sync_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    last_pull_at DATETIME NULL,
    last_push_at DATETIME NULL,
    last_summary TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO energy_sync_state (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO role_permissions (role, permission_key, can_create, can_read, can_update, can_delete) VALUES
('Admin', 'energy', 1, 1, 1, 1),
('Staff', 'energy', 1, 1, 1, 0),
('Resident', 'energy', 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE
    can_create = VALUES(can_create),
    can_read = VALUES(can_read),
    can_update = VALUES(can_update),
    can_delete = VALUES(can_delete);
