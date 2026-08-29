-- Migration: Add photo_path to user_violations
-- Lets a staff-recorded violation (e.g. damage type) attach photo evidence.
-- This migration is idempotent - safe to run multiple times.

SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_violations'
    AND COLUMN_NAME = 'photo_path'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE user_violations ADD COLUMN photo_path VARCHAR(255) NULL DEFAULT NULL COMMENT ''Private storage path to uploaded evidence photo, if any''',
    'SELECT ''Column photo_path already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
