-- Migration: Add auto-approval functionality for facility reservations
-- Run this after the base schema.sql

USE facilities_reservation;

-- Add auto-approval and capacity fields to facilities table
ALTER TABLE facilities
ADD COLUMN auto_approve BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Enable auto-approval for this facility when conditions are met',
ADD COLUMN capacity_threshold INT UNSIGNED NULL COMMENT 'Maximum expected attendees allowed for auto-approval (NULL = no limit)',
ADD COLUMN max_duration_hours DECIMAL(4,2) NULL COMMENT 'Maximum reservation duration in hours for auto-approval (NULL = no limit)';

-- Add expected attendees and commercial flag to reservations table
ALTER TABLE reservations
ADD COLUMN expected_attendees INT UNSIGNED NULL COMMENT 'Expected number of attendees',
ADD COLUMN is_commercial BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether the reservation is for commercial purposes',
ADD COLUMN auto_approved BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether this reservation was auto-approved by the system';

-- Create table for facility blackout dates (dates when facility cannot be auto-approved)
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

-- Create table to track user violations (no-shows, cancellations, policy violations)
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

-- Create indexes for performance
CREATE INDEX idx_blackout_facility_date ON facility_blackout_dates(facility_id, blackout_date);
CREATE INDEX idx_violations_user ON user_violations(user_id);
CREATE INDEX idx_violations_created ON user_violations(created_at);






