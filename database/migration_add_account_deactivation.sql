-- Migration: Add account deactivation (soft delete) support
-- Implements industry-standard soft delete for LGU/government systems
-- Users can deactivate their accounts, but data is retained for compliance

USE facilities_reservation;

-- Add deactivated status to users.status enum
-- Note: ALTER TABLE ... MODIFY COLUMN cannot directly modify ENUM in some MySQL versions
-- We'll check if the value exists and add it if needed
SET @dbname = DATABASE();
SET @tablename = "users";
SET @columnname = "status";
SET @check_sql = CONCAT(
    "SELECT COLUMN_TYPE INTO @current_enum FROM INFORMATION_SCHEMA.COLUMNS ",
    "WHERE table_schema = '", @dbname, "' ",
    "AND table_name = '", @tablename, "' ",
    "AND column_name = '", @columnname, "'"
);
PREPARE stmt FROM @check_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if 'deactivated' already exists in enum
SET @enum_has_deactivated = (SELECT @current_enum LIKE '%deactivated%');

SET @alter_sql = IF(
    @enum_has_deactivated > 0,
    "SELECT 'Status enum already includes deactivated' AS message",
    CONCAT(
        "ALTER TABLE users MODIFY COLUMN status ENUM('pending','active','locked','deactivated') NOT NULL DEFAULT 'pending'"
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add deactivated_at timestamp column
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS deactivated_at TIMESTAMP NULL DEFAULT NULL 
    COMMENT 'Timestamp when account was deactivated (soft delete)';

-- Add index for efficient queries
CREATE INDEX IF NOT EXISTS idx_users_deactivated ON users(deactivated_at, status);

-- Add a note field for deactivation reason (optional, stored by admin if needed)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS deactivation_reason TEXT NULL DEFAULT NULL 
    COMMENT 'Reason for account deactivation (if provided by user or admin)';

