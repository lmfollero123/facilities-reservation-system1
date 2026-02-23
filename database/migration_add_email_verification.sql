-- Migration: Add email verification fields for user registration
-- Purpose:
--  - Track email verification codes and expiry
--  - Block login until email is verified
--  - Allow safe cleanup of unverified accounts after 24 hours

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verification_code_hash VARCHAR(255) NULL COMMENT 'Hashed email verification code',
    ADD COLUMN IF NOT EXISTS email_verification_expires_at DATETIME NULL COMMENT 'When the email verification code expires',
    ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the user has verified their email address',
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL COMMENT 'When the user verified their email address';

-- Mark existing users as email-verified so only NEW registrations are gated
UPDATE users
SET email_verified = 1,
    email_verified_at = IF(email_verified_at IS NULL, NOW(), email_verified_at)
WHERE email_verified = 0;

-- Optional index to speed up cleanup and login checks
SET @dbname = DATABASE();
SET @tablename = "users";
SET @indexname = "idx_users_email_verified";

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND index_name = @indexname) > 0,
  "SELECT 'Index already exists' AS message",
  CONCAT("CREATE INDEX ", @indexname, " ON ", @tablename, "(email_verified, created_at)")
));

PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

