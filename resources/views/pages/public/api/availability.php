<?php
// Suppress any output before JSON
ob_start();

// Set JSON header immediately
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../../../config/database.php';
    
    // Set error reporting for API (don't display errors, just log them)
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    
    $pdo = db();
    
    // Set PDO to throw exceptions
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;
    if (!$date) {
        ob_clean();
        echo json_encode(['error' => 'Date is required']);
        exit;
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        ob_clean();
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
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

    // Fetch booked slots
    $stmt = $pdo->prepare("
        SELECT time_slot
        FROM reservations
        WHERE facility_id = ?
          AND reservation_date = ?
          AND status IN ('approved', 'pending')
        ORDER BY time_slot
    ");
    $stmt->execute([$facility['id'], $date]);
    $bookedSlots = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $bookedRanges = [];
    foreach ($bookedSlots as $slot) {
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

    // Available after last booking
    if ($cursor < $CLOSE_TIME) {
        $facilityData['timeline'][] = [
            'type' => 'available',
            'range' => "$cursor – $CLOSE_TIME"
        ];
    }

    $response['facilities'][] = $facilityData;
}

ob_clean();
echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}
