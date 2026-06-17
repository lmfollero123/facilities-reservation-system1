<?php
/**
 * CLI: verify PayMongo payment and approve a pending_payment reservation.
 *
 * Usage (on server):
 *   cd /home/cprf.infragovservices.com/public_html
 *   php scripts/sync_reservation_payment.php 87
 *   php scripts/sync_reservation_payment.php 87 --verbose
 */
declare(strict_types=1);

$reservationId = (int)($argv[1] ?? 0);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

if ($reservationId <= 0) {
    fwrite(STDERR, "Usage: php scripts/sync_reservation_payment.php <reservation_id> [--verbose]\n");
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo_helper.php';

$pdo = db();

echo "Syncing payment for reservation #{$reservationId}...\n";

$resStmt = $pdo->prepare('SELECT id, user_id, status FROM reservations WHERE id = ? LIMIT 1');
$resStmt->execute([$reservationId]);
$reservation = $resStmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    fwrite(STDERR, "Reservation not found.\n");
    exit(1);
}

echo "  Status: " . ($reservation['status'] ?? 'unknown') . "\n";
echo "  Owner user_id: " . (int)($reservation['user_id'] ?? 0) . "\n";

$payStmt = $pdo->prepare(
    'SELECT id, provider_checkout_id, status, amount, created_at
     FROM payments
     WHERE reservation_id = ?
     ORDER BY id DESC'
);
$payStmt->execute([$reservationId]);
$payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$payments) {
    fwrite(STDERR, "No rows in payments table for this reservation.\n");
    fwrite(STDERR, "The checkout may never have been saved — resident must click Proceed to PayMongo again.\n");
    exit(2);
}

echo "  Payment rows: " . count($payments) . "\n";
foreach ($payments as $row) {
    echo "    - payment #{$row['id']} checkout={$row['provider_checkout_id']} status={$row['status']}\n";
}

if ($verbose) {
    foreach ($payments as $row) {
        $checkoutId = (string)($row['provider_checkout_id'] ?? '');
        if ($checkoutId === '') {
            continue;
        }
        $resp = paymongoRetrieveCheckoutSession($checkoutId);
        echo "\nPayMongo retrieve {$checkoutId}:\n";
        if (empty($resp['ok'])) {
            echo "  ERROR: " . ($resp['error'] ?? 'unknown') . "\n";
            continue;
        }
        echo "  API version: " . ($resp['api_version'] ?? '?') . "\n";
        echo "  is_paid: " . (paymongoCheckoutSessionIsPaid($resp['data'] ?? []) ? 'yes' : 'no') . "\n";
        if ($verbose) {
            $attrs = paymongoCheckoutSessionResource($resp['data'] ?? [])['attributes'] ?? [];
            echo "  session status: " . ($attrs['status'] ?? '(none)') . "\n";
            echo "  payments count: " . count($attrs['payments'] ?? []) . "\n";
        }
    }
    echo "\n";
}

$result = frs_try_sync_reservation_payment($pdo, $reservationId, null);

if (!empty($result['changed'])) {
    echo "SUCCESS: " . $result['message'] . "\n";
    exit(0);
}

if (!empty($result['ok'])) {
    echo "OK (no change): " . $result['message'] . "\n";
    exit(0);
}

fwrite(STDERR, "FAILED: " . ($result['message'] ?? 'Unknown error') . "\n");
exit(3);
