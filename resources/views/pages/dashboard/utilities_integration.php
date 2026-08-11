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
require_once __DIR__ . '/../../../../config/energy_helper.php';

$pdo = db();
$pageTitle = 'UMAN Integration | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';

$message = '';
$messageType = '';
$hasUmanTables = frs_uman_tables_exist($pdo);
$hasReadingTables = frs_energy_tables_exist($pdo);
$canCreateReadings = frs_can_create($role, 'utilities');
$canUpdateReadings = frs_can_update($role, 'utilities');
$canDeleteReadings = frs_can_delete($role, 'utilities');

$tab = (string)($_GET['tab'] ?? 'equipment');
if (!in_array($tab, ['equipment', 'readings'], true)) {
    $tab = 'equipment';
}
$umanTabUrl = static fn (string $t): string => base_path() . '/dashboard/utilities-integration?tab=' . $t;

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
    } elseif ($_POST['action'] === 'add_utility_reading' && $canCreateReadings && $hasReadingTables) {
        $month = (string)($_POST['reading_month'] ?? '');
        $parts = explode('-', $month);
        try {
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int)$parts[1] < 1 || (int)$parts[1] > 12) {
                throw new InvalidArgumentException('Please choose a valid reading month.');
            }
            $readingId = frs_energy_save_reading($pdo, [
                'facility_id' => (int)($_POST['facility_id'] ?? 0),
                'year' => (int)$parts[0],
                'month' => (int)$parts[1],
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'previous_reading_kwh' => (float)($_POST['previous_reading_kwh'] ?? 0),
                'current_reading_kwh' => (float)($_POST['current_reading_kwh'] ?? 0),
                'rate_per_kwh' => $_POST['rate_per_kwh'] ?? null,
                'previous_reading_water' => $_POST['previous_reading_water'] ?? null,
                'current_reading_water' => $_POST['current_reading_water'] ?? null,
                'rate_per_water' => $_POST['rate_per_water'] ?? null,
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'recorded_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $push = frs_uman_push_utility_reading($pdo, $readingId);
            $message = $push['success']
                ? 'Reading saved and sent to UMAN.'
                : 'Reading saved locally. Send to UMAN pending: ' . (string)$push['error'];
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to save reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'update_utility_reading' && $canUpdateReadings && $hasReadingTables) {
        $readingId = (int)($_POST['reading_id'] ?? 0);
        try {
            frs_energy_update_reading($pdo, $readingId, [
                'current_reading_kwh' => $_POST['current_reading_kwh'] ?? null,
                'previous_reading_kwh' => $_POST['previous_reading_kwh'] ?? null,
                'rate_per_kwh' => $_POST['rate_per_kwh'] ?? null,
                'current_reading_water' => $_POST['current_reading_water'] ?? null,
                'previous_reading_water' => $_POST['previous_reading_water'] ?? null,
                'rate_per_water' => $_POST['rate_per_water'] ?? null,
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ]);
            $push = frs_uman_push_utility_reading($pdo, $readingId);
            $message = $push['success']
                ? 'Reading corrected and re-sent to UMAN.'
                : 'Reading corrected. Send to UMAN pending: ' . (string)$push['error'];
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to correct reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'delete_utility_reading' && $canDeleteReadings && $hasReadingTables) {
        $readingId = (int)($_POST['reading_id'] ?? 0);
        try {
            frs_energy_delete_reading($pdo, $readingId);
            $message = 'Reading deleted.';
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to delete reading: ' . $e->getMessage();
            $messageType = 'error';
        }
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

$facilities = $pdo->query("SELECT id, name, status FROM facilities WHERE status != 'deleted' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

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

$utilityFacilities = $pdo->query("SELECT id, name, status FROM facilities WHERE status != 'deleted' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$utilityLatestReadings = [];
if ($hasReadingTables) {
    $utilityRows = $pdo->query('
        SELECT r.*, f.name AS facility_name, u.name AS recorded_by_name
        FROM energy_meter_readings r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN users u ON u.id = r.recorded_by
        ORDER BY r.year DESC, r.month DESC, r.id DESC
        LIMIT 200
    ')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($utilityRows as $row) {
        $fid = (int)$row['facility_id'];
        if (!isset($utilityLatestReadings[$fid])) {
            $utilityLatestReadings[$fid] = $row;
        }
    }
}
$utilityEditReadingId = (int)($_GET['edit_reading'] ?? 0);
$utilityEditReading = null;
$utilityEditReadingIsOnly = false;
if ($hasReadingTables && $utilityEditReadingId > 0 && $canUpdateReadings) {
    foreach ($utilityLatestReadings as $r) {
        if ((int)$r['id'] === $utilityEditReadingId) {
            $utilityEditReading = $r;
            break;
        }
    }
    if ($utilityEditReading !== null) {
        $onlyStmt = $pdo->prepare('SELECT COUNT(*) FROM energy_meter_readings WHERE facility_id = :facility_id AND id != :id');
        $onlyStmt->execute(['facility_id' => (int)$utilityEditReading['facility_id'], 'id' => $utilityEditReadingId]);
        $utilityEditReadingIsOnly = (int)$onlyStmt->fetchColumn() === 0;
    }
}
$utilityMonthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>UMAN Integration</span>
    </div>
    <?= frs_page_title('UMAN Utilities Management Integration', 'Request utility assets from UMAN and assign approved equipment to facilities via Facility Management.'); ?>
</div>

<nav class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 mb-6 gap-1" aria-label="UMAN sections">
    <a class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $tab === 'equipment' ? 'bg-white shadow-sm text-slate-900 font-semibold' : 'text-slate-500 hover:text-slate-700'; ?>" href="<?= htmlspecialchars($umanTabUrl('equipment')); ?>">Equipment &amp; Requests</a>
    <a class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $tab === 'readings' ? 'bg-white shadow-sm text-slate-900 font-semibold' : 'text-slate-500 hover:text-slate-700'; ?>" href="<?= htmlspecialchars($umanTabUrl('readings')); ?>">Utility Readings</a>
</nav>

<?php if ($message):
    $msgClasses = $messageType === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : ($messageType === 'warning'
            ? 'border-amber-200 bg-amber-50 text-amber-800'
            : 'border-red-200 bg-red-50 text-red-800');
    $msgIcon = $messageType === 'success' ? 'bi-check-circle' : ($messageType === 'warning' ? 'bi-exclamation-triangle' : 'bi-exclamation-circle');
?>
    <div class="mb-5 rounded-xl border px-4 py-3 text-sm flex items-start gap-3 <?= $msgClasses; ?>" role="alert">
        <i class="bi <?= $msgIcon; ?> text-lg flex-shrink-0 mt-0.5"></i>
        <span><?= htmlspecialchars($message); ?></span>
    </div>
<?php endif; ?>

<?php if (!$apiKeyConfigured): ?>
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 flex items-start gap-3" role="alert">
        <i class="bi bi-exclamation-triangle text-lg flex-shrink-0 mt-0.5"></i>
        <div>
            <strong class="block mb-0.5">UMAN API key not configured</strong>
            Set <code class="rounded bg-amber-100/80 px-1.5 py-0.5 text-xs">UMAN_API_KEY</code> in your <code class="rounded bg-amber-100/80 px-1.5 py-0.5 text-xs">.env</code> file to submit asset requests.
        </div>
    </div>
<?php elseif (!$catalogLive): ?>
    <div class="mb-5 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 flex items-start gap-3" role="alert">
        <i class="bi bi-info-circle text-lg flex-shrink-0 mt-0.5"></i>
        <div>
            <strong class="block mb-0.5">Request-only mode</strong>
            Asset catalog and request sync couldn't load from UMAN
            <?php if (!empty($apiError)): ?>: <em><?= htmlspecialchars($apiError); ?></em><?php endif; ?>.
            You can still submit requests — they will be queued and synced automatically when UMAN is reachable.
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'equipment'): ?>
<?php
$umanStatLabel = match ($integrationStatus['sync_status']) {
    'live' => 'Live',
    'request_only' => 'Request-only',
    default => 'Offline',
};
$umanStatColor = match ($integrationStatus['sync_status']) {
    'live' => 'bg-emerald-50 text-emerald-600',
    'request_only' => 'bg-sky-50 text-sky-600',
    default => 'bg-red-50 text-red-600',
};
?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full <?= $umanStatColor; ?> flex items-center justify-center flex-shrink-0">
            <i class="bi bi-plug text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">UMAN Connection</p>
            <p class="text-lg font-bold text-slate-900"><?= htmlspecialchars($umanStatLabel); ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-box-seam text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Assets in Catalog</p>
            <p class="text-lg font-bold text-slate-900"><?= (int)$integrationStatus['asset_count']; ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-hourglass-split text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Pending Requests</p>
            <p class="text-lg font-bold text-slate-900"><?= (int)$integrationStatus['pending_requests']; ?></p>
        </div>
    </div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6" id="request-form-wrapper">
    <section class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6">
        <h2 class="text-base font-semibold text-slate-900 mb-1">Request Asset from UMAN</h2>
        <p class="text-sm text-slate-500 mb-4">Submit an equipment/utility asset request to the Utilities Management system. <em class="text-emerald-600">Tip: click any asset row in the catalog below to prefill this form with a specific unit.</em></p>
        <form method="POST" class="booking-form" id="uman-asset-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="request_asset">
            <input type="hidden" name="asset_type_id" id="f_asset_type_id" value="">
            <input type="hidden" name="requested_asset_code" id="f_requested_asset_code" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block text-sm font-medium text-slate-700">
                    Facility *
                    <select name="facility_id" id="f_facility_id" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <option value="">— Select facility —</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= (int)$f['id']; ?>"><?= htmlspecialchars($f['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block text-sm font-medium text-slate-700">
                    Asset / Equipment Type *
                    <select name="asset_type" id="f_asset_type" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
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

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                <label class="block text-sm font-medium text-slate-700">
                    Quantity
                    <input type="number" name="quantity" id="f_quantity" min="1" max="99" value="1" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <label class="block text-sm font-medium text-slate-700">
                    Urgency
                    <select name="urgency" id="f_urgency" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <option value="Routine">Routine (3–5 days)</option>
                        <option value="Priority">Priority (1–2 days)</option>
                        <option value="Emergency">Emergency (same day)</option>
                    </select>
                </label>
                <label class="block text-sm font-medium text-slate-700">
                    Date Needed
                    <input type="date" name="date_needed" id="f_date_needed" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none" min="<?= date('Y-m-d'); ?>">
                </label>
            </div>

            <div id="pinned-asset-banner" class="mt-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" style="display:none;">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <strong class="text-sky-800">Pinned specific asset:</strong>
                        <span id="pinned-asset-name">—</span>
                        <span id="pinned-asset-code" class="ml-1.5 rounded bg-sky-100 px-1.5 py-0.5 font-mono text-xs text-sky-800"></span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-1.5 text-xs text-sky-800 whitespace-nowrap">
                            <input type="checkbox" name="exact_match" id="f_exact_match" class="rounded border-sky-300">
                            Exact unit only
                        </label>
                        <button type="button" id="btn-clear-pin" class="rounded-md border border-sky-300 bg-white px-2.5 py-1 text-xs text-sky-800 hover:bg-sky-100">Clear</button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                <label class="block text-sm font-medium text-slate-700">
                    Booking Reference (optional)
                    <input type="text" name="booking_ref" id="f_booking_ref" placeholder="e.g., RES-2026-0812-007" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <label class="block text-sm font-medium text-slate-700">
                    Responsible Office (optional)
                    <input type="text" name="responsible_office" id="f_responsible_office" placeholder="e.g., Barangay Engineering Office" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
            </div>

            <label class="block text-sm font-medium text-slate-700 mt-4">
                Event / Purpose (optional)
                <input type="text" name="event_purpose" id="f_event_purpose" placeholder="e.g., Graduation ceremony, Barangay assembly" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
            </label>

            <label class="block text-sm font-medium text-slate-700 mt-4">
                Notes (optional)
                <textarea name="notes" id="f_notes" rows="2" placeholder="e.g., For convention hall events, portable unit preferred" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none"></textarea>
            </label>

            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                <?= $typesConnected
                    ? '<strong>Live catalog:</strong> asset types and operational counts pulled directly from UMAN.'
                    : '<strong>Fallback list:</strong> using a static 9-type list — UMAN asset-types endpoint was unreachable' . (!empty($typesApiError) ? ' (' . htmlspecialchars($typesApiError) . ')' : '') . '.';
                ?>
            </div>

            <button type="submit" class="btn-primary mt-4" <?= $apiKeyConfigured ? '' : 'disabled title="Configure UMAN_API_KEY in .env first"'; ?>>
                <?= $apiKeyConfigured ? 'Submit Request to UMAN' : 'UMAN API key not configured'; ?>
            </button>
        </form>
    </section>

    <aside class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6 h-fit">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Facility Equipment Summary</h2>
        <?php if (empty($facilities)): ?>
            <p class="text-sm text-slate-500">No facilities registered.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($facilities as $f): ?>
                    <?php $cnt = $assignedCounts[(int)$f['id']] ?? 0; ?>
                    <li class="py-2.5 flex items-center justify-between gap-2">
                        <span class="text-sm text-slate-700"><?= htmlspecialchars($f['name']); ?></span>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $cnt > 0 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-500'; ?>"><?= $cnt; ?> assigned</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" class="mt-5 pt-4 border-t border-dashed border-slate-200">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="sync_requests">
            <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                <i class="bi bi-arrow-repeat"></i> Sync Request Status from UMAN
            </button>
        </form>
    </aside>
</div>

<section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6 mt-6">
    <h2 class="text-base font-semibold text-slate-900 mb-3">UMAN Asset Catalog <?= $catalogLive ? '' : '<span class="text-xs font-normal text-slate-400">(catalog offline — requests still work)</span>'; ?></h2>
    <?php if (empty($umanAssets)): ?>
        <p class="text-sm text-slate-500 text-center py-8">
            <?= $apiError ? htmlspecialchars($apiError) : 'No assets returned from UMAN.'; ?>
        </p>
    <?php else: ?>
        <div class="mb-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-500 flex items-center gap-2">
            <i class="bi bi-lightbulb text-slate-400"></i>
            <span><strong class="text-slate-600">Tip:</strong> click any row to prefill the request form with that specific asset.</span>
        </div>
        <div class="table-responsive">
            <table class="table" id="asset-catalog-table">
                <thead>
                    <tr class="text-xs font-semibold uppercase tracking-wide text-slate-500">
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
                        <tr class="uman-catalog-row hover:bg-slate-50 <?= $rowClickable ? 'cursor-pointer' : 'opacity-75'; ?>"
                            data-code="<?= htmlspecialchars($code); ?>"
                            data-name="<?= htmlspecialchars($name); ?>"
                            data-type="<?= htmlspecialchars($type); ?>"
                            data-type-id="<?= $typeId; ?>"
                            data-resp="<?= htmlspecialchars($resp); ?>"
                            title="<?= $rowClickable ? 'Click to request this specific asset' : 'Asset not operational — click to view only' ?>">
                            <td data-label="Code"><strong class="font-mono text-slate-700"><?= htmlspecialchars($code); ?></strong></td>
                            <td data-label="Name"><?= htmlspecialchars($name); ?></td>
                            <td data-label="Type"><?= htmlspecialchars($type); ?></td>
                            <td data-label="Status"><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-emerald-50 text-emerald-700"><?= htmlspecialchars($cond); ?></span></td>
                            <td data-label="Location"><?= htmlspecialchars($loc); ?></td>
                            <td data-label="Responsible Office"><?= htmlspecialchars($resp); ?></td>
                            <td>
                                <?php if ($rowClickable): ?>
                                    <button type="button" class="uman-pin-btn inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 whitespace-nowrap"
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
<?php endif; ?>

<?php if ($tab === 'readings'): ?>
<?php if ($hasReadingTables):
    $curYear = (int)date('Y');
    $curMonth = (int)date('n');
    $readingsThisMonth = 0;
    $syncFailedCount = 0;
    foreach ($utilityLatestReadings as $r) {
        if ((int)$r['year'] === $curYear && (int)$r['month'] === $curMonth) {
            $readingsThisMonth++;
        }
        if (($r['sync_status'] ?? '') === 'failed') {
            $syncFailedCount++;
        }
    }
    $totalFacilities = count($utilityFacilities);
    $coveredFacilities = count($utilityLatestReadings);
?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-calendar-check text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Readings This Month</p>
            <p class="text-lg font-bold text-slate-900"><?= $readingsThisMonth; ?> / <?= $totalFacilities; ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-buildings text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Facilities Covered</p>
            <p class="text-lg font-bold text-slate-900"><?= $coveredFacilities; ?> / <?= $totalFacilities; ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full <?= $syncFailedCount > 0 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600'; ?> flex items-center justify-center flex-shrink-0">
            <i class="bi <?= $syncFailedCount > 0 ? 'bi-exclamation-circle' : 'bi-check-circle'; ?> text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Sync Status</p>
            <p class="text-lg font-bold text-slate-900"><?= $syncFailedCount > 0 ? "{$syncFailedCount} failed" : 'All synced'; ?></p>
        </div>
    </div>
</div>
<?php endif; ?>
<section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6">
    <h2 class="text-base font-semibold text-slate-900 mb-1">💧⚡ Utility Readings (Electric &amp; Water)</h2>
    <p class="text-sm text-slate-500 mb-4">
        Monthly readings sent to UMAN for consumption monitoring — UMAN forwards them to the LGU Energy system.
        One reading per facility per month.
    </p>

    <?php if (!$hasReadingTables): ?>
        <p class="text-sm text-slate-500">Run <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">database/migration_add_energy_integration.sql</code> and <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">database/migration_add_water_readings.sql</code> to enable utility readings.</p>
    <?php else: ?>

    <?php if ($utilityEditReading !== null): ?>
        <div class="mb-6 rounded-xl border border-slate-200 p-4 sm:p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Edit Reading</h3>
            <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update_utility_reading">
                <input type="hidden" name="reading_id" value="<?= (int)$utilityEditReading['id']; ?>">
                <label class="block text-sm font-medium text-slate-700">
                    Facility
                    <input type="text" value="<?= htmlspecialchars((string)$utilityEditReading['facility_name']); ?>" readonly class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                </label>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars((string)$utilityEditReading['reading_date']); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-amber-800 mb-2"><i class="bi bi-lightning-charge-fill"></i> Electricity</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" value="<?= htmlspecialchars((string)$utilityEditReading['previous_reading_kwh']); ?>" <?= $utilityEditReadingIsOnly ? '' : 'readonly'; ?> class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" required value="<?= htmlspecialchars((string)$utilityEditReading['current_reading_kwh']); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" required value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_kwh'] ?? 14.83), 2, '.', '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                </div>
                <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-sky-800 mb-2"><i class="bi bi-droplet-fill"></i> Water</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['previous_reading_water'] ?? '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['current_reading_water'] ?? '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_water'] ?? 68.02), 2, '.', '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                </div>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Notes (optional)
                    <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none"><?= htmlspecialchars((string)($utilityEditReading['notes'] ?? '')); ?></textarea>
                </label>
                <div class="mt-4 flex items-center gap-3">
                    <button type="submit" class="btn-primary">Save Correction</button>
                    <a href="<?= htmlspecialchars($umanTabUrl('readings')); ?>" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
                </div>
            </form>
        </div>
    <?php elseif ($canCreateReadings): ?>
        <div class="mb-6 rounded-xl border border-slate-200 p-4 sm:p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Add Reading</h3>
            <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="add_utility_reading">
                <label class="block text-sm font-medium text-slate-700">
                    Facility
                    <select name="facility_id" id="utility-facility-select" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <option value="">— Select facility —</option>
                        <?php foreach ($utilityFacilities as $f): ?>
                            <?php $last = $utilityLatestReadings[(int)$f['id']] ?? null; ?>
                            <option value="<?= (int)$f['id']; ?>"
                                data-prev-kwh="<?= $last !== null ? htmlspecialchars((string)$last['current_reading_kwh']) : ''; ?>"
                                data-rate-kwh="<?= $last !== null ? htmlspecialchars((string)($last['rate_per_kwh'] ?? '14.83')) : '14.83'; ?>"
                                data-prev-water="<?= ($last !== null && $last['current_reading_water'] !== null) ? htmlspecialchars((string)$last['current_reading_water']) : ''; ?>"
                                data-rate-water="<?= ($last !== null && $last['rate_per_water'] !== null) ? htmlspecialchars((string)$last['rate_per_water']) : '68.02'; ?>">
                                <?= htmlspecialchars($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Reading Month
                    <input type="month" name="reading_month" required value="<?= htmlspecialchars(date('Y-m')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars(date('Y-m-d')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-amber-800 mb-2"><i class="bi bi-lightning-charge-fill"></i> Electricity</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" id="utility-prev-kwh" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Auto-filled and locked when the facility already has a reading.</small>
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" id="utility-curr-kwh" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" id="utility-rate-kwh" required value="14.83" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Meralco residential all-in rate, July 2026 — adjust to the current tariff.</small>
                    </label>
                </div>
                <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-sky-800 mb-2"><i class="bi bi-droplet-fill"></i> Water (optional)</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" id="utility-prev-water" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Auto-filled and locked when the facility already has a water reading.</small>
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" id="utility-curr-water" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" id="utility-rate-water" value="68.02" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Manila Water East Zone (Quezon City), Q2 2026 tier — adjust to the current tariff.</small>
                    </label>
                </div>
                <p id="utility-consumption-preview" class="mt-3 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-emerald-800 font-semibold text-sm empty:border-0 empty:bg-transparent empty:p-0"></p>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Notes (optional)
                    <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none"></textarea>
                </label>
                <button type="submit" class="btn-primary mt-4">Save Reading</button>
            </form>
            <script>
            (function () {
                'use strict';
                var sel = document.getElementById('utility-facility-select');
                var prevKwh = document.getElementById('utility-prev-kwh');
                var currKwh = document.getElementById('utility-curr-kwh');
                var rateKwh = document.getElementById('utility-rate-kwh');
                var prevWater = document.getElementById('utility-prev-water');
                var currWater = document.getElementById('utility-curr-water');
                var rateWater = document.getElementById('utility-rate-water');
                var preview = document.getElementById('utility-consumption-preview');
                if (!sel || !prevKwh || !currKwh || !rateKwh || !preview) return;
                function updatePreview() {
                    var lines = [];
                    var pk = parseFloat(prevKwh.value), ck = parseFloat(currKwh.value), rk = parseFloat(rateKwh.value);
                    if (!isNaN(pk) && !isNaN(ck) && ck >= pk) {
                        lines.push('Electric: ' + (ck - pk).toFixed(2) + ' kWh' + (!isNaN(rk) && rk > 0 ? ' | PHP ' + ((ck - pk) * rk).toFixed(2) : ''));
                    }
                    var pw = parseFloat(prevWater.value), cw = parseFloat(currWater.value), rw = parseFloat(rateWater.value);
                    if (!isNaN(pw) && !isNaN(cw) && cw >= pw) {
                        lines.push('Water: ' + (cw - pw).toFixed(2) + ' m³' + (!isNaN(rw) && rw > 0 ? ' | PHP ' + ((cw - pw) * rw).toFixed(2) : ''));
                    }
                    preview.innerHTML = lines.join('<br>');
                }
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    if (!opt) return;
                    var lastKwh = opt.getAttribute('data-prev-kwh');
                    if (lastKwh) { prevKwh.value = lastKwh; prevKwh.readOnly = true; }
                    else { prevKwh.value = ''; prevKwh.readOnly = false; }
                    rateKwh.value = opt.getAttribute('data-rate-kwh') || '14.83';

                    var lastWater = opt.getAttribute('data-prev-water');
                    if (lastWater) { prevWater.value = lastWater; prevWater.readOnly = true; }
                    else { prevWater.value = ''; prevWater.readOnly = false; }
                    rateWater.value = opt.getAttribute('data-rate-water') || '68.02';
                    updatePreview();
                });
                [prevKwh, currKwh, rateKwh, prevWater, currWater, rateWater].forEach(function (el) {
                    el.addEventListener('input', updatePreview);
                });
            })();
            </script>
        </div>
    <?php endif; ?>

    <?php if ($utilityLatestReadings === []): ?>
        <p class="text-sm text-slate-500 text-center py-8">No utility readings recorded yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th>Facility</th><th>Period</th><th>Electric</th><th>Water</th><th>Sync</th><th>Recorded By</th>
                        <?php if ($canUpdateReadings || $canDeleteReadings): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($utilityLatestReadings as $r):
                        $syncBadgeClass = match($r['sync_status']) {
                            'synced' => 'bg-emerald-50 text-emerald-700',
                            'failed' => 'bg-red-50 text-red-700',
                            default  => 'bg-amber-50 text-amber-700',
                        };
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td data-label="Facility"><?= htmlspecialchars((string)$r['facility_name']); ?></td>
                            <td data-label="Period"><?= htmlspecialchars(($utilityMonthNames[(int)$r['month']] ?? $r['month']) . ' ' . $r['year']); ?></td>
                            <td data-label="Electric"><?= number_format((float)$r['consumption_kwh'], 2); ?> kWh · PHP <?= number_format((float)$r['consumption_kwh'] * (float)($r['rate_per_kwh'] ?? 14.83), 2); ?></td>
                            <td data-label="Water">
                                <?php if ($r['current_reading_water'] !== null): ?>
                                    <?= number_format((float)$r['consumption_water'], 2); ?> m³ · PHP <?= number_format((float)$r['consumption_water'] * (float)($r['rate_per_water'] ?? 68.02), 2); ?>
                                <?php else: ?>
                                    <span class="text-slate-400">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Sync">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $syncBadgeClass; ?>"
                                      <?= $r['sync_error'] !== null ? 'title="' . htmlspecialchars((string)$r['sync_error']) . '"' : ''; ?>>
                                    <?= htmlspecialchars(ucfirst((string)$r['sync_status'])); ?>
                                </span>
                            </td>
                            <td data-label="Recorded By"><?= htmlspecialchars((string)($r['recorded_by_name'] ?? '—')); ?></td>
                            <?php if ($canUpdateReadings || $canDeleteReadings): ?>
                            <td data-label="Actions" class="whitespace-nowrap">
                                <?php if ($canUpdateReadings): ?>
                                    <a href="<?= htmlspecialchars($umanTabUrl('readings') . '&edit_reading=' . (int)$r['id']); ?>" class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50"><i class="bi bi-pencil"></i> Edit</a>
                                <?php endif; ?>
                                <?php if ($canDeleteReadings && $r['sync_status'] !== 'synced'): ?>
                                    <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>" class="inline">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_utility_reading">
                                        <input type="hidden" name="reading_id" value="<?= (int)$r['id']; ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-md border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50" onclick="return confirm('Delete this reading?')"><i class="bi bi-trash"></i> Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'equipment'): ?>
<section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6 mt-6">
    <h2 class="text-base font-semibold text-slate-900 mb-3">Asset Requests</h2>
    <?php
    $displayRequests = $localRequests !== [] ? $localRequests : $remoteRequests;
    ?>
    <?php if (empty($displayRequests)): ?>
        <p class="text-sm text-slate-500 text-center py-8">No asset requests yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr class="text-xs font-semibold uppercase tracking-wide text-slate-500">
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
                            $assetLabel .= ' <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-600">' . htmlspecialchars($code) . '</span>';
                        }

                        $urgBadgeClass = match($urg) {
                            'Emergency' => 'bg-red-50 text-red-700',
                            'Priority'  => 'bg-amber-50 text-amber-700',
                            default     => 'bg-slate-100 text-slate-600',
                        };
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td data-label="Reference"><strong class="text-slate-700"><?= htmlspecialchars($ref); ?></strong></td>
                            <td data-label="Facility"><?= htmlspecialchars($fac); ?></td>
                            <td data-label="Asset"><?= $assetLabel; ?></td>
                            <td data-label="Qty"><?= $qty; ?></td>
                            <td data-label="Urgency"><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $urgBadgeClass; ?>"><?= htmlspecialchars($urg); ?></span></td>
                            <td data-label="Need By"><?= htmlspecialchars($need); ?></td>
                            <td data-label="Status"><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-50 text-amber-700"><?= htmlspecialchars(ucfirst($stat)); ?></span></td>
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
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
