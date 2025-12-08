-- Migration: Add user documents table for registration verification
-- This allows residents to upload required documents (birth certificate, IDs, etc.) to prove they are from Barangay Culiat

CREATE TABLE IF NOT EXISTS user_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    document_type ENUM('birth_certificate', 'valid_id', 'brgy_id', 'other') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_documents (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add mobile number column to users table if it doesn't exist
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS mobile VARCHAR(20) NULL AFTER email;



