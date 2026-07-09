<?php
/**
 * Admin-configurable lookup categories (facility statuses, etc.).
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/** @var array<string, list<array<string, mixed>>>|null */
$GLOBALS['_frs_lookup_cache'] = null;

function frs_lookups_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'lookup_values'");
        $ready = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * @return list<array{id:int, slug:string, name:string, description:?string}>
 */
function frs_lookup_categories(PDO $pdo): array
{
    if (!frs_lookups_table_ready($pdo)) {
        return [];
    }
    $stmt = $pdo->query('SELECT id, slug, name, description FROM lookup_categories ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function frs_lookup_values(PDO $pdo, string $categorySlug, bool $activeOnly = true): array
{
    if (!frs_lookups_table_ready($pdo)) {
        return frs_lookup_fallback_values($categorySlug);
    }

    if (!isset($GLOBALS['_frs_lookup_cache'][$categorySlug])) {
        $sql = 'SELECT v.id, v.slug, v.label, v.sort_order, v.is_active, v.is_system, v.metadata
                FROM lookup_values v
                INNER JOIN lookup_categories c ON c.id = v.category_id
                WHERE c.slug = :slug
                ORDER BY v.sort_order ASC, v.label ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['slug' => $categorySlug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $meta = $row['metadata'] ?? null;
            if (is_string($meta) && $meta !== '') {
                $decoded = json_decode($meta, true);
                $row['metadata'] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($meta)) {
                $row['metadata'] = [];
            }
        }
        unset($row);
        $GLOBALS['_frs_lookup_cache'][$categorySlug] = $rows;
    }

    $rows = $GLOBALS['_frs_lookup_cache'][$categorySlug];
    if (!$activeOnly) {
        return $rows;
    }
    return array_values(array_filter($rows, static fn(array $r): bool => !empty($r['is_active'])));
}

/**
 * @return list<array<string, mixed>>
 */
function frs_lookup_fallback_values(string $categorySlug): array
{
    if ($categorySlug === 'facility_status') {
        return [
            ['id' => 0, 'slug' => 'available', 'label' => 'Available', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => false, 'badge_class' => 'available']],
            ['id' => 0, 'slug' => 'maintenance', 'label' => 'Maintenance', 'sort_order' => 20, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => true, 'badge_class' => 'maintenance']],
            ['id' => 0, 'slug' => 'offline', 'label' => 'Offline', 'sort_order' => 30, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => true, 'badge_class' => 'offline']],
        ];
    }
    if ($categorySlug === 'reservation_status') {
        return [
            ['id' => 0, 'slug' => 'pending', 'label' => 'Pending', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => true, 'badge_class' => 'pending', 'is_final' => false]],
            ['id' => 0, 'slug' => 'approved', 'label' => 'Approved', 'sort_order' => 20, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => true, 'badge_class' => 'approved', 'is_final' => false]],
            ['id' => 0, 'slug' => 'denied', 'label' => 'Denied', 'sort_order' => 30, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => false, 'badge_class' => 'denied', 'is_final' => true]],
            ['id' => 0, 'slug' => 'cancelled', 'label' => 'Cancelled', 'sort_order' => 40, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => false, 'badge_class' => 'cancelled', 'is_final' => true]],
            ['id' => 0, 'slug' => 'postponed', 'label' => 'Postponed', 'sort_order' => 50, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => true, 'badge_class' => 'postponed', 'is_final' => false]],
            ['id' => 0, 'slug' => 'pending_payment', 'label' => 'Pending Payment', 'sort_order' => 60, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => true, 'badge_class' => 'pending_payment', 'is_final' => false, 'requires_payment' => true]],
            ['id' => 0, 'slug' => 'completed', 'label' => 'Completed', 'sort_order' => 70, 'is_active' => 1, 'is_system' => 1, 'metadata' => ['blocks_booking' => false, 'badge_class' => 'completed', 'is_final' => true]],
        ];
    }
    return [];
}

function frs_lookup_label(PDO $pdo, string $categorySlug, string $valueSlug): string
{
    foreach (frs_lookup_values($pdo, $categorySlug, false) as $row) {
        if (($row['slug'] ?? '') === $valueSlug) {
            return (string)($row['label'] ?? $valueSlug);
        }
    }
    return ucfirst(str_replace('_', ' ', $valueSlug));
}

function frs_lookup_metadata(PDO $pdo, string $categorySlug, string $valueSlug): array
{
    foreach (frs_lookup_values($pdo, $categorySlug, false) as $row) {
        if (($row['slug'] ?? '') === $valueSlug) {
            return is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        }
    }
    return [];
}

function frs_facility_status_blocks_booking(PDO $pdo, string $statusSlug): bool
{
    $meta = frs_lookup_metadata($pdo, 'facility_status', $statusSlug);
    if (array_key_exists('blocks_booking', $meta)) {
        return (bool)$meta['blocks_booking'];
    }
    return !in_array($statusSlug, ['available'], true);
}

function frs_facility_status_badge_class(PDO $pdo, string $statusSlug): string
{
    $meta = frs_lookup_metadata($pdo, 'facility_status', $statusSlug);
    return (string)($meta['badge_class'] ?? $statusSlug);
}

function frs_reservation_status_blocks_booking(PDO $pdo, string $statusSlug): bool
{
    $meta = frs_lookup_metadata($pdo, 'reservation_status', $statusSlug);
    if (array_key_exists('blocks_booking', $meta)) {
        return (bool)$meta['blocks_booking'];
    }
    return !in_array($statusSlug, ['denied', 'cancelled', 'completed'], true);
}

function frs_reservation_status_badge_class(PDO $pdo, string $statusSlug): string
{
    $meta = frs_lookup_metadata($pdo, 'reservation_status', $statusSlug);
    return (string)($meta['badge_class'] ?? $statusSlug);
}

function frs_reservation_status_is_final(PDO $pdo, string $statusSlug): bool
{
    $meta = frs_lookup_metadata($pdo, 'reservation_status', $statusSlug);
    if (array_key_exists('is_final', $meta)) {
        return (bool)$meta['is_final'];
    }
    return in_array($statusSlug, ['denied', 'cancelled', 'completed'], true);
}

function frs_slugify_lookup(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? '';
    return trim($text, '_') ?: 'item';
}

/**
 * @return array{ok: bool, message: string}
 */
function frs_lookup_add_value(PDO $pdo, string $categorySlug, string $label, ?string $slug = null, array $metadata = []): array
{
    if (!frs_lookups_table_ready($pdo)) {
        return ['ok' => false, 'message' => 'Lookup tables are not installed. Run database/migration_add_system_lookups.sql.'];
    }
    $label = trim($label);
    if ($label === '') {
        return ['ok' => false, 'message' => 'Label is required.'];
    }
    $slug = $slug !== null && trim($slug) !== '' ? frs_slugify_lookup($slug) : frs_slugify_lookup($label);

    $cat = $pdo->prepare('SELECT id FROM lookup_categories WHERE slug = ? LIMIT 1');
    $cat->execute([$categorySlug]);
    $categoryId = (int)$cat->fetchColumn();
    if ($categoryId <= 0) {
        return ['ok' => false, 'message' => 'Unknown category.'];
    }

    $exists = $pdo->prepare('SELECT id FROM lookup_values WHERE category_id = ? AND slug = ? LIMIT 1');
    $exists->execute([$categoryId, $slug]);
    if ($exists->fetchColumn()) {
        return ['ok' => false, 'message' => 'An item with this key already exists.'];
    }

    $maxSort = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM lookup_values WHERE category_id = ?');
    $maxSort->execute([$categoryId]);
    $sortOrder = ((int)$maxSort->fetchColumn()) + 10;

    $stmt = $pdo->prepare(
        'INSERT INTO lookup_values (category_id, slug, label, sort_order, is_active, is_system, metadata)
         VALUES (?, ?, ?, ?, 1, 0, ?)'
    );
    $stmt->execute([$categoryId, $slug, $label, $sortOrder, json_encode($metadata, JSON_UNESCAPED_UNICODE)]);

    unset($GLOBALS['_frs_lookup_cache'][$categorySlug]);
    return ['ok' => true, 'message' => 'Item added.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function frs_lookup_update_value(PDO $pdo, int $valueId, string $label, bool $isActive, array $metadata = []): array
{
    if (!frs_lookups_table_ready($pdo)) {
        return ['ok' => false, 'message' => 'Lookup tables are not installed.'];
    }
    $label = trim($label);
    if ($label === '') {
        return ['ok' => false, 'message' => 'Label is required.'];
    }

    $rowStmt = $pdo->prepare(
        'SELECT v.id, v.slug, v.is_system, c.slug AS category_slug
         FROM lookup_values v
         INNER JOIN lookup_categories c ON c.id = v.category_id
         WHERE v.id = ? LIMIT 1'
    );
    $rowStmt->execute([$valueId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Item not found.'];
    }

    $stmt = $pdo->prepare(
        'UPDATE lookup_values SET label = ?, is_active = ?, metadata = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$label, $isActive ? 1 : 0, json_encode($metadata, JSON_UNESCAPED_UNICODE), $valueId]);

    unset($GLOBALS['_frs_lookup_cache'][(string)$row['category_slug']]);
    return ['ok' => true, 'message' => 'Item updated.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function frs_lookup_delete_value(PDO $pdo, int $valueId): array
{
    if (!frs_lookups_table_ready($pdo)) {
        return ['ok' => false, 'message' => 'Lookup tables are not installed.'];
    }

    $rowStmt = $pdo->prepare(
        'SELECT v.id, v.slug, v.is_system, c.slug AS category_slug
         FROM lookup_values v
         INNER JOIN lookup_categories c ON c.id = v.category_id
         WHERE v.id = ? LIMIT 1'
    );
    $rowStmt->execute([$valueId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Item not found.'];
    }

    if (!empty($row['is_system'])) {
        return ['ok' => false, 'message' => 'System default items cannot be deleted. Deactivate them instead.'];
    }

    if (($row['category_slug'] ?? '') === 'facility_status') {
        $useCount = $pdo->prepare('SELECT COUNT(*) FROM facilities WHERE status = ?');
        $useCount->execute([(string)$row['slug']]);
        if ((int)$useCount->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'This status is assigned to one or more facilities. Reassign those facilities first, or deactivate the status.'];
        }
    }

    $pdo->prepare('DELETE FROM lookup_values WHERE id = ?')->execute([$valueId]);
    unset($GLOBALS['_frs_lookup_cache'][(string)$row['category_slug']]);
    return ['ok' => true, 'message' => 'Item removed.'];
}
