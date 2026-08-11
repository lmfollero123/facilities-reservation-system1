<?php
/**
 * Blocked-dates lookup for a single facility/month, used by the shared
 * date-picker widget (frs-blocked-datepicker.js) so staff can see maintenance
 * / blackout days before picking a new date on Modify, Postpone, or Extend.
 */
require_once __DIR__ . '/../../../../config/app.php';

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/blackout_dates.php';

$facilityId = (int)($_GET['facility_id'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);

if ($facilityId <= 0 || $year < 2000 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['error' => 'facility_id, year, and month are required']);
    exit;
}

$pdo = db();

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));

$facilityStmt = $pdo->prepare('SELECT status FROM facilities WHERE id = ? AND status != "deleted" LIMIT 1');
$facilityStmt->execute([$facilityId]);
$facilityStatus = $facilityStmt->fetchColumn();

if ($facilityStatus === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Facility not found']);
    exit;
}

// A facility flagged maintenance/offline with no dated blackout rows is
// blocked for the whole range (same rule book_facility.php's calendar uses).
$wholeRangeBlocked = false;
if (in_array($facilityStatus, ['maintenance', 'offline'], true)) {
    $anyDatedStmt = $pdo->prepare('SELECT 1 FROM facility_blackout_dates WHERE facility_id = ? LIMIT 1');
    $anyDatedStmt->execute([$facilityId]);
    $wholeRangeBlocked = $anyDatedStmt->fetchColumn() === false;
}

$blocked = [];
if ($wholeRangeBlocked) {
    $cursor = strtotime($startDate);
    $endTs = strtotime($endDate);
    while ($cursor <= $endTs) {
        $iso = date('Y-m-d', $cursor);
        $blocked[$iso] = strtoupper((string)$facilityStatus);
        $cursor = strtotime('+1 day', $cursor);
    }
} else {
    foreach (frs_list_blackout_dates_between($pdo, $startDate, $endDate, $facilityId) as $row) {
        $blocked[(string)$row['blackout_date']] = (string)($row['reason'] ?: 'Blackout');
    }
}

echo json_encode([
    'facility_id' => $facilityId,
    'facility_status' => $facilityStatus,
    'year' => $year,
    'month' => $month,
    'blocked_dates' => $blocked,
]);
