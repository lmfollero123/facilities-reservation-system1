-- Migration: Add extension configuration to facilities table
-- Date: 2026-01-23
-- Description: Adds fields for reservation extension configuration including fees and auto-approval rules

ALTER TABLE facilities
ADD COLUMN IF NOT EXISTS extension_fee_per_hour DECIMAL(10,2) NULL 
DEFAULT 10.00 
COMMENT 'Fee per hour for extending reservations (in pesos). Default: 10.00' 
AFTER operating_hours;

ALTER TABLE facilities
ADD COLUMN IF NOT EXISTS extension_auto_approve_max_hours DECIMAL(4,2) NULL 
COMMENT 'Maximum extension hours for auto-approval. If extension is within this limit, it can be auto-approved after payment. NULL = no auto-approval' 
AFTER extension_fee_per_hour;

ALTER TABLE facilities
ADD COLUMN IF NOT EXISTS allow_same_day_extension BOOLEAN NOT NULL 
DEFAULT TRUE 
COMMENT 'Whether same-day extensions are allowed for this facility' 
AFTER extension_auto_approve_max_hours;

-- Set default values for existing facilities
UPDATE facilities 
SET extension_fee_per_hour = 10.00,
    extension_auto_approve_max_hours = 1.00,
    allow_same_day_extension = TRUE
WHERE extension_fee_per_hour IS NULL;
