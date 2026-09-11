-- Staff shift scheduling for facilitator auto-assignment.
-- Tables are also created at runtime by frs_ensure_staff_shift_schema()
-- (config/staff_shifts.php); this file is the reference migration.

CREATE TABLE IF NOT EXISTS staff_shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Sunday .. 6=Saturday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_shifts_staff (staff_id),
    INDEX idx_staff_shifts_day (day_of_week),
    CONSTRAINT fk_staff_shifts_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_shift_exceptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    exception_date DATE NOT NULL,
    type ENUM('off','custom') NOT NULL DEFAULT 'off',
    start_time TIME NULL,
    end_time TIME NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_staff_date (staff_id, exception_date),
    INDEX idx_shift_exc_date (exception_date),
    CONSTRAINT fk_shift_exc_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_shift_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    request_date DATE NOT NULL,
    type ENUM('off','custom') NOT NULL DEFAULT 'off',
    start_time TIME NULL,
    end_time TIME NULL,
    reason VARCHAR(255) NULL,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    decided_by INT UNSIGNED NULL,
    decided_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_shift_req_staff (staff_id),
    INDEX idx_shift_req_status (status),
    CONSTRAINT fk_shift_req_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
