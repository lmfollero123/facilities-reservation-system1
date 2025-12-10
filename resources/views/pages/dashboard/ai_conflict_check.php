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
        // Lightweight logging for future ML training
        try {
            $logLine = json_encode([
                'ts' => date('c'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'facility_id' => $facilityId,
                'date' => $date,
                'time_slot' => $timeSlot,
                'has_conflict' => $conflictCheck['has_conflict'] ?? false,
                'risk_score' => $conflictCheck['risk_score'] ?? null
            ]) . PHP_EOL;
            $logFile = __DIR__ . '/../../../../logs/conflict_checks.log';
            @file_put_contents($logFile, $logLine, FILE_APPEND);
        } catch (Throwable $e) {
            // ignore logging errors
        }
        echo json_encode($conflictCheck);
    } else {
        echo json_encode(['error' => 'Missing parameters']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}




