-- Migration: Add role_permissions table for granular permission control
-- Run in phpMyAdmin/MySQL against facilities_reservation database
-- This allows Admin to configure what each role can do (CRUD) per module

USE facilities_reservation;

-- Create role_permissions table
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(32) NOT NULL,
    permission_key VARCHAR(64) NOT NULL,
    can_create TINYINT(1) NOT NULL DEFAULT 0,
    can_read TINYINT(1) NOT NULL DEFAULT 0,
    can_update TINYINT(1) NOT NULL DEFAULT 0,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_permissions (role, permission_key),
    KEY idx_role_permissions_role (role),
    KEY idx_role_permissions_permission (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Define permission keys for each module
-- These represent the modules/resources in the system
INSERT INTO role_permissions (role, permission_key, can_create, can_read, can_update, can_delete) VALUES
-- Admin: Full access to everything
('Admin', 'users', 1, 1, 1, 1),
('Admin', 'facilities', 1, 1, 1, 1),
('Admin', 'reservations', 1, 1, 1, 1),
('Admin', 'reports', 1, 1, 1, 1),
('Admin', 'settings', 1, 1, 1, 1),
('Admin', 'announcements', 1, 1, 1, 1),
('Admin', 'blackout_dates', 1, 1, 1, 1),
('Admin', 'audit_trail', 1, 1, 1, 1),
('Admin', 'communications', 1, 1, 1, 1),
('Admin', 'maintenance', 1, 1, 1, 1),
('Admin', 'infrastructure', 1, 1, 1, 1),
('Admin', 'utilities', 1, 1, 1, 1),
('Admin', 'ai_tools', 1, 1, 1, 1),
('Admin', 'documents', 1, 1, 1, 1),

-- Staff: Can manage facilities and reservations, read reports, limited user management
('Staff', 'users', 1, 1, 1, 0),
('Staff', 'facilities', 1, 1, 1, 0),
('Staff', 'reservations', 1, 1, 1, 0),
('Staff', 'reports', 0, 1, 0, 0),
('Staff', 'settings', 0, 0, 0, 0),
('Staff', 'announcements', 1, 1, 1, 0),
('Staff', 'blackout_dates', 1, 1, 1, 0),
('Staff', 'audit_trail', 0, 1, 0, 0),
('Staff', 'communications', 1, 1, 1, 0),
('Staff', 'maintenance', 0, 1, 1, 0),
('Staff', 'infrastructure', 0, 1, 0, 0),
('Staff', 'utilities', 0, 1, 0, 0),
('Staff', 'ai_tools', 0, 1, 0, 0),
('Staff', 'documents', 0, 0, 0, 0),

-- Resident: Can only manage their own reservations
('Resident', 'users', 0, 0, 0, 0),
('Resident', 'facilities', 0, 1, 0, 0),
('Resident', 'reservations', 1, 1, 1, 0),
('Resident', 'reports', 0, 0, 0, 0),
('Resident', 'settings', 0, 0, 0, 0),
('Resident', 'announcements', 0, 1, 0, 0),
('Resident', 'blackout_dates', 0, 1, 0, 0),
('Resident', 'audit_trail', 0, 0, 0, 0),
('Resident', 'communications', 0, 0, 0, 0),
('Resident', 'maintenance', 0, 0, 0, 0),
('Resident', 'infrastructure', 0, 0, 0, 0),
('Resident', 'utilities', 0, 0, 0, 0),
('Resident', 'ai_tools', 0, 1, 0, 0),
('Resident', 'documents', 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE
    can_create = VALUES(can_create),
    can_read = VALUES(can_read),
    can_update = VALUES(can_update),
    can_delete = VALUES(can_delete);
