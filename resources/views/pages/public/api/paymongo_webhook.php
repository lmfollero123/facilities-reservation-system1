<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../../config/app.php';
require_once __DIR__ . '/../../../../../config/database.php';
require_once __DIR__ . '/../../../../../config/notifications.php';
require_once __DIR__ . '/../../../../../config/audit.php';
require_once __DIR__ . '/../../../../../config/paymongo_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$payload = file_get_contents('php://input') ?: '';
$signatureHeader = (string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? $_SERVER['HTTP_PAYmongo_SIGNATURE'] ?? '');

if (!paymongoVerifyWebhookSignature($payload, $signatureHeader)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid webhook signature']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$eventId = (string)($event['data']['id'] ?? '');
$eventType = (string)($event['data']['attributes']['type'] ?? '');
$resource = $event['data']['attributes']['data'] ?? [];
$checkoutId = (string)($resource['id'] ?? '');
if ($checkoutId === '') {
    $checkoutId = (string)($resource['attributes']['checkout_session_id'] ?? '');
}
if ($checkoutId === '') {
    $checkoutId = (string)($resource['attributes']['checkout_session']['id'] ?? '');
}
$reservationIdFromMeta = (int)($resource['attributes']['metadata']['reservation_id']
    ?? $resource['attributes']['checkout_session']['attributes']['metadata']['reservation_id']
    ?? 0);

if ($checkoutId === '' && $reservationIdFromMeta <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing checkout or reservation reference']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    if ($checkoutId !== '') {
        $payStmt = $pdo->prepare(
            'SELECT id, reservation_id, user_id, status
             FROM payments
             WHERE provider_checkout_id = :checkout_id
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $payStmt->execute(['checkout_id' => $checkoutId]);
    } else {
        $payStmt = $pdo->prepare(
            'SELECT id, reservation_id, user_id, status
             FROM payments
             WHERE reservation_id = :reservation_id
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $payStmt->execute(['reservation_id' => $reservationIdFromMeta]);
    }
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment record not found']);
        exit;
    }

    $paymentId = (int)$payment['id'];
    $reservationId = (int)$payment['reservation_id'];
    $userId = (int)$payment['user_id'];
    $isSuccess = (stripos($eventType, 'payment.paid') !== false);
    $isFailed = (stripos($eventType, 'payment.failed') !== false || stripos($eventType, 'checkout_session.expired') !== false);

    if ($isSuccess) {
        $updatePay = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 provider_event_id = :event_id,
                 paid_at = NOW(),
                 payload_json = :payload_json
             WHERE id = :id'
        );
        $updatePay->execute([
            'status' => 'paid',
            'event_id' => $eventId,
            'payload_json' => $payload,
            'id' => $paymentId,
        ]);

        $updateReservation = $pdo->prepare(
            'UPDATE reservations
             SET status = :status, auto_approved = 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :from_status'
        );
        $updateReservation->execute([
            'status' => 'approved',
            'id' => $reservationId,
            'from_status' => 'pending_payment',
        ]);

        $hist = $pdo->prepare(
            'INSERT INTO reservation_history (reservation_id, status, note, created_by)
             VALUES (:reservation_id, :status, :note, NULL)'
        );
        $hist->execute([
            'reservation_id' => $reservationId,
            'status' => 'approved',
            'note' => 'Payment confirmed via PayMongo. Reservation secured.',
        ]);

        createNotification(
            $userId,
            'booking',
            'Payment Confirmed',
            'Your payment was successful. Reservation #' . $reservationId . ' is now approved.',
            base_path() . '/dashboard/book-facility?module=mine'
        );
    } elseif ($isFailed) {
        $updatePay = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 provider_event_id = :event_id,
                 payload_json = :payload_json
             WHERE id = :id'
        );
        $updatePay->execute([
            'status' => 'failed',
            'event_id' => $eventId,
            'payload_json' => $payload,
            'id' => $paymentId,
        ]);
    } else {
        // Unknown event for this flow; record payload but keep status unchanged.
        $updatePay = $pdo->prepare(
            'UPDATE payments
             SET provider_event_id = :event_id,
                 payload_json = :payload_json
             WHERE id = :id'
        );
        $updatePay->execute([
            'event_id' => $eventId,
            'payload_json' => $payload,
            'id' => $paymentId,
        ]);
    }

    $pdo->commit();

    logAudit(
        'Processed PayMongo webhook',
        'Payments',
        'Event ' . ($eventId ?: 'unknown') . ' for checkout ' . $checkoutId
    );

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('PayMongo webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
