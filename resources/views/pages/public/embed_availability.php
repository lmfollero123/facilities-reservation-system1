<?php
/**
 * Embeddable public availability calendar widget (iframe-friendly).
 * Usage: <iframe src="https://yoursite/embed/availability?facility_id=1" ...></iframe>
 */
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/blackout_dates.php';

$facilityId = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$month = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$pdo = db();
$facility = null;
if ($facilityId > 0) {
    $stmt = $pdo->prepare('SELECT id, name, status FROM facilities WHERE id = ? LIMIT 1');
    $stmt->execute([$facilityId]);
    $facility = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$blackouts = [];
if ($facility) {
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    if (function_exists('frs_list_blackout_dates_between')) {
        $blackouts = frs_list_blackout_dates_between($pdo, $facilityId, $start, $end);
    }
}

header('X-Frame-Options: SAMEORIGIN');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $facility ? htmlspecialchars($facility['name']) . ' — Availability' : 'Facility Availability'; ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 1rem; background: #f8fafc; color: #0f172a; }
        h1 { font-size: 1.1rem; margin: 0 0 0.75rem; }
        .meta { font-size: 0.85rem; color: #64748b; margin-bottom: 1rem; }
        .grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .dow { font-size: 0.7rem; text-align: center; color: #94a3b8; font-weight: 600; }
        .day { min-height: 2rem; font-size: 0.75rem; text-align: center; padding: 0.35rem 0; border-radius: 6px; background: #fff; border: 1px solid #e2e8f0; }
        .day.blank { background: transparent; border: none; }
        .day.blocked { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .day.maint { background: #fef3c7; color: #92400e; }
        .legend { display: flex; gap: 1rem; margin-top: 1rem; font-size: 0.75rem; }
        .swatch { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
    </style>
</head>
<body>
<?php if (!$facility): ?>
    <p>Facility not found. Pass <code>?facility_id=</code> in the embed URL.</p>
<?php else: ?>
    <h1><?= htmlspecialchars($facility['name']); ?></h1>
    <p class="meta">Month: <?= htmlspecialchars($month); ?> · Status: <?= htmlspecialchars($facility['status']); ?></p>
    <?php
    $blocked = [];
    foreach ($blackouts as $b) {
        $blocked[(string)($b['blackout_date'] ?? '')] = true;
    }
    $first = new DateTime($month . '-01');
    $daysInMonth = (int)$first->format('t');
    $startDow = (int)$first->format('N');
    $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    ?>
    <div class="grid">
        <?php foreach ($labels as $lb): ?><div class="dow"><?= $lb; ?></div><?php endforeach; ?>
        <?php for ($i = 1; $i < $startDow; $i++): ?><div class="day blank"></div><?php endfor; ?>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $iso = $month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $cls = 'day';
            if (isset($blocked[$iso])) {
                $cls .= ' blocked';
            } elseif (strtolower((string)$facility['status']) === 'maintenance') {
                $cls .= ' maint';
            }
        ?>
            <div class="<?= $cls; ?>"><?= $d; ?></div>
        <?php endfor; ?>
    </div>
    <div class="legend">
        <span><span class="swatch" style="background:#fff;border:1px solid #e2e8f0"></span> Open day</span>
        <span><span class="swatch" style="background:#fee2e2"></span> Blackout / blocked</span>
    </div>
<?php endif; ?>
</body>
</html>
