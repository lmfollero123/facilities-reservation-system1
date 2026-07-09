-- Migration: Add reservation_status category to lookup system
-- Run in phpMyAdmin/MySQL against facilities_reservation database
-- This allows reservation statuses to be configurable via System Settings

USE facilities_reservation;

-- Insert reservation_status category
INSERT INTO lookup_categories (slug, name, description) VALUES
('reservation_status', 'Reservation Status', 'Statuses for reservation lifecycle (pending, approved, cancelled, etc.).')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- Insert default reservation statuses
INSERT INTO lookup_values (category_id, slug, label, sort_order, is_active, is_system, metadata)
SELECT c.id, v.slug, v.label, v.sort_order, 1, 1, v.metadata
FROM lookup_categories c
CROSS JOIN (
    SELECT 'pending' AS slug, 'Pending' AS label, 10 AS sort_order, '{"blocks_booking":true,"badge_class":"pending","is_final":false}' AS metadata
    UNION ALL SELECT 'approved', 'Approved', 20, '{"blocks_booking":true,"badge_class":"approved","is_final":false}'
    UNION ALL SELECT 'denied', 'Denied', 30, '{"blocks_booking":false,"badge_class":"denied","is_final":true}'
    UNION ALL SELECT 'cancelled', 'Cancelled', 40, '{"blocks_booking":false,"badge_class":"cancelled","is_final":true}'
    UNION ALL SELECT 'postponed', 'Postponed', 50, '{"blocks_booking":true,"badge_class":"postponed","is_final":false}'
    UNION ALL SELECT 'pending_payment', 'Pending Payment', 60, '{"blocks_booking":true,"badge_class":"pending_payment","is_final":false,"requires_payment":true}'
    UNION ALL SELECT 'completed', 'Completed', 70, '{"blocks_booking":false,"badge_class":"completed","is_final":true}'
) v
WHERE c.slug = 'reservation_status'
ON DUPLICATE KEY UPDATE label = VALUES(label), metadata = VALUES(metadata);
