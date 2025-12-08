-- Migration: Add reservation_history table
-- Run this in phpMyAdmin or MySQL if the table doesn't exist

USE facilities_reservation;

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

