-- Migration: Add priority system and time-limited pending reservations
-- Implements best practice: Only APPROVED blocks slots, PENDING expires after 48 hours

-- Add priority field (1 = LGU/Barangay Event, 2 = Community/Organization, 3 = Private Individual)
ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS priority_level INT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1=LGU/Barangay, 2=Community/Org, 3=Private Individual' AFTER is_commercial,
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When pending reservation expires (48 hours after creation)' AFTER updated_at;

-- Add index for priority-based queries
SET @index_exists = (
    SELECT COUNT(1) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE table_schema = DATABASE() 
    AND table_name = 'reservations' 
    AND index_name = 'idx_reservations_priority'
);

SET @preparedStatement = (SELECT IF(
    @index_exists > 0,
    "SELECT 'Index idx_reservations_priority already exists' AS message",
    "CREATE INDEX idx_reservations_priority ON reservations(priority_level, status, reservation_date)"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for expiry queries
SET @index_exists2 = (
    SELECT COUNT(1) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE table_schema = DATABASE() 
    AND table_name = 'reservations' 
    AND index_name = 'idx_reservations_expires'
);

SET @preparedStatement2 = (SELECT IF(
    @index_exists2 > 0,
    "SELECT 'Index idx_reservations_expires already exists' AS message",
    "CREATE INDEX idx_reservations_expires ON reservations(status, expires_at)"
));
PREPARE stmt2 FROM @preparedStatement2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Set expires_at for existing pending reservations (48 hours from creation or now if older)
UPDATE reservations
SET expires_at = CASE 
    WHEN status = 'pending' AND expires_at IS NULL THEN
        CASE 
            WHEN created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN DATE_ADD(created_at, INTERVAL 48 HOUR)
            ELSE NOW()
        END
    ELSE expires_at
END
WHERE status = 'pending';



