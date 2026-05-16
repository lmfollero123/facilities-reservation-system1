-- Migration: Reservation attendance (Time In / Time Out) with proof uploads

CREATE TABLE IF NOT EXISTS reservation_attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    time_in_at DATETIME NULL,
    time_in_proof_path VARCHAR(255) NULL,
    time_out_at DATETIME NULL,
    time_out_proof_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance_reservation (reservation_id),
    INDEX idx_attendance_user (user_id, created_at),
    CONSTRAINT fk_attendance_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

