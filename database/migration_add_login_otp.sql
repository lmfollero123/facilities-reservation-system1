-- Migration: Add OTP fields for login verification and extend document types

-- Add OTP-related columns to users table
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS otp_code_hash VARCHAR(255) NULL AFTER locked_until,
    ADD COLUMN IF NOT EXISTS otp_expires_at DATETIME NULL AFTER otp_code_hash,
    ADD COLUMN IF NOT EXISTS otp_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_expires_at,
    ADD COLUMN IF NOT EXISTS otp_last_sent_at DATETIME NULL AFTER otp_attempts;

-- Extend document type enum to include resident_id
ALTER TABLE user_documents
    MODIFY COLUMN document_type ENUM('birth_certificate', 'valid_id', 'brgy_id', 'resident_id', 'other') NOT NULL;




