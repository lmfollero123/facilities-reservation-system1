-- Migration: Add TOTP (Google Authenticator) support for Admin and Staff
-- Authenticator OTP: Admin Mandatory (recommended), Staff Optional
-- Run once. If columns exist, remove the ADD lines or use your DB's "IF NOT EXISTS" if supported.

ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(255) NULL COMMENT 'TOTP secret for authenticator app' AFTER otp_last_sent_at;
ALTER TABLE users
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether authenticator app is enabled (Admin/Staff)' AFTER totp_secret;
