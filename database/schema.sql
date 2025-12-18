-- Database schema for Authentication, Facilities, and Reservations
-- Run this in phpMyAdmin or MySQL before wiring login to the DB.

CREATE DATABASE IF NOT EXISTS facilities_reservation
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE facilities_reservation;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Staff', 'Resident') NOT NULL DEFAULT 'Resident',
    status ENUM('pending', 'active', 'locked') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS facilities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    base_rate VARCHAR(100) NULL,
    image_path VARCHAR(255) NULL,
    location VARCHAR(190) NULL,
    capacity VARCHAR(100) NULL,
    amenities TEXT NULL,
    rules TEXT NULL,
    status ENUM('available', 'maintenance', 'offline') NOT NULL DEFAULT 'available',
    auto_approve BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Enable auto-approval for this facility when conditions are met',
    capacity_threshold INT UNSIGNED NULL COMMENT 'Maximum expected attendees allowed for auto-approval (NULL = no limit)',
    max_duration_hours DECIMAL(4,2) NULL COMMENT 'Maximum reservation duration in hours for auto-approval (NULL = no limit)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    facility_id INT UNSIGNED NOT NULL,
    reservation_date DATE NOT NULL,
    time_slot VARCHAR(50) NOT NULL,
    purpose TEXT NOT NULL,
    status ENUM('pending', 'approved', 'denied', 'cancelled') NOT NULL DEFAULT 'pending',
    reschedule_count INT UNSIGNED NOT NULL DEFAULT 0,
    expected_attendees INT UNSIGNED NULL COMMENT 'Expected number of attendees',
    is_commercial BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether the reservation is for commercial purposes',
    auto_approved BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether this reservation was auto-approved by the system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_res_facility FOREIGN KEY (facility_id) REFERENCES facilities(id)
);

CREATE TABLE IF NOT EXISTS reservation_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'denied', 'cancelled') NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    CONSTRAINT fk_hist_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    CONSTRAINT fk_hist_user FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_audit_module ON audit_log(module);
CREATE INDEX idx_audit_created ON audit_log(created_at);
CREATE INDEX idx_audit_user ON audit_log(user_id);

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL COMMENT 'NULL = system-wide notification',
    type ENUM('booking', 'system', 'reminder') NOT NULL DEFAULT 'system',
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) NULL COMMENT 'Optional link to related page',
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_notif_user ON notifications(user_id);
CREATE INDEX idx_notif_read ON notifications(is_read);
CREATE INDEX idx_notif_created ON notifications(created_at);

-- Auto-approval support tables

CREATE TABLE IF NOT EXISTS facility_blackout_dates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    blackout_date DATE NOT NULL,
    reason VARCHAR(255) NULL COMMENT 'Reason for blackout (e.g., maintenance, special event)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL COMMENT 'Admin/Staff who created this blackout',
    CONSTRAINT fk_blackout_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_blackout_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_facility_date (facility_id, blackout_date)
);

CREATE TABLE IF NOT EXISTS user_violations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    reservation_id INT UNSIGNED NULL COMMENT 'Related reservation if applicable',
    violation_type ENUM('no_show', 'late_cancellation', 'policy_violation', 'damage', 'other') NOT NULL,
    description TEXT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL COMMENT 'Admin/Staff who recorded the violation',
    CONSTRAINT fk_violation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_violation_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
    CONSTRAINT fk_violation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_blackout_facility_date ON facility_blackout_dates(facility_id, blackout_date);
CREATE INDEX idx_violations_user ON user_violations(user_id);
CREATE INDEX idx_violations_created ON user_violations(created_at);

-- Optional sample facilities
-- INSERT INTO facilities (name, description, base_rate, status) VALUES
--   ('Community Convention Hall', 'Indoor multi-purpose hall with AV equipment.', '₱5,000 / 4 hrs', 'available'),
--   ('Municipal Sports Complex', 'Indoor/outdoor courts with seating.', '₱3,500 / session', 'available'),
--   ('People''s Park Amphitheater', 'Open-air amphitheater for programs.', '₱2,000 / day', 'available');

-- Example admin insert (you will need a real hash for the password_hash value).
-- Generate one using PHP: password_hash('your-password', PASSWORD_DEFAULT)
-- and paste the result below.
--
-- INSERT INTO users (name, email, password_hash, role, status)
-- VALUES ('System Administrator', 'admin@lgu.gov.ph', 'PASTE_HASH_HERE', 'Admin', 'active');


