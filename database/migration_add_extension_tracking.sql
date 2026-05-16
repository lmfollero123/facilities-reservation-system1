-- Migration: Add extension tracking to reservations table
-- Date: 2026-01-23
-- Description: Adds fields to track reservation extensions

ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS extension_count INT UNSIGNED NOT NULL 
DEFAULT 0 
COMMENT 'Number of times this reservation has been extended' 
AFTER reschedule_count;

ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS original_end_time VARCHAR(50) NULL 
COMMENT 'Original end time before extension (for audit trail)' 
AFTER extension_count;

ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS extension_fee_paid DECIMAL(10,2) NULL 
COMMENT 'Total fee paid for extensions (in pesos)' 
AFTER original_end_time;

ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS last_extended_at TIMESTAMP NULL 
COMMENT 'Timestamp when the reservation was last extended' 
AFTER extension_fee_paid;
