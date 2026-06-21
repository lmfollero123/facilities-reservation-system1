<?php
/**
 * Printable facility Check In/Out QR poster (Admin/Staff).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !in_array($role, ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';

$facilityId = (int)($_GET['id'] ?? 0);
if ($facilityId <= 0) {
    http_response_code(404);
    echo 'Facility not found.';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, name, location, status FROM facilities WHERE id = ? LIMIT 1');
$stmt->execute([$facilityId]);
$facility = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$facility) {
    http_response_code(404);
    echo 'Facility not found.';
    exit;
}

$token = frs_ensure_facility_checkin_token($pdo, $facilityId);
if (!$token) {
    http_response_code(503);
    echo 'Facility QR is not enabled. Run database/migration_add_facility_checkin_qr.sql';
    exit;
}

$checkinUrl = frs_facility_checkin_url($token);
$qrUrl = frs_facility_qr_image_url($checkinUrl, 420);
$orgName = 'Barangay Culiat Facilities Reservation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print QR — <?= htmlspecialchars($facility['name']); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: #eef2f7;
            color: #0f172a;
        }
        .toolbar {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            padding: 1rem;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }
        .toolbar button {
            border: 0;
            border-radius: 8px;
            padding: 0.65rem 1.1rem;
            font-size: 0.95rem;
            cursor: pointer;
        }
        .btn-print { background: #2563eb; color: #fff; }
        .btn-close { background: #e2e8f0; color: #334155; }
        .poster-wrap { display: flex; justify-content: center; padding: 2rem 1rem; }
        .poster {
            width: min(100%, 520px);
            background: #fff;
            border-radius: 18px;
            border: 2px solid #1e3a8a;
            padding: 2rem 1.75rem;
            text-align: center;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12);
        }
        .poster-badge {
            display: inline-block;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            background: #dbeafe;
            color: #1e40af;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .poster h1 {
            margin: 0.85rem 0 0.35rem;
            font-size: 1.65rem;
            line-height: 1.2;
        }
        .poster .location {
            margin: 0 0 1.25rem;
            color: #64748b;
            font-size: 0.95rem;
        }
        .poster img.qr {
            width: 280px;
            height: 280px;
            border: 8px solid #f8fafc;
            border-radius: 12px;
            box-shadow: inset 0 0 0 1px #e2e8f0;
        }
        .steps {
            margin: 1.25rem 0 0;
            text-align: left;
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem 1.1rem;
        }
        .steps ol {
            margin: 0;
            padding-left: 1.2rem;
            color: #334155;
            line-height: 1.55;
            font-size: 0.92rem;
        }
        .footer-note {
            margin-top: 1rem;
            font-size: 0.78rem;
            color: #94a3b8;
        }
        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
            .poster-wrap { padding: 0; }
            .poster {
                box-shadow: none;
                border-radius: 0;
                width: 100%;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="btn-print" onclick="window.print()">Print poster</button>
        <button type="button" class="btn-close" onclick="window.close()">Close</button>
    </div>
    <div class="poster-wrap">
        <article class="poster">
            <span class="poster-badge">Scan to Check In / Out</span>
            <h1><?= htmlspecialchars($facility['name']); ?></h1>
            <?php if (!empty($facility['location'])): ?>
                <p class="location"><?= htmlspecialchars($facility['location']); ?></p>
            <?php endif; ?>
            <img class="qr" src="<?= htmlspecialchars($qrUrl); ?>" alt="Facility Check In QR code" width="280" height="280">
            <div class="steps">
                <ol>
                    <li>Log in to your reservation account on your phone.</li>
                    <li>Scan this QR code at the facility entrance.</li>
                    <li>Check In when your slot starts; Check Out when it ends.</li>
                </ol>
            </div>
            <p class="footer-note"><?= htmlspecialchars($orgName); ?></p>
        </article>
    </div>
</body>
</html>
