<?php
/**
 * Facilities feed for the LGU Energy Efficiency system.
 *
 * The Energy system pulls this feed (scheduled artisan command + manual
 * "Sync now") and upserts the rows into its own facilities table as
 * source='cprf' records whose identity fields (name, address, details) are
 * read-only on the Energy side. CPRF stays the system of record for
 * Barangay Culiat public facilities; the Energy side owns only the energy
 * layer (profiles, baselines, readings analysis).
 *
 * Auth: Authorization: Bearer <ENERGY_API_TOKEN> — the same shared secret
 * already used for the readings push/recommendations pull, so the
 * partnership needs exactly one secret in both directions.
 *
 * Query params:
 *   updated_since=YYYY-MM-DD HH:MM:SS  (optional) only rows updated after
 *                                      this timestamp; omit for a full feed.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

// --- Auth: shared bearer token (ENERGY_API_TOKEN) ---
$expected = trim((string)(function_exists('env_value') ? env_value('ENERGY_API_TOKEN', '') : (getenv('ENERGY_API_TOKEN') ?: '')));
if ($expected === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Feed disabled: ENERGY_API_TOKEN is not configured.']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$provided = '';
if (preg_match('/^Bearer\s+(\S+)$/i', trim((string)$authHeader), $m)) {
    $provided = $m[1];
} elseif (isset($_GET['key'])) {
    $provided = (string)$_GET['key'];
}

if ($provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: invalid or missing bearer token.']);
    exit;
}

try {
    $pdo = db();

    $sql = '
        SELECT id, name, description, location, capacity, operating_hours,
               latitude, longitude, status, created_at, updated_at
        FROM facilities
    ';
    $params = [];

    $updatedSince = trim((string)($_GET['updated_since'] ?? ''));
    if ($updatedSince !== '') {
        $ts = strtotime($updatedSince);
        if ($ts === false) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid updated_since timestamp.']);
            exit;
        }
        $sql .= ' WHERE updated_at > :updated_since';
        $params['updated_since'] = date('Y-m-d H:i:s', $ts);
    }

    $sql .= ' ORDER BY id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'location' => $row['location'] !== null ? (string)$row['location'] : null,
            'barangay' => 'Culiat',
            'capacity' => $row['capacity'] !== null ? (string)$row['capacity'] : null,
            'operating_hours' => $row['operating_hours'] !== null ? (string)$row['operating_hours'] : null,
            'latitude' => isset($row['latitude']) && $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) && $row['longitude'] !== null ? (float)$row['longitude'] : null,
            // CPRF statuses: available | maintenance | offline
            'status' => (string)$row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    echo json_encode([
        'success' => true,
        'source' => 'cprf',
        'primary_key' => 'id',
        'count' => count($data),
        'generated_at' => date('c'),
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Energy facilities feed error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error while building the facilities feed.']);
}
