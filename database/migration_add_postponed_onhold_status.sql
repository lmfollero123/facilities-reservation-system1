-- Migration: Add 'postponed' and 'on_hold' statuses to reservations
-- Also add postponed_priority flag for tracking priority when facility becomes available
-- This migration is idempotent - safe to run multiple times

-- Add new statuses to reservations table (safe to run multiple times)
ALTER TABLE reservations 
    MODIFY COLUMN status ENUM('pending', 'approved', 'denied', 'cancelled', 'postponed', 'on_hold') NOT NULL DEFAULT 'pending';

-- Check and add postponed_priority column if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'reservations' 
    AND COLUMN_NAME = 'postponed_priority'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE reservations ADD COLUMN postponed_priority BOOLEAN NOT NULL DEFAULT FALSE COMMENT ''True if reservation was postponed due to maintenance and should get priority when facility becomes available''',
    'SELECT ''Column postponed_priority already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add postponed_at column if it doesn't exist
SET @col_exists2 = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'reservations' 
    AND COLUMN_NAME = 'postponed_at'
);

SET @sql2 = IF(@col_exists2 = 0,
    'ALTER TABLE reservations ADD COLUMN postponed_at TIMESTAMP NULL DEFAULT NULL COMMENT ''Timestamp when reservation was postponed due to facility maintenance''',
    'SELECT ''Column postponed_at already exists'' AS message'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Check and create index if it doesn't exist
SET @index_exists = (
    SELECT COUNT(1) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'reservations' 
    AND INDEX_NAME = 'idx_reservations_postponed'
);

SET @sql3 = IF(@index_exists = 0,
    'CREATE INDEX idx_reservations_postponed ON reservations (status, postponed_priority, facility_id)',
    'SELECT ''Index idx_reservations_postponed already exists'' AS message'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Update reservation_history to support new statuses (safe to run multiple times)
ALTER TABLE reservation_history
    MODIFY COLUMN status ENUM('pending', 'approved', 'denied', 'cancelled', 'postponed', 'on_hold') NOT NULL;
