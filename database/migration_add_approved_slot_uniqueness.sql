-- Defense-in-depth against double-booking: the app already serializes
-- booking creation with a facility row lock + conflict check
-- (frs_lock_facility_for_booking / detectBookingConflict), but that was the
-- *only* protection -- no DB-level constraint backed it up. A live duplicate
-- (two approved reservations for the same facility/date/time_slot) was found
-- in production data before this migration was written.
--
-- MySQL/MariaDB have no native partial/conditional unique index, so this
-- uses the standard workaround: a generated column that is NULL for every
-- status except 'approved', with a UNIQUE key on that column. NULLs don't
-- collide in a UNIQUE index, so pending/denied/cancelled/etc. reservations
-- for the same slot are unaffected -- only two *approved* rows for the exact
-- same facility + date + time_slot string will be rejected.
--
-- Scoped to 'approved' only (not pending/pending_payment/postponed) because
-- frs_reservation_blocks_booking() treats those statuses as conditionally
-- blocking based on payment_due_at/expires_at freshness, which a static
-- generated column can't express -- enforcing uniqueness there would risk
-- false-positive DB errors for legitimately-expired holds the cleanup cron
-- hasn't processed yet. 'approved' has no such freshness caveat in the app
-- logic, so it's safe to enforce unconditionally.

ALTER TABLE reservations
    ADD COLUMN active_approved_slot VARCHAR(160)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'approved'
                THEN CONCAT(facility_id, '|', reservation_date, '|', time_slot)
                ELSE NULL
            END
        ) STORED,
    ADD UNIQUE KEY uniq_approved_facility_slot (active_approved_slot);
