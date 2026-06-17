<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/paymongo_helper.php';
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/reservation_helpers.php';

if (!($_SESSION['user_authenticated'] ?? false) || empty($_SESSION['user_id'])) {
    header('Location: ' . base_path() . '/login');
    exit;
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];
$pageTitle = 'Pay Reservation | LGU Facilities Reservation';
$error = '';
$success = '';
$reservation = null;
$checkoutUrl = '';

$reservationId = (int)($_GET['reservation_id'] ?? $_POST['reservation_id'] ?? 0);
if ($reservationId <= 0) {
    $error = 'Invalid reservation selected.';
}

if (!$error) {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.user_id, r.status, r.reservation_date, r.time_slot, r.payment_due_at, f.name AS facility_name, f.base_rate
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         WHERE r.id = :id AND r.user_id = :uid
         LIMIT 1'
    );
    $stmt->execute(['id' => $reservationId, 'uid' => $userId]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$reservation) {
        $error = 'Reservation not found.';
    } elseif (($reservation['status'] ?? '') !== 'pending_payment') {
        $error = 'This reservation is not awaiting payment.';
    } elseif (!empty($reservation['payment_due_at']) && strtotime((string)$reservation['payment_due_at']) < time()) {
        autoDeclineExpiredReservations();
        $error = 'The payment window for this reservation has expired. Please book again.';
    }
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST[CSRF_TOKEN_NAME] ?? '');
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid session token. Please refresh and try again.';
    } elseif (!paymongoEnabled()) {
        $error = 'PayMongo is currently disabled. Please contact admin.';
    } else {
        $cfg = paymongoConfig();
        $rawRate = (string)($reservation['base_rate'] ?? '');
        $normalizedRate = preg_replace('/[^\d]/', '', $rawRate ?? '');
        $amountPhp = $normalizedRate !== '' ? (float)((int)$normalizedRate) : 0.0;
        if ($amountPhp <= 0) {
            $amountPhp = 50.00; // Demo fallback amount for facilities with zero base rate.
        }
        $amountCentavos = (int)round($amountPhp * 100);

        $successUrl = frs_paymongo_return_url((int)$reservation['id'], 'success');
        $cancelUrl = frs_paymongo_return_url((int)$reservation['id'], 'cancelled');

        $lineName = 'Facility Reservation #' . (int)$reservation['id'];
        $desc = $reservation['facility_name'] . ' on ' . date('F j, Y', strtotime($reservation['reservation_date'])) . ' (' . $reservation['time_slot'] . ')';

        $billingEmail = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
        $billingName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Resident'));

        $checkoutPayload = [
            'billing' => [
                'name' => $billingName !== '' ? $billingName : 'Resident',
                'email' => $billingEmail,
            ],
            'line_items' => [[
                'currency' => strtoupper((string)($cfg['currency'] ?? 'PHP')),
                'amount' => $amountCentavos,
                'name' => $lineName,
                'quantity' => 1,
                'description' => $desc,
            ]],
            'payment_method_types' => ['gcash', 'card', 'qrph'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'reference_number' => 'RES-' . (int)$reservation['id'],
            'description' => 'Reservation payment for LGU Facilities',
            'metadata' => [
                'reservation_id' => (string)$reservation['id'],
                'user_id' => (string)$userId,
            ],
        ];

        $resp = paymongoCreateCheckoutSession($checkoutPayload);
        if (!$resp['ok']) {
            $error = 'Unable to start payment: ' . ($resp['error'] ?? 'Unknown error');
        } else {
            $attrs = $resp['data']['data']['attributes'] ?? [];
            $checkoutId = (string)($resp['data']['data']['id'] ?? '');
            $checkoutUrl = (string)($attrs['checkout_url'] ?? '');
            $expiresAt = $attrs['expires_at'] ?? null;

            if ($checkoutId === '' || $checkoutUrl === '') {
                $error = 'PayMongo did not return a checkout URL.';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO payments (
                        reservation_id, user_id, provider, provider_checkout_id,
                        amount, currency, status, expires_at, payload_json
                    ) VALUES (
                        :reservation_id, :user_id, :provider, :checkout_id,
                        :amount, :currency, :status, :expires_at, :payload_json
                    )'
                );
                $insert->execute([
                    'reservation_id' => (int)$reservation['id'],
                    'user_id' => $userId,
                    'provider' => 'paymongo',
                    'checkout_id' => $checkoutId,
                    'amount' => $amountPhp,
                    'currency' => strtoupper((string)($cfg['currency'] ?? 'PHP')),
                    'status' => 'pending',
                    'expires_at' => $expiresAt ? date('Y-m-d H:i:s', strtotime((string)$expiresAt)) : null,
                    'payload_json' => json_encode($resp['data']),
                ]);

                logAudit(
                    'Created payment checkout',
                    'Payments',
                    'RES-' . (int)$reservation['id'] . ' / checkout ' . $checkoutId
                );

                $success = 'Checkout created. Click the button below to open PayMongo in a new tab and complete payment.';
            }
        }
    }
}

// Fallback sync: after returning from PayMongo success URL, verify checkout status and finalize if paid.
if (!$error && $reservation && isset($_GET['payment']) && $_GET['payment'] === 'success' && ($reservation['status'] ?? '') === 'pending_payment') {
    try {
        $latestPayStmt = $pdo->prepare(
            'SELECT id, provider_checkout_id, status
             FROM payments
             WHERE reservation_id = :reservation_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $latestPayStmt->execute(['reservation_id' => (int)$reservation['id']]);
        $latestPayment = $latestPayStmt->fetch(PDO::FETCH_ASSOC);

        if ($latestPayment && !empty($latestPayment['provider_checkout_id'])) {
            $checkoutResp = paymongoRetrieveCheckoutSession((string)$latestPayment['provider_checkout_id']);
            if (!empty($checkoutResp['ok'])) {
                $result = frs_finalize_reservation_payment($pdo, (int)$reservation['id'], $userId, $checkoutResp['data'] ?? []);
                if (!empty($result['ok'])) {
                    header('Location: ' . base_path() . '/dashboard/book-facility?module=mine&payment=success');
                    exit;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Payment return sync error: ' . $e->getMessage());
    }
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb"><span>Reservations</span><span class="sep">/</span><span>Payment</span></div>
    <?= frs_page_title('Complete Payment', 'Pencil bookings hold a slot briefly until payment is completed or the hold expires.'); ?>
</div>

<?php if ($error): ?>
    <div class="message error" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1rem;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="message success" style="background:#e8f5e9;color:#2e7d32;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1rem;">
        <?= htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($reservation && !$error): ?>
    <?php
    $rawDisplayRate = (string)($reservation['base_rate'] ?? '');
    $normalizedDisplayRate = preg_replace('/[^\d]/', '', $rawDisplayRate ?? '');
    $amount = $normalizedDisplayRate !== '' ? (float)((int)$normalizedDisplayRate) : 0.0;
    if ($amount <= 0) { $amount = 50.00; }
    ?>
    <div class="facility-card-admin" style="max-width:760px;">
        <h3 style="margin-top:0;">Reservation #<?= (int)$reservation['id']; ?></h3>
        <p><strong>Facility:</strong> <?= htmlspecialchars((string)$reservation['facility_name']); ?></p>
        <p><strong>Date:</strong> <?= htmlspecialchars(date('F j, Y', strtotime((string)$reservation['reservation_date']))); ?></p>
        <p><strong>Time:</strong> <?= htmlspecialchars((string)$reservation['time_slot']); ?></p>
        <p><strong>Amount:</strong> PHP <?= number_format($amount, 2); ?></p>
        <p style="color:#6b7280;">After successful payment, your reservation status changes to approved.</p>

        <form method="post" action="<?= base_path(); ?>/dashboard/pay-now">
            <?= function_exists('csrf_field') ? csrf_field() : '<input type="hidden" name="' . htmlspecialchars(CSRF_TOKEN_NAME, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">' ?>
            <input type="hidden" name="reservation_id" value="<?= (int)$reservation['id']; ?>">
            <button type="submit" class="btn-primary">Proceed to PayMongo</button>
            <a href="<?= base_path(); ?>/dashboard/book-facility?module=mine" class="btn-outline" style="margin-left:0.5rem; text-decoration:none;">Back</a>
        </form>
        <?php if (!empty($checkoutUrl)): ?>
            <div style="margin-top:0.9rem;">
                <a href="<?= htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn-primary" style="text-decoration:none;">
                    Open PayMongo in New Tab
                </a>
                <div style="margin-top:0.5rem; color:#6b7280; font-size:0.9rem;">
                    If your browser blocks popups, click this link manually.
                </div>
            </div>
            <script>
            (function () {
                var url = <?= json_encode($checkoutUrl, JSON_UNESCAPED_SLASHES); ?>;
                if (!url) return;
                try {
                    window.open(url, '_blank', 'noopener,noreferrer');
                } catch (e) {}
            })();
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
