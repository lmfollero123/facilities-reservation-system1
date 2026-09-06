<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/booking_calendar_status.php';
require_once __DIR__ . '/../../../../services/PredictionService.php';
require_once __DIR__ . '/../../../../services/HolidayService.php';

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$facilityId = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

if ($facilityId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'facility_id is required']);
    exit;
}
if ($month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid month']);
    exit;
}
if ($year < 2000 || $year > 2100) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
    exit;
}

try {
    $pdo = db();
    $todayISO = date('Y-m-d');

    $toneMatrix = frs_facility_calendar_matrix($pdo, $facilityId, $year, $month);

    // Same 60-day-advance demand forecast averaging book_facility.php did
    // inline before this endpoint existed (see that file's git history for
    // the pre-extraction version of this block).
    $demandMatrix = [];
    $predictionService = new PredictionService($pdo);
    $monthForecast = $predictionService->getFacilityDemandForecast($facilityId, 60);
    foreach ($monthForecast as $dayForecast) {
        $date = $dayForecast['date'];
        $slots = $dayForecast['slots'] ?? [];
        $dataBackedSlots = array_filter($slots, fn($slot) => !empty($slot['has_sufficient_data']));
        if (empty($dataBackedSlots)) {
            continue;
        }
        $totalScore = 0;
        foreach ($dataBackedSlots as $slot) {
            $totalScore += $slot['score'];
        }
        $avgScore = (int)round($totalScore / count($dataBackedSlots));
        $classification = 'Low';
        if ($avgScore >= 76) {
            $classification = 'Very High';
        } elseif ($avgScore >= 51) {
            $classification = 'High';
        } elseif ($avgScore >= 26) {
            $classification = 'Medium';
        }
        $demandMatrix[$date] = ['score' => $avgScore, 'classification' => $classification];
    }

    $holidayMatrix = [];
    $holidayService = new HolidayService();
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, (int)date('t', mktime(0, 0, 0, $month, 1, $year)));
    foreach ($holidayService->getHolidaysInRange($monthStart, $monthEnd) as $holiday) {
        $holidayMatrix[$holiday['date']] = $holiday;
    }

    $entries = frs_bcf_calendar_day_entries($todayISO, $year, $month, $toneMatrix, $demandMatrix, $holidayMatrix);

    echo json_encode([
        'facility_id' => $facilityId,
        'year' => $year,
        'month' => $month,
        'today' => $todayISO,
        'days' => $entries,
    ]);
} catch (Throwable $e) {
    error_log('book-facility-calendar-data API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load calendar data']);
}
