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
$pdo = db();
$pageTitle = 'My Reservations | LGU Facilities Reservation';
$userId = $_SESSION['user_id'] ?? null;

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$reservations = [];
$totalRows = 0;

if ($userId) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = :user_id');
    $countStmt->execute(['user_id' => $userId]);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         WHERE r.user_id = :user_id
         ORDER BY r.reservation_date DESC, r.created_at DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span>My Reservations</span>
    </div>
    <h1>My Reservations</h1>
    <small>Track the status of your submitted bookings.</small>
</div>

<?php if (empty($reservations)): ?>
    <p>You have not submitted any reservations yet.</p>
<?php else: ?>
    <?php foreach ($reservations as $reservation): ?>
        <?php
        $historyStmt = $pdo->prepare(
            'SELECT status, note, created_at FROM reservation_history WHERE reservation_id = :id ORDER BY created_at DESC'
        );
        $historyStmt->execute(['id' => $reservation['id']]);
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <article class="facility-card-admin" style="margin-bottom:1rem;">
            <header>
                <div>
                    <h3><?= htmlspecialchars($reservation['facility_name']); ?></h3>
                    <small><?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?></small>
                </div>
                <span class="status-badge <?= $reservation['status']; ?>"><?= ucfirst($reservation['status']); ?></span>
            </header>
            <?php if ($history): ?>
                <ul class="timeline">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <strong><?= ucfirst($entry['status']); ?></strong>
                            <p style="margin:0;"><?= $entry['note'] ? htmlspecialchars($entry['note']) : 'No remarks.'; ?></p>
                            <small style="color:#8b95b5;"><?= htmlspecialchars($entry['created_at']); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No status updates recorded yet.</p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
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
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
