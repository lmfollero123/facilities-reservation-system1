-- Migration: Barangay Culiat resident exemption + per-reservation referral
-- Client decision: any Quezon City resident can register, but a reservation
-- from a non-Culiat resident needs a referral (a Culiat resident's ID +
-- name/relationship) attached to that specific booking. Verified users an
-- admin flags as Culiat residents skip this requirement entirely.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_culiat_resident BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Set by admin/staff only, after ID verification, when the address is confirmed within Barangay Culiat',
    ADD COLUMN IF NOT EXISTS culiat_resident_set_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS culiat_resident_set_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'Admin/Staff user ID who set the flag';

SET @constraint_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND constraint_name = 'fk_user_culiat_resident_set_by'
);
SET @preparedStatement = (SELECT IF(
    @constraint_exists > 0,
    "SELECT 'Foreign key constraint fk_user_culiat_resident_set_by already exists' AS message",
    "ALTER TABLE users ADD CONSTRAINT fk_user_culiat_resident_set_by FOREIGN KEY (culiat_resident_set_by) REFERENCES users(id) ON DELETE SET NULL"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS referral_name VARCHAR(150) NULL COMMENT 'Name of the Barangay Culiat resident vouching for this booking (non-Culiat requesters only)',
    ADD COLUMN IF NOT EXISTS referral_relationship VARCHAR(100) NULL COMMENT 'Requester''s stated relationship to the referral',
    ADD COLUMN IF NOT EXISTS referral_id_document_path VARCHAR(255) NULL COMMENT 'Secure storage path to the referral''s uploaded valid ID';
