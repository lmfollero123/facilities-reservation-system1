-- Migration: Add OTP preference option for users
-- Allows users to enable/disable OTP on login

ALTER TABLE users
ADD COLUMN IF NOT EXISTS enable_otp BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether OTP is required on login (default: enabled for security)';

-- Create index for faster queries
CREATE INDEX IF NOT EXISTS idx_users_enable_otp ON users(enable_otp);
