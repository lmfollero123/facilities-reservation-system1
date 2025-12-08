<?php
/**
 * AI Conflict Detection API Endpoint
 * Returns JSON response with conflict information
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['user_authenticated'] ?? false)) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facilityId = (int)($_POST['facility_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $timeSlot = $_POST['time_slot'] ?? '';

    if ($facilityId && $date && $timeSlot) {
        $conflictCheck = detectBookingConflict($facilityId, $date, $timeSlot);
        echo json_encode($conflictCheck);
    } else {
        echo json_encode(['error' => 'Missing parameters']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}


