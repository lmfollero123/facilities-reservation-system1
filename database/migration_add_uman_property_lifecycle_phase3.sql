-- -----------------------------------------------------------------------------
-- CPRF (Barangay Culiat FRS) — UMAN Property Custodian Lifecycle Phase 3
-- Manual migration for clean deploys / COA audits.
--
-- NOTE: EVERY ALTER TABLE in this file is a SUBSET of the idempotent auto-migrate
-- logic that fires inside `frs_ensure_facility_equipment_schema_v2()` and
-- `frs_ensure_uman_requests_schema_v2()` in /services/uman_api.php. You do NOT
-- need to run this file manually — the PHP helpers wrap identical DDL in
-- try/catch so legacy databases are upgraded the first time any page (e.g.
-- Facility Management, the CPRF Integration Hub, or /api/integrations) loads.
-- Running it explicitly is recommended for:
--   (a) brand-new FRS deployments (faster first page-load, no chance of
--       per-request ALTERs racing a busy API)
--   (b) before COA/DILG spot-audits so all columns exist and are discoverable
--       via `SHOW COLUMNS` queries in database-diff audit tools.
--
-- Engine:          MySQL 8.0+ InnoDB, utf8mb4_unicode_ci
-- Run order:       1. migration_add_uman_equipment.sql (the v1 base)   ALREADY EXISTS
--                  2. migration_add_uman_requests_schema_v2.sql        ALREADY EXISTS (in-code only)
--                  3. THIS FILE  — migration_add_uman_property_lifecycle_phase3.sql
-- Target user:     FRS database owner (must have ALTER, CREATE, INDEX privs)
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- 1.  Widen `facility_equipment` with the Phase-2/3 custody-lifecycle columns.
--     (The v1 migration only had id/facility_id/uman_asset_id + meta fields.)
-- -----------------------------------------------------------------------------
ALTER TABLE facility_equipment
    ADD COLUMN IF NOT EXISTS `status` ENUM('active','return_pending','replacement_in_transit','archived','decommissioned')
        NOT NULL DEFAULT 'active' AFTER `assigned_at`,

    ADD COLUMN IF NOT EXISTS `assigned_source`
        ENUM('UMAN_DIRECT','UMAN_REQUEST_FULFILLED','UMAN_REASSIGNED_DEPRECATED','UMAN_WEBHOOK_RECALL','UMAN_REPLACEMENT_SHIPMENT')
        NOT NULL DEFAULT 'UMAN_DIRECT' AFTER `status`,

    ADD COLUMN IF NOT EXISTS `assigned_by_user_id` INT NULL AFTER `assigned_source`,

    ADD COLUMN IF NOT EXISTS `assigned_event_ref` VARCHAR(60) NULL AFTER `assigned_by_user_id`,

    ADD COLUMN IF NOT EXISTS `return_requested_at` TIMESTAMP NULL AFTER `assigned_event_ref`,
    ADD COLUMN IF NOT EXISTS `return_requested_by` VARCHAR(150) NULL AFTER `return_requested_at`,

    ADD COLUMN IF NOT EXISTS `return_type`
        ENUM('RETURN_ONLY','RETURN_AND_REPLACE','RETURN_DECOMMISSION') NULL
        AFTER `return_requested_by`,

    ADD COLUMN IF NOT EXISTS `return_condition` VARCHAR(100) NULL AFTER `return_type`,
    ADD COLUMN IF NOT EXISTS `return_reason`    TEXT NULL AFTER `return_condition`,

    -- Phase-3c: end-of-lifecycle tracing (per COA §6.2)
    ADD COLUMN IF NOT EXISTS `accepted_return_ref`        VARCHAR(60) NULL AFTER `return_reason`,
    ADD COLUMN IF NOT EXISTS `accepted_return_by`        VARCHAR(150) NULL AFTER `accepted_return_ref`,
    ADD COLUMN IF NOT EXISTS `linked_replacement_asset_id` INT NULL AFTER `accepted_return_by`,
    ADD COLUMN IF NOT EXISTS `disposal_ref`              VARCHAR(60) NULL AFTER `linked_replacement_asset_id`,
    ADD COLUMN IF NOT EXISTS `archived_at`               TIMESTAMP NULL AFTER `disposal_ref`,

    -- Indexes (all idempotent: IF NOT EXISTS ignored for indexes on 8.0.13+)
    ADD UNIQUE KEY IF NOT EXISTS uk_fe_facility_asset (facility_id, uman_asset_id),
    ADD INDEX IF NOT EXISTS idx_fe_status (status),
    ADD INDEX IF NOT EXISTS idx_fe_return_requested (return_requested_at),
    ADD INDEX IF NOT EXISTS idx_fe_archived (archived_at),
    ADD INDEX IF NOT EXISTS idx_fe_replacement (linked_replacement_asset_id);

-- -----------------------------------------------------------------------------
-- 2.  Chain-of-custody event log. 7-year retention per COA Circular 2023-004
--     §6.2 and DILG MC 2022-012 Property Custodian Handbook.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `facility_equipment_events` (
    `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_ref`          VARCHAR(60) NOT NULL UNIQUE COMMENT 'caller-provided, dedupe via UNIQUE',
    `facility_id`        INT NOT NULL,
    `uman_asset_id`      INT NOT NULL,
    `event_type`         ENUM('UMAN_ASSIGN','UMAN_UNASSIGN','UMAN_RETURN_ACCEPTED',
                              'CPRF_RETURN_REQUESTED','CPRF_RETURN_CANCELLED',
                              'UMAN_DECOMMISSIONED','UMAN_REPLACEMENT_SHIPPED',
                              'UMAN_RETURN_TRIGGERED','CPRF_REPLACEMENT_RECEIVED') NOT NULL,
    `actor_system`       ENUM('CPRF','UMAN') NOT NULL,
    `actor_user_label`   VARCHAR(150) NOT NULL COMMENT 'human-readable name, not user_id',
    `return_type`        ENUM('RETURN_ONLY','RETURN_AND_REPLACE','RETURN_DECOMMISSION') NULL,
    `condition_reported` VARCHAR(50) NULL,
    `event_notes`        TEXT NULL,
    `linked_request_ref` VARCHAR(50) NULL COMMENT 'external_asset_requests.request_ref',
    `linked_disposal_ref` VARCHAR(50) NULL COMMENT 'WMR / inventory disposal ref',
    `linked_asset_id`    INT NULL COMMENT 'peer asset: replacement_id on returns, original_id on shipments',
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fee_facility`    (`facility_id`),
    INDEX `idx_fee_asset`       (`uman_asset_id`),
    INDEX `idx_fee_type`        (`event_type`),
    INDEX `idx_fee_created`     (`created_at`),
    INDEX `idx_fee_linked_asset`(`linked_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For databases where facility_equipment_events already EXISTS with a
-- narrower ENUM, run these widening + add-column statements individually.
-- (PHP helper `frs_ensure_facility_equipment_schema_v2` auto-runs equivalent.)
--
-- ALTER TABLE facility_equipment_events MODIFY COLUMN event_type ENUM(...) NOT NULL;
-- ALTER TABLE facility_equipment_events ADD COLUMN linked_asset_id INT NULL;
-- ALTER TABLE facility_equipment_events ADD INDEX idx_fee_linked_asset(linked_asset_id);

-- -----------------------------------------------------------------------------
-- 3.  Widen `uman_asset_requests` with the Phase-2 request-tracking columns
--     (fulfilled_asset_id, review_notes, urgency, date_needed).
--     These are auto-added by frs_ensure_uman_requests_schema_v2() on first
--     external-asset-request page load; this script runs them up-front.
-- -----------------------------------------------------------------------------
ALTER TABLE uman_asset_requests
    ADD COLUMN IF NOT EXISTS `uman_asset_code`       VARCHAR(50) NULL AFTER `uman_request_ref`,
    ADD COLUMN IF NOT EXISTS `asset_name`            VARCHAR(150) NULL AFTER `uman_asset_code`,
    ADD COLUMN IF NOT EXISTS `review_notes`          TEXT NULL AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `fulfilled_asset_id`    INT UNSIGNED NULL AFTER `review_notes`,
    ADD COLUMN IF NOT EXISTS `requested_asset_code`  VARCHAR(50) NULL AFTER `fulfilled_asset_id`,
    ADD COLUMN IF NOT EXISTS `request_source`
        ENUM('CPRF_DASHBOARD','CPRF_API','UMAN_INITIATED') NOT NULL DEFAULT 'CPRF_DASHBOARD'
        AFTER `status`,
    ADD COLUMN IF NOT EXISTS `requested_by_user_id`  INT UNSIGNED NULL AFTER `request_source`,
    ADD COLUMN IF NOT EXISTS `fulfilled_by`          VARCHAR(150) NULL AFTER `requested_by_user_id`,
    ADD COLUMN IF NOT EXISTS `fulfilled_at`          TIMESTAMP NULL AFTER `fulfilled_by`,
    ADD COLUMN IF NOT EXISTS `urgency`               ENUM('low','medium','high','emergency') NOT NULL DEFAULT 'medium' AFTER `quantity`,
    ADD COLUMN IF NOT EXISTS `date_needed`           DATE NULL AFTER `urgency`;
