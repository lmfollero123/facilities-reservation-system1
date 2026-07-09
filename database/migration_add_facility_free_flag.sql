-- Migration: Add is_free flag to facilities table
-- Run in phpMyAdmin/MySQL against facilities_reservation database
-- This allows facilities to be marked as free (no payment required) or paid

USE facilities_reservation;

-- Add is_free column to facilities table
ALTER TABLE facilities
ADD COLUMN IF NOT EXISTS is_free BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this facility is free to use (no payment required)';

-- Update existing facilities to be free by default (maintains current behavior)
UPDATE facilities SET is_free = TRUE WHERE is_free IS NULL;
