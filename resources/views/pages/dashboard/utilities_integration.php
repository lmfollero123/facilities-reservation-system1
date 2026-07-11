<?php
/**
 * UMAN Integration page — Utilities Management (assets & equipment), not billing.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'utilities')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../services/uman_api.php';

$pdo = db();
$pageTitle = 'UMAN Integration | LGU Facilities Reservation';

$message = '';
$messageType = '';
$hasUmanTables = frs_uman_tables_exist($pdo);

$equipmentTypes = [
    'Sound System',
    'Projector & AV',
    'Air Conditioning',
    'Lighting Equipment',
    'Furniture Set',
    'Streetlight',
    'Water Pipeline',
    'Electrical Utility Pole',
    'Public Utility Infrastructure',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } elseif ($_POST['action'] === 'request_asset') {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $assetType = trim((string)($_POST['asset_type'] ?? ''));
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $facStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
        $facStmt->execute([$facilityId]);
        $facilityName = (string)($facStmt->fetchColumn() ?: '');

        if ($facilityId <= 0 || $facilityName === '' || $assetType === '') {
            $message = 'Please select a facility and asset type.';
            $messageType = 'error';
        } else {
            $result = submitUMANAssetRequest($facilityId, $facilityName, $assetType, $quantity, $notes);
            if (!empty($result['error'])) {
                $message = 'Unable to submit request to UMAN: ' . $result['error'];
                $messageType = 'error';
            } else {
                $ref = (string)($result['data']['request_ref'] ?? '');
                if ($hasUmanTables && $ref !== '') {
                    frs_record_uman_asset_request($pdo, $facilityId, $assetType, $quantity, $notes, $ref, 'pending');
                }
                $message = 'Asset request submitted to UMAN' . ($ref !== '' ? " (ref: {$ref})" : '') . '.';
                $messageType = 'success';
            }
        }
    } elseif ($_POST['action'] === 'sync_requests') {
        $count = frs_sync_local_uman_requests($pdo);
        $message = $count > 0
            ? "Synced {$count} request status update(s) from UMAN."
            : 'Request statuses are up to date (or UMAN API unavailable).';
        $messageType = 'success';
    }
}

frs_sync_local_uman_requests($pdo);

$assetsResult = fetchUMANAssets(true);
$umanAssets = $assetsResult['data'] ?? [];
$apiError = $assetsResult['error'] ?? null;
$connected = $apiError === null && uman_api_key() !== '';

$requestsResult = fetchUMANAssetRequests();
$remoteRequests = $requestsResult['data'] ?? [];
if (!empty($requestsResult['error']) && $apiError === null) {
    $apiError = $requestsResult['error'];
}

$localRequests = [];
if ($hasUmanTables) {
    try {
        $localRequests = $pdo->query("
            SELECT r.*, f.name AS facility_name
            FROM uman_asset_requests r
            JOIN facilities f ON f.id = r.facility_id
            ORDER BY r.created_at DESC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $localRequests = [];
    }
}

$facilities = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$assignedCounts = [];
if ($hasUmanTables) {
    try {
        foreach ($pdo->query('SELECT facility_id, COUNT(*) AS cnt FROM facility_equipment GROUP BY facility_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignedCounts[(int)$row['facility_id']] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {
        $assignedCounts = [];
    }
}

$integrationStatus = [
    'connected' => $connected,
    'preview' => !$connected,
    'last_sync' => $connected ? date('Y-m-d H:i:s') : null,
    'sync_status' => $connected ? 'live' : 'disconnected',
    'asset_count' => count($umanAssets),
    'pending_requests' => count(array_filter($remoteRequests, fn($r) => ($r['status'] ?? '') === 'pending')),
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>UMAN Integration</span>
    </div>
    <?= frs_page_title('UMAN Utilities Management Integration', 'Request utility assets from UMAN and assign approved equipment to facilities via Facility Management.'); ?>
</div>

<?php if ($message): ?>
    <div class="message <?= htmlspecialchars($messageType); ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="booking-card" style="margin-bottom: 1.5rem; border-left: 4px solid <?= $connected ? '#28a745' : '#f59e0b'; ?>;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="margin: 0 0 0.5rem 0; font-size: 1.1rem;">Integration Status</h2>
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span class="status-badge <?= $connected ? 'active' : 'offline'; ?>" style="font-size: 0.9rem;">
                    <?= $connected ? 'Connected to UMAN' : 'Not Connected'; ?>
                </span>
                <?php if ($connected): ?>
                    <span style="background: #d4edda; color: #155724; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        <?= (int)$integrationStatus['asset_count']; ?> asset(s) available
                    </span>
                <?php else: ?>
                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        Set UMAN_API_KEY in .env
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($hasUmanTables): ?>
        <form method="POST" style="margin:0;">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="sync_requests">
            <button class="btn-outline" type="submit" style="padding: 0.5rem 1rem;" <?= $connected ? '' : 'disabled title="Connect UMAN API first"'; ?>>
                Sync Request Status
            </button>
        </form>
        <?php endif; ?>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>UMAN</strong> (<?= htmlspecialchars(uman_api_base_url()); ?>) manages utility assets and equipment inventory.
            CPRF requests assets here; assign approved items per facility in
            <a href="<?= base_path(); ?>/dashboard/facility-management">Facility Management</a>.
            <?php if (!$hasUmanTables): ?>
                <br><strong>Note:</strong> Run <code>database/migration_add_uman_equipment.sql</code> to enable local equipment tracking.
            <?php endif; ?>
            <?php if ($apiError): ?>
                <br><span style="color:#b23030;">API: <?= htmlspecialchars($apiError); ?></span>
            <?php endif; ?>
        </small>
    </div>
</div>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Request Asset from UMAN</h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">Submit an equipment/utility asset request to the Utilities Management system for a specific facility.</p>
        <form method="POST" class="booking-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="request_asset">
            <label>
                Facility
                <select name="facility_id" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <option value="">— Select facility —</option>
                    <?php foreach ($facilities as $f): ?>
                        <option value="<?= (int)$f['id']; ?>"><?= htmlspecialchars($f['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="margin-top:0.75rem; display:block;">
                Asset / Equipment Type
                <select name="asset_type" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <option value="">— Select type —</option>
                    <?php foreach ($equipmentTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type); ?>"><?= htmlspecialchars($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="margin-top:0.75rem; display:block;">
                Quantity
                <input type="number" name="quantity" min="1" max="99" value="1" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
            </label>
            <label style="margin-top:0.75rem; display:block;">
                Notes (optional)
                <textarea name="notes" rows="2" placeholder="e.g., For convention hall events, portable unit preferred" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"></textarea>
            </label>
            <button type="submit" class="btn-primary" style="margin-top:1rem;" <?= $connected ? '' : 'disabled'; ?>>Submit Request to UMAN</button>
        </form>
    </section>

    <aside class="booking-card">
        <h2>Facility Equipment Summary</h2>
        <?php if (empty($facilities)): ?>
            <p style="color:#8b95b5;">No facilities registered.</p>
        <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0;">
                <?php foreach ($facilities as $f): ?>
                    <?php $cnt = $assignedCounts[(int)$f['id']] ?? 0; ?>
                    <li style="padding:0.75rem 0; border-bottom:1px solid #edf2f7; display:flex; justify-content:space-between; gap:0.5rem;">
                        <span><?= htmlspecialchars($f['name']); ?></span>
                        <span style="font-weight:600; color:<?= $cnt > 0 ? '#0066cc' : '#8b95b5'; ?>;"><?= $cnt; ?> assigned</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
</div>

<section class="booking-card" style="margin-top:1.5rem;">
    <h2>UMAN Asset Catalog <?= $connected ? '' : '<small style="font-weight:500;color:#8b95b5;">(connect API to load)</small>'; ?></h2>
    <?php if (empty($umanAssets)): ?>
        <p style="color:#8b95b5; text-align:center; padding:2rem;">
            <?= $apiError ? htmlspecialchars($apiError) : 'No assets returned from UMAN.'; ?>
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Location</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($umanAssets as $asset): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($asset['asset_code'] ?? '')); ?></strong></td>
                            <td><?= htmlspecialchars((string)($asset['name'] ?? '')); ?></td>
                            <td><?= htmlspecialchars((string)($asset['asset_type'] ?? '')); ?></td>
                            <td><span class="status-badge active"><?= htmlspecialchars((string)($asset['condition_status'] ?? '')); ?></span></td>
                            <td><?= htmlspecialchars((string)($asset['location'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="booking-card" style="margin-top:1.5rem;">
    <h2>Asset Requests</h2>
    <?php
    $displayRequests = $localRequests !== [] ? $localRequests : $remoteRequests;
    ?>
    <?php if (empty($displayRequests)): ?>
        <p style="color:#8b95b5; text-align:center; padding:2rem;">No asset requests yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Facility</th>
                        <th>Asset Type</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayRequests as $req): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($req['uman_request_ref'] ?? $req['request_ref'] ?? '—')); ?></strong></td>
                            <td><?= htmlspecialchars((string)($req['facility_name'] ?? '')); ?></td>
                            <td><?= htmlspecialchars((string)($req['asset_type'] ?? '')); ?></td>
                            <td><?= (int)($req['quantity'] ?? 1); ?></td>
                            <td><span class="status-badge maintenance"><?= htmlspecialchars(ucfirst((string)($req['status'] ?? 'pending'))); ?></span></td>
                            <td><?= htmlspecialchars(date('M d, Y', strtotime((string)($req['created_at'] ?? 'now')))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
