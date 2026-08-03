<?php
/**
 * UMAN Integration page — Utilities Management (assets & equipment), not billing.
 *
 * v2 Quick wins:
 *  1. Asset Type dropdown is populated from LIVE UMAN asset_types endpoint
 *     (falls back to static 9-item array if API is offline).
 *  2. Catalog table rows are clickable "Request this asset" — prefills the form
 *     with asset_type, asset_type_id, requested_asset_code, responsible_office.
 *  3. 5 new specificity fields added: urgency, date_needed, booking_ref,
 *     event_purpose, responsible_office (plus hidden exact_match toggle).
 *  4. All new specific fields are sent to UMAN's POST intake (schema expanded
 *     on both sides in asset-requests.php) and stored locally.
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
$dashboardContentClass = 'integrations-modern';

$message = '';
$messageType = '';
$hasUmanTables = frs_uman_tables_exist($pdo);

if ($hasUmanTables) {
    frs_ensure_uman_requests_schema_v2($pdo);
}

$STATIC_EQUIPMENT_TYPES = [
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
        $facilityId   = (int)($_POST['facility_id'] ?? 0);
        $assetType    = trim((string)($_POST['asset_type'] ?? ''));
        $quantity     = max(1, (int)($_POST['quantity'] ?? 1));
        $notes        = trim((string)($_POST['notes'] ?? ''));

        $assetTypeId      = !empty($_POST['asset_type_id']) ? (int)$_POST['asset_type_id'] : null;
        $reqCode          = trim((string)($_POST['requested_asset_code'] ?? '')) ?: null;
        $exactMatch       = !empty($_POST['exact_match']);
        $urgency          = trim((string)($_POST['urgency'] ?? ''));
        $dateNeeded       = trim((string)($_POST['date_needed'] ?? '')) ?: null;
        $bookingRef       = trim((string)($_POST['booking_ref'] ?? '')) ?: null;
        $eventPurpose     = trim((string)($_POST['event_purpose'] ?? '')) ?: null;
        $responsibleOffice = trim((string)($_POST['responsible_office'] ?? '')) ?: null;

        if (!in_array($urgency, ['Routine', 'Priority', 'Emergency'], true)) {
            $urgency = 'Routine';
        }

        $facStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
        $facStmt->execute([$facilityId]);
        $facilityName = (string)($facStmt->fetchColumn() ?: '');

        if ($facilityId <= 0 || $facilityName === '' || $assetType === '') {
            $message = 'Please select a facility and asset type.';
            $messageType = 'error';
        } else {
            $extras = [
                'asset_type_id'        => $assetTypeId,
                'requested_asset_code' => $reqCode,
                'exact_match'          => $exactMatch,
                'urgency'              => $urgency,
                'date_needed'          => $dateNeeded,
                'booking_ref'          => $bookingRef,
                'event_purpose'        => $eventPurpose,
                'responsible_office'   => $responsibleOffice,
            ];

            $result = submitUMANAssetRequest($facilityId, $facilityName, $assetType, $quantity, $notes, $extras);
            $ref = (string)($result['data']['request_ref'] ?? '');

            if (!empty($result['error'])) {
                $queuedRef = '';
                if ($hasUmanTables) {
                    $queuedRef = 'CPRF-Q-' . date('YmdHis') . '-' . $facilityId;
                    frs_record_uman_asset_request(
                        $pdo, $facilityId, $assetType, $quantity, $notes,
                        $queuedRef, 'queued', $extras
                    );
                }
                $message = 'UMAN temporarily unavailable — request queued locally'
                         . ($queuedRef !== '' ? " (ref {$queuedRef})" : '')
                         . '. Error: ' . $result['error'];
                $messageType = 'warning';
            } else {
                if ($hasUmanTables && $ref !== '') {
                    frs_record_uman_asset_request(
                        $pdo, $facilityId, $assetType, $quantity, $notes,
                        $ref, 'pending', $extras
                    );
                }
                $extraFlags = [];
                if ($urgency !== 'Routine')        $extraFlags[] = $urgency;
                if ($dateNeeded)                    $extraFlags[] = "need by {$dateNeeded}";
                if ($reqCode)                       $extraFlags[] = ($exactMatch ? 'exact ' : 'prefers ') . $reqCode;
                if ($responsibleOffice)             $extraFlags[] = "route: {$responsibleOffice}";
                $flagStr = $extraFlags ? ' [' . implode(' · ', $extraFlags) . ']' : '';
                $message = 'Asset request submitted to UMAN'
                         . ($ref !== '' ? " (ref: {$ref})" : '')
                         . $flagStr . '.';
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

$apiKeyConfigured = uman_api_key() !== '';

$assetsResult = fetchUMANAssets(true);
$umanAssets = $assetsResult['data'] ?? [];
$apiError = $assetsResult['error'] ?? null;
$assetsConnected = $apiError === null && $apiKeyConfigured;

$requestsResult = fetchUMANAssetRequests();
$remoteRequests = $requestsResult['data'] ?? [];
if (!empty($requestsResult['error']) && $apiError === null) {
    $apiError = $requestsResult['error'];
}
$requestsConnected = empty($requestsResult['error']) && $apiKeyConfigured;

$connected = $apiKeyConfigured;
$catalogLive = $assetsConnected && $requestsConnected;

$typesResult = fetchUMANAssetTypes();
$liveTypes = $typesResult['data'] ?? [];
$typesApiError = $typesResult['error'] ?? null;
$typesConnected = empty($typesApiError) && $apiKeyConfigured && !empty($liveTypes);

$equipmentTypes = [];
if ($typesConnected) {
    foreach ($liveTypes as $t) {
        $equipmentTypes[] = [
            'id'          => (int)($t['id'] ?? 0),
            'name'        => (string)($t['name'] ?? ''),
            'description' => (string)($t['description'] ?? ''),
            'asset_count' => (int)($t['asset_count'] ?? 0),
            'operational_count' => (int)($t['operational_count'] ?? 0),
        ];
    }
    usort($equipmentTypes, fn($a, $b) => strcasecmp($a['name'], $b['name']));
} else {
    foreach ($STATIC_EQUIPMENT_TYPES as $name) {
        $equipmentTypes[] = [
            'id' => 0,
            'name' => $name,
            'description' => '',
            'asset_count' => 0,
            'operational_count' => 0,
        ];
    }
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
    'catalog_live' => $catalogLive,
    'preview' => !$connected,
    'last_sync' => $connected ? date('Y-m-d H:i:s') : null,
    'sync_status' => $catalogLive ? 'live' : ($apiKeyConfigured ? 'request_only' : 'disconnected'),
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

<?php if ($message):
    $msgBg = $messageType === 'success' ? '#ecfdf5' : ($messageType === 'warning' ? '#fffbeb' : '#fef2f2');
    $msgFg = $messageType === 'success' ? '#047857' : ($messageType === 'warning' ? '#92400e' : '#b91c1c');
    $msgBd = $messageType === 'success' ? '#a7f3d0' : ($messageType === 'warning' ? '#fde68a' : '#fecaca');
?>
    <div class="message <?= htmlspecialchars($messageType); ?>" style="padding:0.85rem 1rem;border-radius:10px;margin-bottom:1.25rem;background:<?= $msgBg; ?>;color:<?= $msgFg; ?>;border:1px solid <?= $msgBd; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!$apiKeyConfigured): ?>
    <div style="padding:0.85rem 1rem;border-radius:10px;margin-bottom:1.25rem;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;">
        <strong style="display:block;margin-bottom:0.25rem;">UMAN API key not configured</strong>
        Set <code>UMAN_API_KEY</code> in your <code>.env</code> file to submit asset requests.
    </div>
<?php elseif (!$catalogLive): ?>
    <div style="padding:0.85rem 1rem;border-radius:10px;margin-bottom:1.25rem;background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;">
        <strong style="display:block;margin-bottom:0.25rem;">Request-only mode</strong>
        Asset catalog and request sync couldn't load from UMAN
        <?php if (!empty($apiError)): ?>: <em><?= htmlspecialchars($apiError); ?></em><?php endif; ?>.
        You can still submit requests — they will be queued and synced automatically when UMAN is reachable.
    </div>
<?php endif; ?>

<div class="booking-wrapper" id="request-form-wrapper">
    <section class="booking-card">
        <h2>Request Asset from UMAN</h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">Submit an equipment/utility asset request to the Utilities Management system. <em style="color:#059669;">Tip: click any asset row in the catalog below to prefill this form with a specific unit.</em></p>
        <form method="POST" class="booking-form" id="uman-asset-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="request_asset">
            <input type="hidden" name="asset_type_id" id="f_asset_type_id" value="">
            <input type="hidden" name="requested_asset_code" id="f_requested_asset_code" value="">

            <div class="integration-form-row integration-form-row--2">
                <label>
                    Facility *
                    <select name="facility_id" id="f_facility_id" required class="integration-field">
                        <option value="">— Select facility —</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= (int)$f['id']; ?>"><?= htmlspecialchars($f['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Asset / Equipment Type *
                    <select name="asset_type" id="f_asset_type" required class="integration-field">
                        <option value="">— Select type —</option>
                        <?php foreach ($equipmentTypes as $t):
                            $countStr = $typesConnected && $t['asset_count'] > 0
                                ? " ({$t['operational_count']}/{$t['asset_count']} oper.)"
                                : '';
                            $title = $t['description'] !== '' ? ' title="' . htmlspecialchars($t['description']) . '"' : '';
                            $dataId = $t['id'] > 0 ? " data-id=\"{$t['id']}\"" : '';
                        ?>
                            <option value="<?= htmlspecialchars($t['name']); ?>"<?= $dataId . $title; ?>><?= htmlspecialchars($t['name'] . $countStr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="integration-form-row integration-form-row--3">
                <label>
                    Quantity
                    <input type="number" name="quantity" id="f_quantity" min="1" max="99" value="1" class="integration-field">
                </label>
                <label>
                    Urgency
                    <select name="urgency" id="f_urgency" class="integration-field">
                        <option value="Routine">Routine (3–5 days)</option>
                        <option value="Priority">Priority (1–2 days)</option>
                        <option value="Emergency">Emergency (same day)</option>
                    </select>
                </label>
                <label>
                    Date Needed
                    <input type="date" name="date_needed" id="f_date_needed" class="integration-field" min="<?= date('Y-m-d'); ?>">
                </label>
            </div>

            <div id="pinned-asset-banner" style="margin-top:0.75rem;padding:0.6rem 0.85rem;border-radius:8px;background:#ecfeff;border:1px solid #a5f3fc;color:#0e7490;font-size:0.9rem;display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                    <div>
                        <strong style="color:#155e75;">Pinned specific asset:</strong>
                        <span id="pinned-asset-name">—</span>
                        <span id="pinned-asset-code" style="margin-left:0.4rem;padding:0.1rem 0.4rem;border-radius:4px;background:#cffafe;color:#0e7490;font-family:monospace;font-size:0.8rem;"></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <label style="display:inline-flex;align-items:center;gap:0.25rem;font-size:0.85rem;color:#155e75;white-space:nowrap;">
                            <input type="checkbox" name="exact_match" id="f_exact_match" style="width:auto;margin:0;">
                            Exact unit only
                        </label>
                        <button type="button" id="btn-clear-pin" style="padding:0.2rem 0.5rem;border-radius:4px;border:1px solid #a5f3fc;background:#fff;color:#0e7490;font-size:0.8rem;cursor:pointer;">Clear</button>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.75rem;">
                <label style="display:block;">
                    Booking Reference (optional)
                    <input type="text" name="booking_ref" id="f_booking_ref" placeholder="e.g., RES-2026-0812-007" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="display:block;">
                    Responsible Office (optional)
                    <input type="text" name="responsible_office" id="f_responsible_office" placeholder="e.g., Barangay Engineering Office" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
            </div>

            <label style="margin-top:0.75rem; display:block;">
                Event / Purpose (optional)
                <input type="text" name="event_purpose" id="f_event_purpose" placeholder="e.g., Graduation ceremony, Barangay assembly" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
            </label>

            <label style="margin-top:0.75rem; display:block;">
                Notes (optional)
                <textarea name="notes" id="f_notes" rows="2" placeholder="e.g., For convention hall events, portable unit preferred" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"></textarea>
            </label>

            <div style="margin-top:0.75rem;padding:0.55rem 0.8rem;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:0.82rem;">
                <?= $typesConnected
                    ? '<strong>Live catalog:</strong> asset types and operational counts pulled directly from UMAN.'
                    : '<strong>Fallback list:</strong> using a static 9-type list — UMAN asset-types endpoint was unreachable' . (!empty($typesApiError) ? ' (' . htmlspecialchars($typesApiError) . ')' : '') . '.';
                ?>
            </div>

            <button type="submit" class="btn-primary" style="margin-top:1rem;" <?= $apiKeyConfigured ? '' : 'disabled title="Configure UMAN_API_KEY in .env first"'; ?>>
                <?= $apiKeyConfigured ? 'Submit Request to UMAN' : 'UMAN API key not configured'; ?>
            </button>
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

        <form method="POST" style="margin-top:1.25rem;padding-top:1rem;border-top:1px dashed #e0e6ed;">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="sync_requests">
            <button type="submit" class="btn-outline" style="width:100%;padding:0.5rem;border-radius:6px;border:1px solid #cbd5e1;background:#fff;color:#475569;cursor:pointer;">
                ⟳ Sync Request Status from UMAN
            </button>
        </form>
    </aside>
</div>

<section class="booking-card" style="margin-top:1.5rem;">
    <h2>UMAN Asset Catalog <?= $catalogLive ? '' : '<small style="font-weight:500;color:#8b95b5;">(catalog offline — requests still work)</small>'; ?></h2>
    <?php if (empty($umanAssets)): ?>
        <p style="color:#8b95b5; text-align:center; padding:2rem;">
            <?= $apiError ? htmlspecialchars($apiError) : 'No assets returned from UMAN.'; ?>
        </p>
    <?php else: ?>
        <div style="margin-bottom:0.6rem;font-size:0.85rem;color:#64748b;">
            💡 <strong>Tip:</strong> click any row to prefill the request form with that specific asset.
        </div>
        <div class="table-responsive">
            <table class="table" id="asset-catalog-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Responsible Office</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($umanAssets as $asset):
                        $code = (string)($asset['asset_code'] ?? '');
                        $name = (string)($asset['name'] ?? '');
                        $type = (string)($asset['asset_type'] ?? '');
                        $cond = (string)($asset['condition_status'] ?? '');
                        $loc  = (string)($asset['location'] ?? '');
                        $resp = (string)($asset['responsible_office'] ?? '');
                        $typeId = (int)($asset['asset_type_id'] ?? 0);
                        $rowClickable = $cond === 'Operational';
                    ?>
                        <tr class="uman-catalog-row"
                            data-code="<?= htmlspecialchars($code); ?>"
                            data-name="<?= htmlspecialchars($name); ?>"
                            data-type="<?= htmlspecialchars($type); ?>"
                            data-type-id="<?= $typeId; ?>"
                            data-resp="<?= htmlspecialchars($resp); ?>"
                            style="<?= $rowClickable ? 'cursor:pointer;' : 'opacity:0.75;' ?>"
                            title="<?= $rowClickable ? 'Click to request this specific asset' : 'Asset not operational — click to view only' ?>">
                            <td data-label="Code"><strong style="font-family:monospace;"><?= htmlspecialchars($code); ?></strong></td>
                            <td data-label="Name"><?= htmlspecialchars($name); ?></td>
                            <td data-label="Type"><?= htmlspecialchars($type); ?></td>
                            <td data-label="Status"><span class="status-badge active"><?= htmlspecialchars($cond); ?></span></td>
                            <td data-label="Location"><?= htmlspecialchars($loc); ?></td>
                            <td data-label="Responsible Office"><?= htmlspecialchars($resp); ?></td>
                            <td>
                                <?php if ($rowClickable): ?>
                                    <button type="button" class="uman-pin-btn"
                                        style="padding:0.25rem 0.6rem;border-radius:6px;border:1px solid #059669;background:#d1fae5;color:#059669;font-size:0.78rem;cursor:pointer;white-space:nowrap;"
                                        aria-label="Request this asset">Use this</button>
                                <?php endif; ?>
                            </td>
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
                        <th>Asset</th>
                        <th>Qty</th>
                        <th>Urgency</th>
                        <th>Need By</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayRequests as $req):
                        $ref  = (string)($req['uman_request_ref'] ?? $req['request_ref'] ?? '—');
                        $fac  = (string)($req['facility_name'] ?? '');
                        $type = (string)($req['asset_type'] ?? '');
                        $code = (string)($req['requested_asset_code'] ?? '');
                        $qty  = (int)($req['quantity'] ?? 1);
                        $urg  = (string)($req['urgency'] ?? 'Routine');
                        $need = !empty($req['date_needed']) ? date('M d, Y', strtotime((string)$req['date_needed'])) : '—';
                        $stat = (string)($req['status'] ?? 'pending');
                        $when = date('M d, Y', strtotime((string)($req['created_at'] ?? 'now')));

                        $assetLabel = $type;
                        if ($code !== '') {
                            $assetLabel .= ' <span style="font-size:0.75rem;padding:0.08rem 0.3rem;border-radius:3px;background:#f1f5f9;color:#475569;font-family:monospace;">' . htmlspecialchars($code) . '</span>';
                        }

                        $urgColor = match($urg) {
                            'Emergency' => '#dc2626',
                            'Priority'  => '#d97706',
                            default     => '#64748b',
                        };
                    ?>
                        <tr>
                            <td data-label="Reference"><strong><?= htmlspecialchars($ref); ?></strong></td>
                            <td data-label="Facility"><?= htmlspecialchars($fac); ?></td>
                            <td data-label="Asset"><?= $assetLabel; ?></td>
                            <td data-label="Qty"><?= $qty; ?></td>
                            <td data-label="Urgency"><span style="color:<?= $urgColor; ?>;font-weight:600;"><?= htmlspecialchars($urg); ?></span></td>
                            <td data-label="Need By"><?= htmlspecialchars($need); ?></td>
                            <td data-label="Status"><span class="status-badge maintenance"><?= htmlspecialchars(ucfirst($stat)); ?></span></td>
                            <td data-label="Date"><?= htmlspecialchars($when); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    "use strict";

    const $ = (id) => document.getElementById(id);
    const banner     = $('pinned-asset-banner');
    const pinName    = $('pinned-asset-name');
    const pinCode    = $('pinned-asset-code');
    const fTypeId    = $('f_asset_type_id');
    const fCode      = $('f_requested_asset_code');
    const fType      = $('f_asset_type');
    const fExact     = $('f_exact_match');
    const fResp      = $('f_responsible_office');
    const fQty       = $('f_quantity');
    const btnClear   = $('btn-clear-pin');
    const typeOpts   = fType ? Array.from(fType.querySelectorAll('option')) : [];

    function pickTypeOption(typeName, typeId) {
        if (!fType) return;
        let matched = null;
        if (typeId > 0) {
            matched = typeOpts.find(o => Number(o.dataset.id) === Number(typeId));
        }
        if (!matched) {
            const norm = (typeName || '').toString().trim().toLowerCase();
            matched = typeOpts.find(o => o.value.trim().toLowerCase() === norm);
        }
        if (matched) matched.selected = true;
    }

    function pinAsset(payload) {
        fTypeId.value = payload.typeId || '';
        fCode.value   = payload.code   || '';
        pickTypeOption(payload.type, payload.typeId);
        if (payload.resp && fResp && fResp.value.trim() === '') {
            fResp.value = payload.resp;
        }
        if (pinName) pinName.textContent = payload.name || '—';
        if (pinCode) pinCode.textContent = payload.code ? '#' + payload.code : '';
        if (banner) {
            banner.style.display = 'block';
        }
        if (fQty) fQty.value = 1;
        if (fExact) fExact.checked = false;
        document.getElementById('request-form-wrapper')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function clearPin() {
        fTypeId.value = '';
        fCode.value   = '';
        if (fExact) fExact.checked = false;
        if (banner) banner.style.display = 'none';
    }

    if (btnClear) btnClear.addEventListener('click', clearPin);

    document.querySelectorAll('.uman-catalog-row').forEach(function (row) {
        const handler = function (ev) {
            if (ev.target && ev.target.classList && ev.target.classList.contains('uman-pin-btn')) {
                ev.preventDefault();
            }
            const ds = row.dataset || {};
            pinAsset({
                code:   ds.code   || '',
                name:   ds.name   || '',
                type:   ds.type   || '',
                typeId: Number(ds.typeId) || 0,
                resp:   ds.resp   || '',
            });
        };
        row.addEventListener('click', handler);
        const btn = row.querySelector('.uman-pin-btn');
        if (btn) btn.addEventListener('click', handler);
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
