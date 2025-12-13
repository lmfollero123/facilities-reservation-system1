-- Migration: Add reschedule_count to reservations table
-- This tracks how many times a reservation has been rescheduled (max 1 per policy)

ALTER TABLE reservations
ADD COLUMN reschedule_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;

CREATE INDEX idx_res_reschedule_count ON reservations(reschedule_count);

