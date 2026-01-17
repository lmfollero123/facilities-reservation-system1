<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';

header('Content-Type: application/json');

// Check authentication
if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$facilityId = isset($_POST['facility_id']) ? (int)$_POST['facility_id'] : 0;

if ($facilityId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid facility ID']);
    exit;
}

try {
    $pdo = db();
    
    // Fetch facility details
    $stmt = $pdo->prepare('SELECT id, name, location, capacity, capacity_threshold, description, amenities, rules, base_rate, status FROM facilities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $facilityId]);
    $facility = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facility) {
        http_response_code(404);
        echo json_encode(['error' => 'Facility not found']);
        exit;
    }
    
    echo json_encode($facility);
    
} catch (Throwable $e) {
    error_log('Facility details API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to fetch facility details']);
}
