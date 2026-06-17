<?php
/**
 * Public PayMongo return/cancel landing page.
 * Must respond with HTTP 200 so PayMongo can validate success_url / cancel_url.
 */
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/paymongo_helper.php';

$reservationId = (int)($_GET['reservation_id'] ?? 0);
$paymentOutcome = trim((string)($_GET['payment'] ?? ''));
$pageTitle = 'Payment Status | LGU Facilities Reservation';
$message = '';
$messageType = 'info';
$redirectUrl = null;

if ($paymentOutcome === 'cancelled') {
    $message = 'Payment was cancelled. You can try again from My Reservations.';
    $messageType = 'warning';
} elseif ($paymentOutcome === 'success') {
    $message = 'Thank you. If your payment was successful, your reservation will be confirmed shortly.';
    $messageType = 'success';

    if ($reservationId > 0 && frs_dashboard_is_authenticated()) {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                'SELECT id, user_id, status
                 FROM reservations
                 WHERE id = :id AND user_id = :uid
                 LIMIT 1'
            );
            $stmt->execute(['id' => $reservationId, 'uid' => $userId]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($reservation && ($reservation['status'] ?? '') === 'pending_payment') {
                $latestPayStmt = $pdo->prepare(
                    'SELECT provider_checkout_id
                     FROM payments
                     WHERE reservation_id = :reservation_id AND user_id = :user_id
                     ORDER BY id DESC
                     LIMIT 1'
                );
                $latestPayStmt->execute(['reservation_id' => $reservationId, 'user_id' => $userId]);
                $checkoutId = (string)$latestPayStmt->fetchColumn();

                if ($checkoutId !== '') {
                    $checkoutResp = paymongoRetrieveCheckoutSession($checkoutId);
                    if (!empty($checkoutResp['ok'])) {
                        $result = frs_finalize_reservation_payment($pdo, $reservationId, $userId, $checkoutResp['data'] ?? []);
                        if (!empty($result['ok'])) {
                            header('Location: ' . base_path() . '/dashboard/book-facility?module=mine&payment=success');
                            exit;
                        }
                    }
                }
            } elseif ($reservation && ($reservation['status'] ?? '') === 'approved') {
                header('Location: ' . base_path() . '/dashboard/book-facility?module=mine&payment=success');
                exit;
            }
        } catch (Throwable $e) {
            error_log('Payment return sync error: ' . $e->getMessage());
        }
    }

    if (frs_dashboard_is_authenticated()) {
        $redirectUrl = base_path() . '/dashboard/book-facility?module=mine';
    } else {
        $message .= ' Please log in to view your reservation status.';
    }
} else {
    $message = 'Payment return page.';
}

$bg = '#eef2ff';
$color = '#1e3a8a';
if ($messageType === 'success') {
    $bg = '#e8f5e9';
    $color = '#2e7d32';
} elseif ($messageType === 'warning') {
    $bg = '#fff4e5';
    $color = '#856404';
}

http_response_code(200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f7fb; margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        .card { background: #fff; border-radius: 12px; padding: 2rem; max-width: 520px; width: 100%; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); }
        .msg { background: <?= $bg; ?>; color: <?= $color; ?>; padding: 1rem; border-radius: 8px; line-height: 1.6; }
        .actions { margin-top: 1.25rem; display: flex; gap: 0.75rem; flex-wrap: wrap; }
        a.btn { display: inline-block; padding: 0.65rem 1rem; border-radius: 8px; text-decoration: none; font-weight: 600; }
        a.primary { background: #2563eb; color: #fff; }
        a.secondary { background: #fff; color: #2563eb; border: 1px solid #cbd5e1; }
    </style>
</head>
<body>
    <div class="card">
        <h1 style="margin-top:0;font-size:1.35rem;">Payment Status</h1>
        <div class="msg"><?= htmlspecialchars($message); ?></div>
        <div class="actions">
            <?php if ($redirectUrl): ?>
                <a class="btn primary" href="<?= htmlspecialchars($redirectUrl); ?>">View My Reservations</a>
            <?php else: ?>
                <a class="btn primary" href="<?= htmlspecialchars(base_path() . '/login?next=' . rawurlencode(base_path() . '/dashboard/book-facility?module=mine')); ?>">Log In</a>
            <?php endif; ?>
            <a class="btn secondary" href="<?= htmlspecialchars(base_path() . '/'); ?>">Home</a>
        </div>
    </div>
</body>
</html>
