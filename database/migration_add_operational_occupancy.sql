-- Operational occupancy: staff facility overrides + check-in tokens (QR)
-- Run once. If columns already exist, skip the ALTER statements.

CREATE TABLE IF NOT EXISTS facility_live_status (
    facility_id INT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('auto', 'in_use', 'vacant', 'event_ending', 'closed') NOT NULL DEFAULT 'auto',
    note VARCHAR(255) NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_facility_live_status_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_facility_live_status_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reservations
    ADD COLUMN attendance_checkin_token VARCHAR(64) NULL,
    ADD COLUMN no_show_flagged_at DATETIME NULL;

ALTER TABLE reservations
    ADD UNIQUE KEY uniq_reservation_checkin_token (attendance_checkin_token);
