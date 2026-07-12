-- CPRF outbound maintenance requests to CIMM (auto-created by app if missing)
CREATE TABLE IF NOT EXISTS cprf_maintenance_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    facility_name VARCHAR(255) NOT NULL DEFAULT '',
    requested_date DATE NOT NULL,
    suggested_end_date DATE NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'medium',
    risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    risk_band VARCHAR(20) NOT NULL DEFAULT 'Low',
    notes TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    cimm_reference VARCHAR(64) NULL,
    requested_by INT UNSIGNED NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cprf_maint_req_facility (facility_id),
    INDEX idx_cprf_maint_req_status (status),
    INDEX idx_cprf_maint_req_date (requested_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
