-- Migration: Add separate name fields and address components to users table
-- Splits name into first_name, middle_name, last_name, suffix
-- Splits address into street (dropdown) and house_number

-- Check and add first_name column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'first_name');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN first_name VARCHAR(50) NULL AFTER name',
    'SELECT ''Column first_name already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add middle_name column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'middle_name');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN middle_name VARCHAR(50) NULL AFTER first_name',
    'SELECT ''Column middle_name already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add last_name column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'last_name');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN last_name VARCHAR(50) NULL AFTER middle_name',
    'SELECT ''Column last_name already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add suffix column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'suffix');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN suffix VARCHAR(10) NULL AFTER last_name',
    'SELECT ''Column suffix already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add street column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'street');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN street VARCHAR(150) NULL AFTER address',
    'SELECT ''Column street already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add house_number column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'house_number');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN house_number VARCHAR(50) NULL AFTER street',
    'SELECT ''Column house_number already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Note: The 'name' and 'address' columns are kept for backward compatibility
-- The application will concatenate name parts and combine address components
