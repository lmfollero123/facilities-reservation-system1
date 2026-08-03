-- Migration: force password change after admin-initiated resets
-- Run in phpMyAdmin/MySQL against facilities_reservation database

USE facilities_reservation;

ALTER TABLE users
ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
