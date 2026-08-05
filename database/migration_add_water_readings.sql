-- Add water meter reading columns alongside the existing electricity ones.
-- Safe to re-run: guards each ALTER on INFORMATION_SCHEMA before applying.

USE facilities_reservation;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'previous_reading_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN previous_reading_water DECIMAL(14,2) NULL AFTER rate_per_kwh',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'current_reading_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN current_reading_water DECIMAL(14,2) NULL AFTER previous_reading_water',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'consumption_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN consumption_water DECIMAL(14,2) NULL AFTER current_reading_water',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'rate_per_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN rate_per_water DECIMAL(10,2) NOT NULL DEFAULT 68.02 AFTER consumption_water',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
