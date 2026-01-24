-- Migration: Add operating_hours field to facilities table
-- Date: 2026-01-23
-- Description: Adds operating hours field to store facility operating time (e.g., "09:00-16:00" or "8:00 AM - 4:00 PM")

ALTER TABLE facilities
ADD COLUMN IF NOT EXISTS operating_hours VARCHAR(50) NULL 
COMMENT 'Operating hours in format HH:MM-HH:MM (24-hour) or HH:MM AM/PM - HH:MM AM/PM (12-hour). Example: "09:00-16:00" or "8:00 AM - 4:00 PM"' 
AFTER max_duration_hours;

-- Set default operating hours for existing facilities (8:00 AM - 9:00 PM)
UPDATE facilities 
SET operating_hours = '08:00-21:00' 
WHERE operating_hours IS NULL;
