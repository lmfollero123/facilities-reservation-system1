-- Migration: Add self-service account deletion request fields to users
-- RA 10173 (Data Privacy Act) - lets a verified active resident request
-- their account/data be removed, for Admin review (the existing hard-delete
-- action already refuses accounts with reservation history, so this is a
-- review queue, not an automatic delete).
-- This migration is idempotent - safe to run multiple times.

SET @col_exists1 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'deletion_requested_at'
);

SET @sql1 = IF(@col_exists1 = 0,
    'ALTER TABLE users ADD COLUMN deletion_requested_at TIMESTAMP NULL DEFAULT NULL COMMENT ''When the user requested account/data deletion''',
    'SELECT ''Column deletion_requested_at already exists'' AS message'
);
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @col_exists2 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'deletion_reason'
);

SET @sql2 = IF(@col_exists2 = 0,
    'ALTER TABLE users ADD COLUMN deletion_reason TEXT NULL DEFAULT NULL COMMENT ''Reason the user gave for requesting deletion''',
    'SELECT ''Column deletion_reason already exists'' AS message'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
