-- Migration: Remove payments module
-- Since facilities are completely free for residents of Barangay Culiat, the payment module is being removed
-- Run this in phpMyAdmin or MySQL to remove payment-related tables and constraints

USE facilities_reservation;

-- Drop foreign key constraints first
ALTER TABLE payments DROP FOREIGN KEY IF EXISTS fk_payment_reservation;
ALTER TABLE payments DROP FOREIGN KEY IF EXISTS fk_payment_verifier;

-- Drop indexes
DROP INDEX IF EXISTS idx_payment_reservation ON payments;
DROP INDEX IF EXISTS idx_payment_or ON payments;
DROP INDEX IF EXISTS idx_payment_status ON payments;
DROP INDEX IF EXISTS idx_payment_date ON payments;

-- Drop the payments table
DROP TABLE IF EXISTS payments;

-- Update notifications enum to remove 'payment' type
-- Note: This requires MySQL 8.0+ or MariaDB 10.2+ for ALTER TABLE ... MODIFY COLUMN with ENUM
ALTER TABLE notifications 
MODIFY COLUMN type ENUM('booking', 'system', 'reminder') NOT NULL DEFAULT 'system';


