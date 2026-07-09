-- Configurable lookup categories (facility statuses, etc.)
-- Run once on production after backup.

CREATE TABLE IF NOT EXISTS lookup_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lookup_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lookup_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    slug VARCHAR(64) NOT NULL,
    label VARCHAR(128) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lookup_values_cat_slug (category_id, slug),
    KEY idx_lookup_values_category_active (category_id, is_active, sort_order),
    CONSTRAINT fk_lookup_values_category FOREIGN KEY (category_id) REFERENCES lookup_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allow custom facility status slugs beyond ENUM
ALTER TABLE facilities MODIFY COLUMN status VARCHAR(64) NOT NULL DEFAULT 'available';

INSERT INTO lookup_categories (slug, name, description) VALUES
('facility_status', 'Facility Status', 'Operational status shown on facilities and booking rules.')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO lookup_values (category_id, slug, label, sort_order, is_active, is_system, metadata)
SELECT c.id, v.slug, v.label, v.sort_order, 1, 1, v.metadata
FROM lookup_categories c
CROSS JOIN (
    SELECT 'available' AS slug, 'Available' AS label, 10 AS sort_order, JSON_OBJECT('blocks_booking', false, 'badge_class', 'available') AS metadata
    UNION ALL SELECT 'maintenance', 'Maintenance', 20, JSON_OBJECT('blocks_booking', true, 'badge_class', 'maintenance')
    UNION ALL SELECT 'offline', 'Offline', 30, JSON_OBJECT('blocks_booking', true, 'badge_class', 'offline')
) v
WHERE c.slug = 'facility_status'
ON DUPLICATE KEY UPDATE label = VALUES(label), metadata = VALUES(metadata);
