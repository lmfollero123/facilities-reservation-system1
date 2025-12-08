<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
$pageTitle = 'Facilities | LGU Facilities Reservation';
$base = base_path();
$pdo = db();

$stmt = $pdo->query('SELECT id, name, description, image_path, status FROM facilities ORDER BY name');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fallback images rotation if no uploaded image
$fallbackImages = [
    $base . '/public/img/convention-hall.jpg',
    $base . '/public/img/sports-complex.jpg',
    $base . '/public/img/amphitheater.jpg',
];

$facilities = [];
foreach ($rows as $idx => $row) {
    $statusLabel = ucfirst($row['status']);
    $statusClass = $row['status'] === 'available' ? 'status-available' : 'status-booked';
    $image = $row['image_path']
        ? $base . $row['image_path']
        : $fallbackImages[$idx % count($fallbackImages)];

    $facilities[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'description' => $row['description'] ?: 'LGU facility.',
        'status' => $statusLabel,
        'status_class' => $statusClass,
        'image' => $image,
    ];
}

ob_start();
?>
<section class="section">
    <div class="container">
        <h2>Facilities Directory</h2>
        <?php if (empty($facilities)): ?>
            <p style="color:#4c5b7c;margin-top:1rem;">No facilities are published yet. Please check again later or contact the LGU Facilities Office.</p>
        <?php else: ?>
            <div class="facility-grid">
                <?php foreach ($facilities as $facility): ?>
                    <article class="facility-card">
                        <img src="<?= htmlspecialchars($facility['image']); ?>" alt="<?= htmlspecialchars($facility['name']); ?>">
                        <div class="card-body">
                            <div class="status-pill <?= $facility['status_class']; ?>">
                                <?= htmlspecialchars($facility['status']); ?>
                            </div>
                            <h3><?= htmlspecialchars($facility['name']); ?></h3>
                            <p><?= htmlspecialchars($facility['description']); ?></p>
                            <small style="color:#5b6888; font-weight:500;">Free of Charge</small>
                            <a class="btn btn-primary" href="<?= $base; ?>/resources/views/pages/public/facility_details.php?id=<?= (int)$facility['id']; ?>">View Details</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


