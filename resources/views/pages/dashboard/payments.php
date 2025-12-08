<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
$pdo = db();
$pageTitle = 'Payments | LGU Facilities Reservation';

$message = '';
$messageType = 'success';

// Handle payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $orNumber = trim($_POST['or_number'] ?? '');
        $amount = trim($_POST['amount'] ?? '');
        $paymentDate = $_POST['payment_date'] ?? '';
        $paymentChannel = $_POST['payment_channel'] ?? 'Cash';
        $notes = trim($_POST['notes'] ?? '');

        if (!$reservationId || !$orNumber || !$amount || !$paymentDate) {
            $message = 'Please complete all required fields.';
            $messageType = 'error';
        } else {
            // Clean amount (remove currency symbols)
            $amount = preg_replace('/[^0-9.]/', '', $amount);
            $amount = (float)$amount;

            if ($amount <= 0) {
                $message = 'Amount must be greater than zero.';
                $messageType = 'error';
            } else {
                try {
                    // Check if OR number already exists
                    $orCheck = $pdo->prepare('SELECT id FROM payments WHERE or_number = ?');
                    $orCheck->execute([$orNumber]);
                    if ($orCheck->fetch()) {
                        $message = 'OR number already exists. Please use a different OR number.';
                        $messageType = 'error';
                    } else {
                        // Check if reservation exists and is approved
                        $resCheck = $pdo->prepare('SELECT id, status FROM reservations WHERE id = ?');
                        $resCheck->execute([$reservationId]);
                        $reservation = $resCheck->fetch(PDO::FETCH_ASSOC);

                        if (!$reservation) {
                            $message = 'Reservation not found.';
                            $messageType = 'error';
                        } else {
                            $stmt = $pdo->prepare(
                                'INSERT INTO payments (reservation_id, or_number, amount, payment_date, payment_channel, status, notes) 
                                 VALUES (:reservation_id, :or_number, :amount, :payment_date, :payment_channel, :status, :notes)'
                            );
                            $stmt->execute([
                                'reservation_id' => $reservationId,
                                'or_number' => $orNumber,
                                'amount' => $amount,
                                'payment_date' => $paymentDate,
                                'payment_channel' => $paymentChannel,
                                'status' => 'pending',
                                'notes' => $notes ?: null,
                            ]);

                            // Get reservation details for audit log
                            $resStmt = $pdo->prepare(
                                'SELECT r.id, f.name AS facility_name 
                                 FROM reservations r 
                                 JOIN facilities f ON r.facility_id = f.id 
                                 WHERE r.id = ?'
                            );
                            $resStmt->execute([$reservationId]);
                            $resInfo = $resStmt->fetch(PDO::FETCH_ASSOC);

                            logAudit('Recorded payment', 'Payments', 
                                'OR: ' . $orNumber . ' – ₱' . number_format($amount, 2) . 
                                ' for RES-' . $reservationId . ' (' . ($resInfo ? $resInfo['facility_name'] : 'Unknown') . ')');

                            $message = 'Payment recorded successfully.';
                        }
                    }
                } catch (Throwable $e) {
                    $message = 'Unable to record payment. Please try again.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($_POST['action'] === 'verify') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $verifyStatus = $_POST['verify_status'] ?? 'verified';

        if ($paymentId && in_array($verifyStatus, ['verified', 'rejected'], true)) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE payments 
                     SET status = :status, verified_by = :user_id, verified_at = CURRENT_TIMESTAMP 
                     WHERE id = :id'
                );
                $stmt->execute([
                    'status' => $verifyStatus,
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'id' => $paymentId,
                ]);

                // Get payment details for audit log
                $payStmt = $pdo->prepare('SELECT or_number, amount FROM payments WHERE id = ?');
                $payStmt->execute([$paymentId]);
                $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

                logAudit(ucfirst($verifyStatus) . ' payment', 'Payments', 
                    'OR: ' . ($payment ? $payment['or_number'] : 'Unknown') . 
                    ' – ₱' . ($payment ? number_format($payment['amount'], 2) : '0.00'));

                $message = 'Payment ' . $verifyStatus . ' successfully.';
            } catch (Throwable $e) {
                $message = 'Unable to update payment status. Please try again.';
                $messageType = 'error';
            }
        }
    }
}

// Get approved reservations for dropdown
$approvedReservations = [];
try {
    $resStmt = $pdo->query(
        'SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name, u.name AS requester_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         JOIN users u ON r.user_id = u.id
         WHERE r.status = "approved"
         ORDER BY r.reservation_date DESC, r.created_at DESC
         LIMIT 50'
    );
    $approvedReservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore error
}

// Pagination
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Count total payments
$totalStmt = $pdo->query('SELECT COUNT(*) FROM payments');
$totalRows = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch payments
$paymentsStmt = $pdo->prepare(
    'SELECT p.id, p.or_number, p.amount, p.payment_date, p.payment_channel, p.status, p.verified_at,
            r.id AS reservation_id, f.name AS facility_name, u.name AS payer_name
     FROM payments p
     JOIN reservations r ON p.reservation_id = r.id
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     ORDER BY p.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$paymentsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$paymentsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$paymentsStmt->execute();
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Finance</span><span class="sep">/</span><span>Payments</span>
    </div>
    <h1>Payments & OR Tracking</h1>
    <small>Record payments, issue OR numbers, and monitor verification status.</small>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Record Payment</h2>
        <form class="booking-form" method="POST">
            <input type="hidden" name="action" value="create">
            
            <label>
                Reservation Reference
                <div class="input-wrapper">
                    <span class="input-icon">🧾</span>
                    <select name="reservation_id" required>
                        <option value="">Select a reservation...</option>
                        <?php foreach ($approvedReservations as $reservation): ?>
                            <option value="<?= $reservation['id']; ?>">
                                RES-<?= $reservation['id']; ?> – <?= htmlspecialchars($reservation['facility_name']); ?> 
                                (<?= htmlspecialchars($reservation['reservation_date']); ?> <?= htmlspecialchars($reservation['time_slot']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small style="color:#8b95b5; font-size:0.85rem;">Only approved reservations are shown.</small>
            </label>

            <label>
                Official Receipt (OR) Number
                <div class="input-wrapper">
                    <span class="input-icon">#</span>
                    <input type="text" name="or_number" placeholder="2025-00123" required>
                </div>
            </label>

            <label>
                Amount Paid
                <div class="input-wrapper">
                    <span class="input-icon">💰</span>
                    <input type="text" name="amount" placeholder="₱0.00" required>
                </div>
            </label>

            <label>
                Payment Date
                <div class="input-wrapper">
                    <span class="input-icon">📅</span>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d'); ?>" required>
                </div>
            </label>

            <label>
                Payment Channel
                <div class="input-wrapper">
                    <span class="input-icon">🏦</span>
                    <select name="payment_channel" required>
                        <option value="Cash">Cash</option>
                        <option value="Check">Check</option>
                        <option value="Online Transfer">Online Transfer</option>
                    </select>
                </div>
            </label>

            <label>
                Notes (Optional)
                <textarea name="notes" rows="2" placeholder="Additional notes about this payment..."></textarea>
            </label>

            <button class="btn-primary" type="submit">Save Payment</button>
        </form>
    </section>

    <aside class="booking-card">
        <h2>Recent Payments</h2>
        <?php if (empty($payments)): ?>
            <p>No payments recorded yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>OR Number</th>
                    <th>Payer</th>
                    <th>Facility</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($payment['or_number']); ?></strong></td>
                        <td><?= htmlspecialchars($payment['payer_name']); ?></td>
                        <td><?= htmlspecialchars($payment['facility_name']); ?></td>
                        <td>₱<?= number_format($payment['amount'], 2); ?></td>
                        <td>
                            <?php
                            $statusClass = $payment['status'] === 'verified' ? 'active' : 
                                         ($payment['status'] === 'rejected' ? 'offline' : 'maintenance');
                            $statusDisplay = ucfirst($payment['status']);
                            if ($payment['status'] === 'pending') {
                                $statusDisplay = 'Pending Verification';
                            }
                            ?>
                            <span class="status-badge <?= $statusClass; ?>"><?= $statusDisplay; ?></span>
                        </td>
                        <td>
                            <?php if ($payment['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="verify">
                                    <input type="hidden" name="payment_id" value="<?= $payment['id']; ?>">
                                    <input type="hidden" name="verify_status" value="verified">
                                    <button class="btn-primary confirm-action" data-message="Verify this payment?" type="submit" style="padding:0.35rem 0.6rem; font-size:0.85rem;">Verify</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="verify">
                                    <input type="hidden" name="payment_id" value="<?= $payment['id']; ?>">
                                    <input type="hidden" name="verify_status" value="rejected">
                                    <button class="btn-outline confirm-action" data-message="Reject this payment?" type="submit" style="padding:0.35rem 0.6rem; font-size:0.85rem;">Reject</button>
                                </form>
                            <?php else: ?>
                                <small style="color:#8b95b5;">
                                    <?= $payment['verified_at'] ? date('M d, Y', strtotime($payment['verified_at'])) : ''; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top:1rem;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1; ?>">&larr; Prev</a>
                    <?php endif; ?>
                    <span class="current">Page <?= $page; ?> of <?= $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1; ?>">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </aside>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
