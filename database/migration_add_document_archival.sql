-- Migration: Add document archival support and optimization indexes
-- This migration adds archival fields, retention policy tracking, and missing performance indexes

-- Add archival fields to user_documents
ALTER TABLE user_documents
    ADD COLUMN archived_at DATETIME NULL COMMENT 'When document was archived',
    ADD COLUMN archived_by INT UNSIGNED NULL COMMENT 'Admin/System who archived',
    ADD COLUMN archive_path VARCHAR(255) NULL COMMENT 'Path to archived file (relative to archive storage root)',
    ADD COLUMN is_archived BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether document is archived',
    ADD INDEX idx_user_documents_archived (is_archived, archived_at),
    ADD INDEX idx_user_documents_user_archived (user_id, is_archived);

-- Add data export tracking table (for Data Privacy Act compliance)
CREATE TABLE IF NOT EXISTS data_exports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    export_type ENUM('full', 'reservations', 'profile', 'documents') NOT NULL,
    file_path VARCHAR(255) NOT NULL COMMENT 'Path to exported file (with expiration)',
    expires_at DATETIME NOT NULL COMMENT 'Export files expire after 7 days for security',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL COMMENT 'Admin who created export (NULL = user self-export)',
    CONSTRAINT fk_export_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_export_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_data_exports_user (user_id),
    INDEX idx_data_exports_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document retention policy table (for legal compliance tracking)
CREATE TABLE IF NOT EXISTS document_retention_policy (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('user_document', 'reservation', 'audit_log', 'security_log', 'reservation_history') NOT NULL,
    retention_days INT UNSIGNED NOT NULL COMMENT 'Total retention period in days',
    archive_after_days INT UNSIGNED NOT NULL COMMENT 'Archive after this many days',
    auto_delete_after_days INT UNSIGNED NULL COMMENT 'Auto-delete after this many days (NULL = never auto-delete, requires manual review)',
    description TEXT NULL COMMENT 'Policy description and legal basis',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_document_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default retention policies (based on Philippine legal requirements)
INSERT INTO document_retention_policy (document_type, retention_days, archive_after_days, auto_delete_after_days, description) VALUES
('user_document', 2555, 1095, 2555, '7 years retention for identity documents (BIR/NBI requirements, Data Privacy Act). Archive after 3 years, auto-delete after 7 years.'),
('reservation', 1825, 1095, 1825, '5 years retention for reservation records (Local Government records retention). Archive after 3 years, auto-delete after 5 years.'),
('audit_log', 2555, 1825, NULL, '7 years retention for audit logs (accountability, audit trail requirements). Archive after 5 years, never auto-delete (requires manual review).'),
('security_log', 1095, 730, NULL, '3 years retention for security logs (security incident investigation). Archive after 2 years, never auto-delete.'),
('reservation_history', 1825, 1095, 1825, '5 years retention for reservation history (matches reservation retention). Archive after 3 years, auto-delete after 5 years.')
ON DUPLICATE KEY UPDATE 
    retention_days = VALUES(retention_days),
    archive_after_days = VALUES(archive_after_days),
    auto_delete_after_days = VALUES(auto_delete_after_days),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Add missing performance indexes for common queries
CREATE INDEX IF NOT EXISTS idx_reservations_date_status ON reservations(reservation_date, status);
CREATE INDEX IF NOT EXISTS idx_reservations_user_status ON reservations(user_id, status);
CREATE INDEX IF NOT EXISTS idx_reservations_auto_approved ON reservations(auto_approved, status);
CREATE INDEX IF NOT EXISTS idx_reservations_composite ON reservations(status, reservation_date, facility_id);

-- Optimize user violation checks for auto-approval
CREATE INDEX IF NOT EXISTS idx_violations_auto_approval_check ON user_violations(user_id, severity, created_at);

-- Optimize facility availability queries
CREATE INDEX IF NOT EXISTS idx_facilities_auto_approve ON facilities(auto_approve, status);

-- Add index for blackout date lookups (already has unique index, but add composite for range queries)
CREATE INDEX IF NOT EXISTS idx_blackout_dates_date_range ON facility_blackout_dates(blackout_date, facility_id);

-- Add index for audit log archival queries
CREATE INDEX IF NOT EXISTS idx_audit_log_created_module ON audit_log(created_at, module);

-- Add index for security log archival queries
CREATE INDEX IF NOT EXISTS idx_security_logs_created_severity ON security_logs(created_at, severity);






