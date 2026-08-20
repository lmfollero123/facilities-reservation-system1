-- Migration: Facility-level supporting-document requirement
-- Run in phpMyAdmin/MySQL against facilities_reservation database
-- Lets admins flag a facility as requiring a specific supporting document
-- (e.g. school principal's approval for facilities inside school premises)
-- before a booking can be submitted.

USE facilities_reservation;

ALTER TABLE facilities
ADD COLUMN IF NOT EXISTS requires_document BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether a supporting document must be uploaded to book this facility',
ADD COLUMN IF NOT EXISTS document_requirement_note VARCHAR(255) NULL COMMENT 'Shown to the resident and staff, e.g. "Requires approval from the school principal"';
