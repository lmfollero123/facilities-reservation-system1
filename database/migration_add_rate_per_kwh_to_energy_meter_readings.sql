-- Store the electricity tariff submitted with each CPRF meter reading.
-- Safe for existing and fresh installations.

USE facilities_reservation;

SET @rate_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'rate_per_kwh'
);

SET @rate_column_sql = IF(
    @rate_column_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN rate_per_kwh DECIMAL(10,2) NOT NULL DEFAULT 12.00 AFTER consumption_kwh',
    'SELECT 1'
);

PREPARE rate_column_stmt FROM @rate_column_sql;
EXECUTE rate_column_stmt;
DEALLOCATE PREPARE rate_column_stmt;
