-- Migration: Secure Document Storage & Access Logging
-- This migration adds secure document access logging and prepares for moving documents outside public/

-- Create document access log table
CREATE TABLE IF NOT EXISTS document_access_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL COMMENT 'ID of accessed document',
    user_id INT UNSIGNED NULL COMMENT 'Owner of the document',
    accessed_by INT UNSIGNED NOT NULL COMMENT 'User who accessed the document',
    access_type ENUM('view', 'download', 'view_thumbnail') NOT NULL DEFAULT 'view',
    ip_address VARCHAR(45) NULL COMMENT 'IP address of requester',
    user_agent TEXT NULL COMMENT 'User agent string',
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_access_document FOREIGN KEY (document_id) REFERENCES user_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_access_accessed_by FOREIGN KEY (accessed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_doc_access_document (document_id),
    INDEX idx_doc_access_user (user_id),
    INDEX idx_doc_access_accessed_by (accessed_by),
    INDEX idx_doc_access_time (accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




