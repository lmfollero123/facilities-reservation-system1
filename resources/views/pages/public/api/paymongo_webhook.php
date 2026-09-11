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

$parsed = paymongoParseWebhookPayload($event);
$eventId = $parsed['event_id'];
$eventType = $parsed['event_type'];
$checkoutId = $parsed['checkout_id'];
$reservationIdFromMeta = $parsed['reservation_id'];
$paymongoPaymentId = (string)($parsed['payment_id'] ?? '');
if ($paymongoPaymentId === '') {
    $paymongoPaymentId = paymongoExtractPaymentId($event);
}

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
    $isSuccess = (stripos($eventType, 'payment.paid') !== false || stripos($eventType, 'checkout_session.paid') !== false);
    $isFailed = (stripos($eventType, 'payment.failed') !== false || stripos($eventType, 'checkout_session.expired') !== false);

    if ($isSuccess) {
        // Prefer PayMongo payment resource id (pay_...) for refunds; keep event id only as fallback.
        $providerId = $paymongoPaymentId !== '' ? $paymongoPaymentId : $eventId;

        // Idempotency: PayMongo delivers at-least-once. If this payment is
        // already settled, a duplicate event must not re-approve, re-refund,
        // or fire another notification — just backfill the provider id and stop.
        if (in_array((string) ($payment['status'] ?? ''), ['paid', 'refunded'], true)) {
            $pdo->prepare(
                'UPDATE payments SET provider_event_id = COALESCE(NULLIF(provider_event_id, ""), :event_id) WHERE id = :id'
            )->execute(['event_id' => $providerId, 'id' => $paymentId]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Already processed']);
            exit;
        }

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
            'event_id' => $providerId,
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
        $approvedNow = $updateReservation->rowCount() === 1;

        $hist = $pdo->prepare(
            'INSERT INTO reservation_history (reservation_id, status, note, created_by)
             VALUES (:reservation_id, :status, :note, NULL)'
        );

        if ($approvedNow) {
            $hist->execute([
                'reservation_id' => $reservationId,
                'status' => 'approved',
                'note' => 'Payment confirmed via PayMongo webhook (' . ($eventType ?: 'payment.paid') . ').',
            ]);
            createNotification(
                $userId,
                'booking',
                'Payment Confirmed',
                'Your payment was successful. Reservation #' . $reservationId . ' is now approved.',
                base_path() . '/dashboard/reservation-detail?id=' . $reservationId
            );
        } else {
            // The reservation was no longer awaiting payment when the paid event
            // landed. Find out why and act correctly rather than falsely telling
            // the resident it was approved.
            $curStmt = $pdo->prepare('SELECT status FROM reservations WHERE id = ? LIMIT 1');
            $curStmt->execute([$reservationId]);
            $curStatus = (string) ($curStmt->fetchColumn() ?: '');

            if ($curStatus === 'approved') {
                // Already approved (e.g. a manual payment-sync beat the webhook).
                // Record the confirmation but do not send a duplicate notice.
                $hist->execute([
                    'reservation_id' => $reservationId,
                    'status' => 'approved',
                    'note' => 'Payment confirmed via PayMongo webhook; reservation was already approved.',
                ]);
            } else {
                // Paid for a slot that is no longer held (auto-declined on
                // expiry, or cancelled/denied by staff). Refund and tell the
                // resident the truth — never claim approval.
                require_once dirname(__DIR__, 5) . '/config/paymongo_helper.php';
                $fullPayStmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
                $fullPayStmt->execute([$paymentId]);
                $fullPayRow = $fullPayStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $refund = frs_refund_payment_row(
                    $pdo,
                    $fullPayRow,
                    'requested_by_customer',
                    'Auto-refund: reservation was no longer available (status=' . $curStatus . ') when payment confirmed.'
                );

                $refunded = !empty($refund['refunded']);
                $hist->execute([
                    'reservation_id' => $reservationId,
                    'status' => $curStatus !== '' ? $curStatus : 'cancelled',
                    'note' => 'Payment received via PayMongo after the reservation was ' . ($curStatus ?: 'closed')
                        . '. ' . ($refunded ? 'Automatically refunded.' : 'Automatic refund needs staff follow-up: ' . ($refund['message'] ?? '')),
                ]);

                createNotification(
                    $userId,
                    'booking',
                    $refunded ? 'Payment refunded' : 'Payment received — refund pending',
                    $refunded
                        ? 'Your payment for reservation #' . $reservationId . ' was refunded because the slot was no longer available.'
                        : 'We received your payment for reservation #' . $reservationId . ', but the slot was no longer available. Our staff will process your refund.',
                    base_path() . '/dashboard/reservation-detail?id=' . $reservationId
                );

                if (!$refunded) {
                    error_log('PayMongo webhook: refund needed but not completed for payment #' . $paymentId . ' reservation #' . $reservationId . ': ' . ($refund['message'] ?? ''));
                }
            }
        }
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
            'event_id' => ($paymongoPaymentId !== '' ? $paymongoPaymentId : $eventId),
            'payload_json' => $payload,
            'id' => $paymentId,
        ]);
    } else {
        $updatePay = $pdo->prepare(
            'UPDATE payments
             SET provider_event_id = COALESCE(NULLIF(:event_id, ""), provider_event_id),
                 payload_json = :payload_json
             WHERE id = :id'
        );
        $updatePay->execute([
            'event_id' => ($paymongoPaymentId !== '' ? $paymongoPaymentId : $eventId),
            'payload_json' => $payload,
            'id' => $paymentId,
        ]);
    }

    $pdo->commit();

    logAudit(
        'Processed PayMongo webhook',
        'Payments',
        'Event ' . ($eventId ?: 'unknown') . ' type ' . ($eventType ?: 'unknown') . ' checkout ' . $checkoutId
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
