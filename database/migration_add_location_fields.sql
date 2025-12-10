-- Migration: Add location fields for proximity-based recommendations
-- Adds address and coordinates to users and facilities tables

-- Add address and coordinates to users table
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL AFTER mobile,
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8) NULL AFTER latitude;

-- Add coordinates to facilities table (location field already exists)
ALTER TABLE facilities
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8) NULL AFTER location,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8) NULL AFTER latitude;

-- Add indexes for faster distance queries
CREATE INDEX IF NOT EXISTS idx_users_coordinates ON users(latitude, longitude);
CREATE INDEX IF NOT EXISTS idx_facilities_coordinates ON facilities(latitude, longitude);
CREATE INDEX IF NOT EXISTS idx_facilities_status_location ON facilities(status, latitude, longitude);



