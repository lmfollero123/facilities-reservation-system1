-- Migration: Add "deleted" facility status (soft delete for Facility Management)
--
-- Several read paths already filter WHERE status != 'deleted' in
-- anticipation of this status (public/api/availability.php,
-- public/api/mobile/index.php facilities endpoints, dashboard/blackout_dates.php)
-- but nothing could ever set it: Facility Management had an Add/Edit form
-- with no Delete action. This adds 'deleted' as a first-class facility_status
-- lookup value plus tracking columns; facility_management.php is wired up to
-- set/clear it via delete_facility / restore_facility actions.
--
-- Facilities are never hard-deleted: reservations.facility_id has no
-- ON DELETE CASCADE (by design, so historical reservation records survive),
-- so a real DELETE FROM facilities would fail with a foreign key violation
-- for any facility that has ever been booked. This is the same soft-delete
-- pattern already used for user accounts (see
-- migration_add_account_deactivation.sql: status='deactivated' + deactivated_at
-- + deactivation_reason).

INSERT INTO lookup_values (category_id, slug, label, sort_order, is_active, is_system, metadata)
SELECT c.id, 'deleted', 'Deleted', 40, 1, 1, JSON_OBJECT('blocks_booking', true, 'badge_class', 'deleted')
FROM lookup_categories c
WHERE c.slug = 'facility_status'
ON DUPLICATE KEY UPDATE label = VALUES(label), metadata = VALUES(metadata);

ALTER TABLE facilities
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When the facility was soft-deleted';

ALTER TABLE facilities
    ADD COLUMN IF NOT EXISTS deleted_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'User who deleted the facility';

ALTER TABLE facilities
    ADD COLUMN IF NOT EXISTS delete_reason TEXT NULL DEFAULT NULL COMMENT 'Optional reason provided when deleting';
