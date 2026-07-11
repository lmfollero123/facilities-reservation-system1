-- UMAN integration: facility equipment linked to UMAN utility assets

CREATE TABLE IF NOT EXISTS facility_equipment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    uman_asset_id INT UNSIGNED NOT NULL,
    uman_asset_code VARCHAR(50) NOT NULL,
    asset_name VARCHAR(150) NOT NULL,
    asset_type VARCHAR(100) NULL,
    condition_status VARCHAR(50) NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    UNIQUE KEY uniq_facility_uman_asset (facility_id, uman_asset_id),
    KEY idx_facility_equipment_facility (facility_id),
    CONSTRAINT fk_facility_equipment_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uman_asset_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    asset_type VARCHAR(100) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    notes TEXT NULL,
    uman_request_ref VARCHAR(50) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_uman_requests_facility (facility_id),
    KEY idx_uman_requests_status (status),
    CONSTRAINT fk_uman_requests_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
