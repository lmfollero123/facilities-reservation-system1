<?php
/**
 * Walk-in booking — searchable resident lookup (Staff/Admin only).
 * Returns at most 10 residents per request; use ?q= to search by name or email.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? 'Resident');
if (!in_array($role, ['Admin', 'Staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';

$pdo = db();
$limit = min(10, max(1, (int)($_GET['limit'] ?? 10)));
$id = (int)($_GET['id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            "SELECT id, name, email FROM users WHERE id = ? AND role = 'Resident' AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'residents' => $row ? [$row] : [], 'has_more' => false]);
        exit;
    }

    $fetchLimit = $limit + 1;

    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            "SELECT id, name, email FROM users
             WHERE role = 'Resident' AND status = 'active'
               AND (name LIKE ? OR email LIKE ?)
             ORDER BY name ASC
             LIMIT {$fetchLimit}"
        );
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $pdo->query(
            "SELECT id, name, email FROM users
             WHERE role = 'Resident' AND status = 'active'
             ORDER BY name ASC
             LIMIT {$fetchLimit}"
        );
    }

    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }

    echo json_encode([
        'success' => true,
        'residents' => $rows,
        'has_more' => $hasMore,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load residents.']);
}
