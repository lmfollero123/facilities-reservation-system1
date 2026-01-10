<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';

$base = base_path();
$pdo = db();

// Get facility ID from query string
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: ' . $base . '/resources/views/pages/public/facilities.php');
    exit;
}

// Load facility from DB
$stmt = $pdo->prepare('SELECT *, image_citation FROM facilities WHERE id = ?');
$stmt->execute([$id]);
$facility = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$facility) {
    header('Location: ' . $base . '/resources/views/pages/public/facilities.php');
    exit;
}

$pageTitle = htmlspecialchars($facility['name']) . ' | LGU Facilities Reservation';

// Build simple 14‑day availability snapshot using real reservations
$calendar = [];
$today = new DateTimeImmutable('today');

$resStmt = $pdo->prepare(
    'SELECT reservation_date, COUNT(*) AS cnt
     FROM reservations
     WHERE facility_id = :facility_id
       AND reservation_date BETWEEN :start AND :end
       AND status IN ("pending", "approved")
     GROUP BY reservation_date'
);
$startDate = $today->format('Y-m-d');
$endDate = $today->modify('+13 days')->format('Y-m-d');
$resStmt->execute([
    'facility_id' => $facility['id'],
    'start' => $startDate,
    'end' => $endDate,
]);

$byDate = [];
foreach ($resStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $byDate[$row['reservation_date']] = (int)$row['cnt'];
}

for ($i = 0; $i < 14; $i++) {
    $date = $today->modify("+{$i} days");
    $key = $date->format('Y-m-d');
    $hasBookings = isset($byDate[$key]);
    $calendar[] = [
        'label' => $date->format('M j'),
        'status' => $facility['status'] !== 'available'
            ? 'unavailable'
            : ($hasBookings ? 'unavailable' : 'available'),
    ];
}

ob_start();
?>
<section class="section facility-details-section">
    <div class="container facility-detail-layout">
        <div class="facility-detail-main">
            <div class="facility-hero-card">
                <?php
                $imageUrl = null;
                if (!empty($facility['image_path'])) {
                    $imageUrl = $base . $facility['image_path'];
                }
                ?>
                <?php if ($imageUrl): ?>
                    <div class="facility-hero-image-wrapper">
                        <div class="facility-hero-image" style="background-image:url('<?= htmlspecialchars($imageUrl); ?>');"></div>
                        <?php if (!empty($facility['image_citation'])): ?>
                            <div class="image-citation" title="Image Source">
                                <small>📷 <?= htmlspecialchars($facility['image_citation']); ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="facility-hero-placeholder">
                        <span class="icon">🏛️</span>
                    </div>
                <?php endif; ?>
                <div class="facility-hero-body">
                    <div class="facility-hero-header">
                        <h2><?= htmlspecialchars($facility['name']); ?></h2>
                        <span class="status-pill <?= $facility['status'] === 'available' ? 'status-available' : 'status-booked'; ?>">
                            <?= ucfirst(htmlspecialchars($facility['status'])); ?>
                        </span>
                    </div>
                    <?php if (!empty($facility['location']) || !empty($facility['capacity'])): ?>
                        <p class="facility-meta">
                            <?php if (!empty($facility['location'])): ?>
                                <span><strong>Location:</strong> <?= htmlspecialchars($facility['location']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($facility['capacity'])): ?>
                                <span><strong>Capacity:</strong> <?= htmlspecialchars($facility['capacity']); ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <p class="facility-description">
                        <?= nl2br(htmlspecialchars($facility['description'] ?: 'LGU-owned facility available for reservation.')); ?>
                    </p>
                </div>
            </div>

            <div class="facility-detail-sections">
                <section class="facility-section">
                    <h3>Usage</h3>
                    <p class="rate-text"><strong>Free of Charge</strong></p>
                    <p style="color:#4c5b7c; font-size:0.9rem; margin-top:0.5rem;">This facility is provided free of charge for public use by the LGU/Barangay.</p>
                </section>

                <?php if (!empty($facility['amenities'])): ?>
                    <section class="facility-section">
                        <h3>Amenities</h3>
                        <p><?= nl2br(htmlspecialchars($facility['amenities'])); ?></p>
                    </section>
                <?php endif; ?>

                <?php if (!empty($facility['rules'])): ?>
                    <section class="facility-section">
                        <h3>Rules & Regulations</h3>
                        <ol class="rules-list">
                            <?php foreach (preg_split('/\r\n|\r|\n/', $facility['rules']) as $rule): ?>
                                <?php if (trim($rule) !== ''): ?>
                                    <li><?= htmlspecialchars($rule); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                <?php endif; ?>
            </div>
        </div>

        <aside class="facility-detail-sidebar">
            <div class="facility-availability-card">
                <h3>Availability (Next 14 Days)</h3>
                <div class="calendar" role="grid">
                    <?php foreach ($calendar as $slot): ?>
                        <div class="day <?= $slot['status']; ?>" data-label="<?= htmlspecialchars($slot['label']); ?>">
                            <?= htmlspecialchars($slot['label']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="availability-note">
                    For full availability and to submit a reservation request, please log in and use the booking module.
                </p>
            </div>

            <div style="background:#fff4e5; border:1px solid #ffc107; border-radius:8px; padding:1rem; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#856404; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚠️</span> Important Notice
                </h4>
                <p style="margin:0; color:#856404; font-size:0.85rem; line-height:1.5;">
                    <strong>Emergency Override Policy:</strong> In case of emergencies (e.g., evacuation centers, disaster response, urgent LGU/Barangay needs), 
                    the LGU reserves the right to override or cancel existing reservations. Affected residents will be notified immediately. 
                    All facilities are provided free of charge for public use.
                </p>
            </div>
        </aside>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';

?>

<script>
// Make calendar dates clickable: send user to login with redirect to booking calendar
document.addEventListener('DOMContentLoaded', function() {
    const loginUrl = '<?= $base; ?>/resources/views/pages/auth/login.php?next=<?= urlencode($base . '/resources/views/pages/dashboard/calendar.php'); ?>';
    document.querySelectorAll('.calendar .day').forEach(day => {
        day.style.cursor = 'pointer';
        day.addEventListener('click', () => {
            window.location.href = loginUrl;
        });
    });
});
</script>


