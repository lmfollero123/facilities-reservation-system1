<?php
require_once __DIR__ . '/../../../../../config/database.php';
require_once __DIR__ . '/../../../../../config/reservation_helpers.php';

header('Content-Type: application/json');

$pdo = db();

$date = $_GET['date'] ?? null;
if (!$date) {
    echo json_encode(['error' => 'Date is required']);
    exit;
}

$OPEN_TIME  = '08:00';
$CLOSE_TIME = '21:00';

$facilities = $pdo->query("
    SELECT id, name, status
    FROM facilities
    WHERE status != 'deleted'
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$response = [
    'date' => $date,
    'facilities' => []
];

foreach ($facilities as $facility) {

    $facilityData = [
        'facility_name' => $facility['name'],
        'status' => $facility['status'],
        'timeline' => []
    ];

    // Maintenance / Offline
    if (in_array($facility['status'], ['maintenance', 'offline'])) {
        $facilityData['timeline'][] = [
            'type' => 'blocked',
            'label' => strtoupper($facility['status'])
        ];
        $response['facilities'][] = $facilityData;
        continue;
    }

    $stmt = $pdo->prepare("
        SELECT time_slot, status, payment_due_at, expires_at
        FROM reservations
        WHERE facility_id = ?
          AND reservation_date = ?
          AND status IN ('approved', 'pending', 'pending_payment', 'postponed')
        ORDER BY time_slot
    ");
    $stmt->execute([$facility['id'], $date]);

    $bookedRanges = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!frs_reservation_blocks_booking($row)) {
            continue;
        }
        $slot = (string)$row['time_slot'];
        if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $slot, $m)) {
            $bookedRanges[] = [$m[1], $m[2]];
        }
    }

    $cursor = $OPEN_TIME;

    foreach ($bookedRanges as [$start, $end]) {

        // Available before booking
        if ($cursor < $start) {
            $facilityData['timeline'][] = [
                'type' => 'available',
                'range' => "$cursor – $start"
            ];
        }

        // Booked slot
        $facilityData['timeline'][] = [
            'type' => 'booked',
            'range' => "$start – $end"
        ];

        $cursor = max($cursor, $end);
    }

    // Remaining availability
    if ($cursor < $CLOSE_TIME) {
        $facilityData['timeline'][] = [
            'type' => 'available',
            'range' => "$cursor – $CLOSE_TIME"
        ];
    }

    $response['facilities'][] = $facilityData;
}

echo json_encode($response);
