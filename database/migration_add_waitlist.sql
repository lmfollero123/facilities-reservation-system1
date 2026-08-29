-- Migration: Add waitlist_entries table
-- Residents can join a waitlist when a facility/date/time is fully booked;
-- when a blocking reservation is cancelled/denied/expires, the oldest
-- matching waitlist entry gets offered the freed slot.
-- This migration is idempotent - safe to run multiple times.

SET @table_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'waitlist_entries'
);

SET @sql = IF(@table_exists = 0,
    'CREATE TABLE waitlist_entries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        facility_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        reservation_date DATE NOT NULL,
        time_slot VARCHAR(50) NOT NULL,
        purpose TEXT NULL,
        status ENUM(''waiting'',''offered'',''claimed'',''expired'',''cancelled'') NOT NULL DEFAULT ''waiting'',
        offer_expires_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_waitlist_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE,
        CONSTRAINT fk_waitlist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_waitlist_slot (facility_id, reservation_date, status),
        INDEX idx_waitlist_user (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT ''Table waitlist_entries already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
