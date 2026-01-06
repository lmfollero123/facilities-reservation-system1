-- Migration: Add user verification status for progressive verification
-- Allows accounts to be active but unverified, requiring ID submission for full features

-- Add columns if they don't exist
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_verified BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether user has submitted and been verified with valid ID',
    ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When user was verified by admin/staff',
    ADD COLUMN IF NOT EXISTS verified_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'Admin/Staff user ID who verified this account';

-- Add index only if it doesn't exist
-- Skip this if you get "Duplicate key name" error (index already exists)
-- Check if index exists first
SET @dbname = DATABASE();
SET @tablename = "users";
SET @indexname = "idx_users_verified";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND index_name = @indexname) > 0,
  "SELECT 'Index already exists' AS message",
  CONCAT("CREATE INDEX ", @indexname, " ON ", @tablename, "(is_verified, status)")
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for verified_by if it doesn't exist
SET @constraint_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE table_schema = DATABASE() 
    AND table_name = 'users' 
    AND constraint_name = 'fk_user_verified_by'
);

SET @preparedStatement = (SELECT IF(
    @constraint_exists > 0,
    "SELECT 'Foreign key constraint fk_user_verified_by already exists' AS message",
    "ALTER TABLE users ADD CONSTRAINT fk_user_verified_by FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing active users: if they have a valid_id document, mark as verified
UPDATE users u
SET is_verified = TRUE,
    verified_at = (
        SELECT MIN(uploaded_at)
        FROM user_documents
        WHERE user_id = u.id
          AND document_type = 'valid_id'
          AND is_archived = FALSE
    )
WHERE EXISTS (
    SELECT 1
    FROM user_documents d
    WHERE d.user_id = u.id
      AND d.document_type = 'valid_id'
      AND d.is_archived = FALSE
);

