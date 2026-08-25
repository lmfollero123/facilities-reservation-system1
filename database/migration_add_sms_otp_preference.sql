-- Migration: Add SMS OTP preference option for users
-- Lets users choose SMS as a login-OTP delivery channel (alongside email
-- and, for Admin/Staff, Google Authenticator). Usable only when the
-- account also has a mobile number on file (enforced in app code).

ALTER TABLE users
ADD COLUMN IF NOT EXISTS sms_otp_enabled BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether SMS is an enabled login-OTP delivery channel (requires mobile on file)';

CREATE INDEX IF NOT EXISTS idx_users_sms_otp_enabled ON users(sms_otp_enabled);
