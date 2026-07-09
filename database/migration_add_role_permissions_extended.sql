-- Incremental migration for databases that already ran migration_add_role_permissions.sql
-- before the extended module keys were added (communications, maintenance, etc.).
-- Fresh installs: run migration_add_role_permissions.sql only (includes all keys).
-- Safe to re-run: uses ON DUPLICATE KEY UPDATE

USE facilities_reservation;

INSERT INTO role_permissions (role, permission_key, can_create, can_read, can_update, can_delete) VALUES
('Admin', 'communications', 1, 1, 1, 1),
('Admin', 'maintenance', 1, 1, 1, 1),
('Admin', 'infrastructure', 1, 1, 1, 1),
('Admin', 'utilities', 1, 1, 1, 1),
('Admin', 'ai_tools', 1, 1, 1, 1),
('Admin', 'documents', 1, 1, 1, 1),

('Staff', 'communications', 1, 1, 1, 0),
('Staff', 'maintenance', 0, 1, 1, 0),
('Staff', 'infrastructure', 0, 1, 0, 0),
('Staff', 'utilities', 0, 1, 0, 0),
('Staff', 'ai_tools', 0, 1, 0, 0),
('Staff', 'documents', 0, 0, 0, 0),

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
