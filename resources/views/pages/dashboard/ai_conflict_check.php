<?php
/**
 * AI Conflict Detection API Endpoint
 * Returns JSON response with conflict information and demand predictions
 */
require_once __DIR__ . '/../../../../config/app.php';

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';
require_once __DIR__ . '/../../../../services/PredictionService.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    frs_reject_invalid_csrf_json();
    $facilityId = (int)($_POST['facility_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $timeSlot = $_POST['time_slot'] ?? '';
    $excludeReservationId = !empty($_POST['exclude_reservation_id']) ? (int)$_POST['exclude_reservation_id'] : null;

    if ($facilityId && $date && $timeSlot) {
        $conflictCheck = detectBookingConflict($facilityId, $date, $timeSlot, $excludeReservationId);
        
        // Add demand prediction using PredictionService
        $pdo = db();
        $predictionService = new PredictionService($pdo);
        $demandPrediction = $predictionService->predictDemand($facilityId, $date, $timeSlot);
        
        // Get alternative slots if demand is high
        $alternatives = [];
        if ($demandPrediction['score'] >= 50) {
            $alternatives = $predictionService->getAlternativeSlots($facilityId, $date, $timeSlot);
        }
        
        // Merge demand prediction into conflict check
        $conflictCheck['demand_score'] = $demandPrediction['score'];
        $conflictCheck['demand_classification'] = $demandPrediction['classification'];
        $conflictCheck['demand_confidence'] = $demandPrediction['confidence'];
        $conflictCheck['demand_factors'] = $demandPrediction['factors'];
        $conflictCheck['demand_alternatives'] = $alternatives;
        
        // Lightweight logging for future ML training
        try {
            $logLine = json_encode([
                'ts' => date('c'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'facility_id' => $facilityId,
                'date' => $date,
                'time_slot' => $timeSlot,
                'has_conflict' => $conflictCheck['has_conflict'] ?? false,
                'risk_score' => $conflictCheck['risk_score'] ?? null,
                'demand_score' => $demandPrediction['score'] ?? null
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




