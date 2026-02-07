<?php
$useTailwind = true;
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
<section class="section public-fade-in" id="portfolio">
    <div class="container">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-3">Facilities Directory</h2>
            <div class="h-1 w-16 bg-emerald-600 rounded-full mx-auto mb-4"></div>
            <p class="text-gray-600">Browse our available barangay facilities</p>
        </div>
        <?php if (empty($facilities)): ?>
            <p class="text-gray-600 text-center py-12">No facilities are published yet. Please check again later or contact the LGU Facilities Office.</p>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php foreach ($facilities as $idx => $facility): ?>
                    <a href="<?= $base; ?>/facility-details?id=<?= (int)$facility['id']; ?>" class="block bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden group public-card-hover">
                        <div class="relative h-56 overflow-hidden">
                            <img src="<?= htmlspecialchars($facility['image']); ?>" alt="<?= htmlspecialchars($facility['name']); ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                            <span class="absolute top-4 right-4 px-3 py-1.5 rounded-full text-sm font-semibold <?= $facility['status_class'] === 'status-available' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800'; ?>">
                                <?= htmlspecialchars($facility['status']); ?>
                            </span>
                        </div>
                        <div class="p-6">
                            <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-emerald-600 transition-colors"><?= htmlspecialchars($facility['name']); ?></h3>
                            <p class="text-gray-600 text-sm mb-4 line-clamp-2"><?= htmlspecialchars($facility['description']); ?></p>
                            <span class="text-emerald-600 font-semibold text-sm inline-flex items-center gap-1">
                                View Details
                                <svg class="w-5 h-5 public-icon-transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


