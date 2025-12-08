-- Migration: Add profile picture column to users table
-- This allows users to upload and display their profile pictures

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL COMMENT 'Path to user profile picture' 
    AFTER mobile;



