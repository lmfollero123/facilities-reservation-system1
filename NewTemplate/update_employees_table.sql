-- SQL script to add new columns to employees table
-- Run this script in phpMyAdmin or MySQL to update the employees table structure
-- This will add first_name, last_name, and role columns to the employees table

-- Add columns with default values to handle existing data
ALTER TABLE `employees`
ADD COLUMN `first_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `id`,
ADD COLUMN `last_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `first_name`,
ADD COLUMN `role` VARCHAR(100) NOT NULL DEFAULT 'Employee' AFTER `email`;

-- Note: If you get an error saying a column already exists, that column has already been added.
-- You can safely ignore that error or comment out that line and run the remaining ALTER TABLE statements.

