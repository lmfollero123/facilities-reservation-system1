<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'maintenance')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../services/cimm_api.php';

$pdo = db();
$pageTitle = 'Maintenance Integration | LGU Facilities Reservation';

// Action feedback
$success = '';
$error = '';

// Apply blackout (manual / predictive maintenance action)
$hasBlackoutTable = false;
try {
    $checkBlackout = $pdo->query("SHOW TABLES LIKE 'facility_blackout_dates'");
    $hasBlackoutTable = (bool)$checkBlackout->fetchColumn();
} catch (Throwable $e) {
    $hasBlackoutTable = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_blackout') {
    if (!defined('CSRF_TOKEN_NAME') || !function_exists('verifyCSRFToken')) {
        $error = 'Security configuration missing. Unable to apply blackout.';
    } elseif (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } elseif (!$hasBlackoutTable) {
        $error = 'Blackout dates table is not available in the database. Please apply the blackout migration first.';
    } else {
        $facilityId = isset($_POST['facility_id']) ? (int)$_POST['facility_id'] : 0;
        $blackoutDate = isset($_POST['blackout_date']) ? trim((string)$_POST['blackout_date']) : '';
        $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : 'Maintenance blackout';

        if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $blackoutDate)) {
            $error = 'Invalid blackout request.';
        } else {
            try {
                $existsStmt = $pdo->prepare(
                    'SELECT id FROM facility_blackout_dates WHERE facility_id = :facility_id AND blackout_date = :date LIMIT 1'
                );
                $existsStmt->execute(['facility_id' => $facilityId, 'date' => $blackoutDate]);
                $exists = $existsStmt->fetch(PDO::FETCH_ASSOC);

                if ($exists) {
                    $success = 'Blackout already exists for this facility and date.';
                } else {
                    // facility_blackout_dates schema varies by migration; try common columns
                    $insertSql = 'INSERT INTO facility_blackout_dates (facility_id, blackout_date, reason, created_at) VALUES (:facility_id, :date, :reason, NOW())';
                    $ins = $pdo->prepare($insertSql);
                    $ins->execute(['facility_id' => $facilityId, 'date' => $blackoutDate, 'reason' => $reason]);
                    $success = 'Blackout date applied successfully. Booking for this facility will be blocked on that date.';
                }
            } catch (Throwable $e) {
                $error = 'Failed to apply blackout. Please try again.';
                error_log('Apply blackout error: ' . $e->getMessage());
            }
        }
    }
}

// Fetch maintenance schedules from CIMM API (read-only on page load — DB sync runs via cron/manual sync)
$apiResult = fetchCIMMMaintenanceSchedules();
$rawSchedules = $apiResult['data'] ?? [];
$apiError = $apiResult['error'] ?? null;
$maintenanceSchedules = mapCIMMToCPRF($rawSchedules);
$cimmSyncState = frs_cimm_load_sync_state();
$syncSummary = $cimmSyncState['last_summary'] ?: [
    'updated_to_maintenance' => 0,
    'updated_to_available' => 0,
    'blackouts_added' => 0,
    'blackouts_removed' => 0,
    'matched_schedule_count' => 0,
    'unmatched_schedule_count' => 0,
    'errors' => [],
];

// Separate completed schedules for history
$mockMaintenanceHistory = [];
$upcomingSchedules = [];
foreach ($maintenanceSchedules as $schedule) {
    if (strtolower($schedule['status']) === 'completed') {
        $mockMaintenanceHistory[] = [
            'id' => $schedule['id'],
            'facility_name' => $schedule['facility_name'],
            'maintenance_type' => $schedule['maintenance_type'],
            'completed_at' => $schedule['scheduled_end'],
            'status' => 'completed',
            'duration' => $schedule['estimated_duration'],
            'technician' => $schedule['assigned_team'],
            'notes' => $schedule['description'],
        ];
    } else {
        $upcomingSchedules[] = $schedule;
    }
}

// Use upcoming schedules for the main list (full set for calendar/schedule list)
$mockMaintenanceSchedules = $upcomingSchedules;

// Filters and pagination for "Upcoming Maintenance Schedules" table only
$statusFilter = $_GET['status'] ?? 'all';
$priorityFilter = $_GET['priority'] ?? 'all';
$upcomingFiltered = $upcomingSchedules;
if ($statusFilter !== 'all') {
    $upcomingFiltered = array_filter($upcomingFiltered, fn($s) => (strtolower($s['status'] ?? '') === $statusFilter));
}
if ($priorityFilter !== 'all') {
    $upcomingFiltered = array_filter($upcomingFiltered, fn($s) => (strtolower($s['priority'] ?? '') === $priorityFilter));
}
$totalFiltered = count($upcomingFiltered);
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$upcomingPaginated = array_slice(array_values($upcomingFiltered), $offset, $perPage);

// Get real facilities for dropdown
$facilities = [];
try {
    $facilitiesStmt = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore error
}

// Predictive Maintenance (rule-based, data-driven)
$predictiveMaintenanceRows = [];
try {
    $facilityUsageStmt = $pdo->query(
        "SELECT
            f.id,
            f.name,
            f.status,
            SUM(CASE WHEN r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS usage_90d,
            SUM(CASE WHEN r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS usage_30d
         FROM facilities f
         LEFT JOIN reservations r ON r.facility_id = f.id AND r.status IN ('approved','pending','pending_payment')
         GROUP BY f.id, f.name, f.status
         ORDER BY f.name ASC"
    );
    $facilityUsage = $facilityUsageStmt ? $facilityUsageStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $dowStmt = $pdo->query(
        "SELECT DAYOFWEEK(reservation_date) AS dow, COUNT(*) AS cnt
         FROM reservations
         WHERE reservation_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
           AND status IN ('approved','pending','pending_payment')
         GROUP BY DAYOFWEEK(reservation_date)"
    );
    $dowCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    if ($dowStmt) {
        foreach ($dowStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dow = (int)($row['dow'] ?? 0);
            if (isset($dowCounts[$dow])) {
                $dowCounts[$dow] = (int)($row['cnt'] ?? 0);
            }
        }
    }
    asort($dowCounts);
    $leastBusyDow = (int)array_key_first($dowCounts);
    $dowNames = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
    $leastBusyName = $dowNames[$leastBusyDow] ?? 'Sunday';

    foreach ($facilityUsage as $fRow) {
        $facilityId = (int)($fRow['id'] ?? 0);
        $usage90 = (int)($fRow['usage_90d'] ?? 0);
        $usage30 = (int)($fRow['usage_30d'] ?? 0);
        $status = strtolower((string)($fRow['status'] ?? 'available'));

        // Simple explainable risk score (0-100) from usage pressure + growth + current status.
        $usagePressure = min(60, (int)round($usage90 * 1.2));
        $growthPressure = min(25, max(0, ($usage30 - (int)round($usage90 / 3))) * 2);
        $statusPressure = ($status === 'maintenance') ? 15 : 0;
        $riskScore = min(100, $usagePressure + $growthPressure + $statusPressure);

        if ($riskScore >= 75) {
            $riskBand = 'High';
            $riskColor = '#b91c1c';
            $riskBg = '#fee2e2';
        } elseif ($riskScore >= 45) {
            $riskBand = 'Medium';
            $riskColor = '#b45309';
            $riskBg = '#fef3c7';
        } else {
            $riskBand = 'Low';
            $riskColor = '#166534';
            $riskBg = '#dcfce7';
        }

        // Recommend next low-demand weekday in the coming 14 days.
        $recommendedDate = null;
        for ($i = 1; $i <= 14; $i++) {
            $candidate = new DateTime('+' . $i . ' day');
            $phpDow = (int)$candidate->format('w'); // 0..6
            $mysqlDow = $phpDow === 0 ? 1 : $phpDow + 1; // 1..7
            if ($mysqlDow === $leastBusyDow) {
                $recommendedDate = $candidate->format('Y-m-d');
                break;
            }
        }

        $predictiveMaintenanceRows[] = [
            'facility_id' => $facilityId,
            'facility_name' => (string)($fRow['name'] ?? 'Facility'),
            'status' => ucfirst($status),
            'usage_90d' => $usage90,
            'usage_30d' => $usage30,
            'risk_score' => $riskScore,
            'risk_band' => $riskBand,
            'risk_color' => $riskColor,
            'risk_bg' => $riskBg,
            'recommended_date' => $recommendedDate,
            'recommended_window_label' => $recommendedDate
                ? (date('M d, Y', strtotime($recommendedDate)) . ' (' . $leastBusyName . ')')
                : ('Next ' . $leastBusyName),
        ];
    }

    usort($predictiveMaintenanceRows, static function (array $a, array $b): int {
        return (int)$b['risk_score'] <=> (int)$a['risk_score'];
    });
} catch (Throwable $e) {
    $predictiveMaintenanceRows = [];
}

// Integration status (API read + last persisted sync run)
$lastSyncAt = $cimmSyncState['last_sync_at'] ?? null;
$integrationStatus = [
    'connected' => !empty($rawSchedules) && empty($apiError),
    'last_sync' => $lastSyncAt ?: null,
    'sync_status' => empty($apiError) && !empty($rawSchedules) ? 'success' : (empty($apiError) ? 'no_data' : 'failed'),
    'pending_updates' => (int)($syncSummary['unmatched_schedule_count'] ?? 0),
    'error' => $apiError,
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Maintenance Integration</span>
    </div>
    <?= frs_page_title('Maintenance Integration', 'Syncs maintenance windows from CIMM. Scheduled work can set facilities to maintenance and add blackout dates.'); ?>
</div>

<!-- Integration Status Card -->
<div class="booking-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div>
            <?= frs_heading_with_tip('Integration Status', 'Pulls maintenance schedules from CIMM when connected. Sync updates facility status and blackout dates.', 'h2'); ?>
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span class="status-badge <?= $integrationStatus['connected'] ? 'active' : 'offline'; ?>" style="font-size: 0.9rem;">
                    <?= $integrationStatus['connected'] ? '✓ Connected' : '✗ Disconnected'; ?>
                </span>
                <small style="color: #8b95b5;">
                    Last sync: <?= $integrationStatus['last_sync']
                        ? date('M d, Y H:i', strtotime($integrationStatus['last_sync']))
                        : 'Never (run cron or Sync Now)'; ?>
                </small>
                <?php if ($integrationStatus['pending_updates'] > 0): ?>
                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        <?= $integrationStatus['pending_updates']; ?> pending update(s)
                    </span>
                <?php endif; ?>
            </div>
            <?php if (!empty($integrationStatus['error'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #fee2e2; border-left: 4px solid #dc2626; border-radius: 4px;">
                    <strong style="color: #dc2626; display: block; margin-bottom: 0.25rem;">Connection Error:</strong>
                    <small style="color: #991b1b;"><?= htmlspecialchars($integrationStatus['error']); ?></small>
                    <div style="margin-top: 0.5rem;">
                        <small style="color: #991b1b;">
                            <strong>Solution:</strong> Ensure CIMM has set up their API endpoint at 
                            <code style="background: rgba(0,0,0,0.1); padding: 2px 4px; border-radius: 2px;">https://cimm.infragovservices.com/api/maintenance-schedules.php</code>
                            <br>See <code>docs/CIMM_API_INTEGRATION.md</code> for setup instructions.
                        </small>
                    </div>
                </div>
            <?php elseif (empty($rawSchedules) && empty($integrationStatus['error'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;">
                    <small style="color: #92400e;">
                        <strong>Note:</strong> Connected successfully but no maintenance schedules found. 
                        CIMM may not have any scheduled maintenance yet.
                    </small>
                </div>
            <?php endif; ?>
        </div>
        <button class="btn-outline" onclick="syncMaintenanceData()" style="padding: 0.5rem 1rem;">
            🔄 Sync Now
        </button>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <?php if (!empty($integrationStatus['last_sync'])): ?>
            <div style="margin-top:0.65rem; color:#4b5563; font-size:0.85rem; display:flex; gap:0.8rem; flex-wrap:wrap;">
                <span>🔁 Updated to maintenance: <strong><?= (int)($syncSummary['updated_to_maintenance'] ?? 0); ?></strong></span>
                <span>✅ Updated to available: <strong><?= (int)($syncSummary['updated_to_available'] ?? 0); ?></strong></span>
                <span>📅 Blackouts added: <strong><?= (int)($syncSummary['blackouts_added'] ?? 0); ?></strong></span>
                <span>🗑️ Blackouts removed: <strong><?= (int)($syncSummary['blackouts_removed'] ?? 0); ?></strong></span>
                <span>🎯 Matched schedules: <strong><?= (int)($syncSummary['matched_schedule_count'] ?? 0); ?></strong></span>
                <span>⚠️ Unmatched schedules: <strong><?= (int)($syncSummary['unmatched_schedule_count'] ?? 0); ?></strong></span>
            </div>
            <?php if (!empty($syncSummary['errors'])): ?>
                <div style="margin-top: 0.5rem; padding: 0.55rem 0.7rem; background: #fee2e2; border-left: 4px solid #dc2626; border-radius: 4px; color:#991b1b; font-size:0.83rem;">
                    <strong>Sync warnings:</strong> <?= htmlspecialchars(implode(' | ', (array)$syncSummary['errors'])); ?>
                </div>
            <?php endif; ?>
            <small style="color:#8b95b5; display:block; margin-top:0.5rem;">
                Facility status and blackout writes run via <code>scripts/sync_cimm_maintenance.php</code> (cron or Sync Now), not on every page view.
            </small>
        <?php else: ?>
            <small style="color:#8b95b5;">
                No sync has run yet. Schedule <code>php scripts/sync_cimm_maintenance.php</code> in cron, or click Sync Now.
            </small>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
    <div class="message success" style="background:#e3f8ef;color:#0d7a43;padding:0.85rem 1rem;border-radius:10px;margin-bottom:1rem;border:1px solid rgba(16,185,129,0.25);">
        <?= htmlspecialchars($success); ?>
    </div>
<?php elseif ($error): ?>
    <div class="message error" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:10px;margin-bottom:1rem;border:1px solid rgba(239,68,68,0.25);">
        <?= htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<section class="booking-card" style="margin-bottom: 1.5rem;">
    <h2>Predictive Maintenance (Recommendation)</h2>
    <p style="color:#6b7280; margin:0.2rem 0 1rem;" class="predictive-maintenance-desc">
        Rule-based forecast from recent booking pressure (last 90/30 days) to suggest low-demand maintenance windows.
    </p>
    <?php if (empty($predictiveMaintenanceRows)): ?>
        <p style="color:#8b95b5; text-align:center; padding:1.2rem 0;" class="predictive-maintenance-empty">Not enough data yet to generate maintenance risk forecasts.</p>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:0.75rem;" class="predictive-maintenance-grid">
            <?php foreach (array_slice($predictiveMaintenanceRows, 0, 8) as $row): ?>
                <div style="border:1px solid #e5e7eb; border-radius:12px; padding:0.8rem 0.9rem; background:#fff;" class="predictive-maintenance-card">
                    <div style="display:flex; justify-content:space-between; gap:0.5rem; align-items:flex-start;">
                        <div style="font-weight:800; color:#0f172a;" class="predictive-facility-name"><?= htmlspecialchars($row['facility_name']); ?></div>
                        <span style="background:<?= htmlspecialchars($row['risk_bg']); ?>; color:<?= htmlspecialchars($row['risk_color']); ?>; padding:0.2rem 0.55rem; border-radius:999px; font-size:0.78rem; font-weight:800;" class="predictive-risk-badge">
                            <?= htmlspecialchars($row['risk_band']); ?> Risk
                        </span>
                    </div>
                    <div style="margin-top:0.5rem; color:#475569; font-size:0.86rem;" class="predictive-maintenance-details">
                        <div>Risk score: <strong><?= (int)$row['risk_score']; ?>/100</strong></div>
                        <div>Usage (90d): <strong><?= (int)$row['usage_90d']; ?></strong> bookings</div>
                        <div>Usage (30d): <strong><?= (int)$row['usage_30d']; ?></strong> bookings</div>
                        <div>Status: <strong><?= htmlspecialchars($row['status']); ?></strong></div>
                        <div style="margin-top:0.25rem;">Suggested maintenance window: <strong><?= htmlspecialchars($row['recommended_window_label']); ?></strong></div>
                    </div>
                    <div style="margin-top:0.75rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <?php if ($hasBlackoutTable && !empty($row['recommended_date']) && (int)($row['facility_id'] ?? 0) > 0): ?>
                            <form method="POST" style="margin:0;">
                                <?= function_exists('csrf_field') ? csrf_field() : '<input type="hidden" name="' . htmlspecialchars(CSRF_TOKEN_NAME, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">' ?>
                                <input type="hidden" name="action" value="apply_blackout">
                                <input type="hidden" name="facility_id" value="<?= (int)$row['facility_id']; ?>">
                                <input type="hidden" name="blackout_date" value="<?= htmlspecialchars((string)$row['recommended_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="reason" value="<?= htmlspecialchars('Predictive Maintenance: recommended low-demand window', ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn-outline" style="padding:0.45rem 0.7rem; border-radius:10px; font-weight:800;">
                                    Apply Blackout
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="font-size:0.82rem; color:#6b7280;" class="predictive-maintenance-note">
                                <?= $hasBlackoutTable ? 'No recommended date available.' : 'Blackout feature unavailable (missing table).' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<style>
/* Dark mode for Predictive Maintenance section */
html[data-theme="dark"] section.booking-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}

html[data-theme="dark"] .predictive-maintenance-desc {
    color: #cbd5e1 !important;
}

html[data-theme="dark"] .predictive-maintenance-empty {
    color: #94a3b8 !important;
}

html[data-theme="dark"] .predictive-maintenance-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}

html[data-theme="dark"] .predictive-facility-name {
    color: #f1f5f9 !important;
}

html[data-theme="dark"] .predictive-maintenance-details {
    color: #cbd5e1 !important;
}

html[data-theme="dark"] .predictive-maintenance-details strong {
    color: #f1f5f9 !important;
}

html[data-theme="dark"] .predictive-maintenance-details div {
    color: #cbd5e1 !important;
}

html[data-theme="dark"] .predictive-maintenance-note {
    color: #94a3b8 !important;
}

/* Dark mode for the section header */
html[data-theme="dark"] .booking-card h2 {
    color: #f1f5f9 !important;
}

/* Dark mode for the Apply Blackout button */
html[data-theme="dark"] .predictive-maintenance-card .btn-outline {
    color: #f1f5f9 !important;
    border-color: #475569 !important;
}

html[data-theme="dark"] .predictive-maintenance-card .btn-outline:hover {
    background: #334155 !important;
    border-color: #64748b !important;
}

/* Dark mode for risk badge - ensure it remains visible */
html[data-theme="dark"] .predictive-risk-badge {
    color: #f1f5f9 !important;
}

/* Override inline styles for dark mode */
html[data-theme="dark"] .predictive-maintenance-card [style*="color:#0f172a"] {
    color: #f1f5f9 !important;
}

html[data-theme="dark"] .predictive-maintenance-card [style*="color:#475569"] {
    color: #cbd5e1 !important;
}

html[data-theme="dark"] .predictive-maintenance-card [style*="color:#6b7280"] {
    color: #94a3b8 !important;
}

html[data-theme="dark"] section.booking-card [style*="color:#6b7280"] {
    color: #cbd5e1 !important;
}
</style>

<div class="booking-wrapper">
    <!-- Upcoming Maintenance Schedules -->
    <section class="booking-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>Upcoming Maintenance Schedules</h2>
            <div style="display: flex; gap: 0.5rem;">
                <select id="filterStatus" onchange="applyMaintenanceFilters()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <select id="filterPriority" onchange="applyMaintenanceFilters()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                    <option value="all" <?= $priorityFilter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                    <option value="high" <?= $priorityFilter === 'high' ? 'selected' : ''; ?>>High</option>
                    <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="low" <?= $priorityFilter === 'low' ? 'selected' : ''; ?>>Low</option>
                </select>
            </div>
        </div>

        <?php if ($totalFiltered === 0): ?>
            <p style="color: #8b95b5; text-align: center; padding: 2rem;">No upcoming maintenance schedules.</p>
        <?php else: ?>
            <div class="table-responsive table-responsive--maintenance">
                <table class="table table--maintenance-schedules">
                    <thead>
                        <tr>
                            <th>Maintenance ID</th>
                            <th>Facility</th>
                            <th>Type</th>
                            <th>Scheduled Date</th>
                            <th>Duration</th>
                            <th class="th-badge">Priority</th>
                            <th class="th-badge">Status</th>
                            <th>Affected</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="maintenanceTableBody">
                        <?php foreach ($upcomingPaginated as $schedule): 
                            $priorityClass = $schedule['priority'] === 'high' ? 'offline' : ($schedule['priority'] === 'medium' ? 'maintenance' : 'active');
                            $statusClass = $schedule['status'] === 'in_progress' ? 'maintenance' : ($schedule['status'] === 'completed' ? 'active' : 'offline');
                            $statusDisplay = ucfirst(str_replace('_', ' ', $schedule['status']));
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($schedule['id']); ?></strong></td>
                                <td><?= htmlspecialchars($schedule['facility_name']); ?></td>
                                <td><?= htmlspecialchars($schedule['maintenance_type']); ?></td>
                                <td>
                                    <?= date('M d, Y', strtotime($schedule['scheduled_start'])); ?><br>
                                    <small style="color: #8b95b5;">
                                        <?= date('H:i', strtotime($schedule['scheduled_start'])); ?> - 
                                        <?= date('H:i', strtotime($schedule['scheduled_end'])); ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($schedule['estimated_duration']); ?></td>
                                <td class="td-badge">
                                    <span class="status-badge status-badge--cell <?= $priorityClass; ?>" style="text-transform: capitalize;" title="<?= htmlspecialchars($schedule['priority']); ?>">
                                        <?= htmlspecialchars($schedule['priority']); ?>
                                    </span>
                                </td>
                                <td class="td-badge">
                                    <span class="status-badge status-badge--cell <?= $statusClass; ?>" title="<?= htmlspecialchars($statusDisplay); ?>">
                                        <?= $statusDisplay; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($schedule['affected_reservations'] > 0): ?>
                                        <span style="color: #dc3545; font-weight: 600;">
                                            <?= $schedule['affected_reservations']; ?> reservation(s)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #8b95b5;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-outline" onclick="viewMaintenanceDetails('<?= htmlspecialchars($schedule['id']); ?>')" style="padding: 0.35rem 0.6rem; font-size: 0.85rem;">
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $linkParams = array_filter(['status' => $statusFilter !== 'all' ? $statusFilter : null, 'priority' => $priorityFilter !== 'all' ? $priorityFilter : null]);
            $prevQuery = $page > 1 ? http_build_query($linkParams + ['page' => $page - 1]) : '';
            $nextQuery = $page < $totalPages ? http_build_query($linkParams + ['page' => $page + 1]) : '';
            ?>
            <div class="pagination-bar" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
                <span style="color: #6b7280; font-size: 0.9rem;">
                    Showing <?= $totalFiltered ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $totalFiltered); ?> of <?= $totalFiltered; ?>
                </span>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <?php if ($prevQuery): ?>
                        <a href="?<?= htmlspecialchars($prevQuery); ?>" class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem;">← Prev</a>
                    <?php else: ?>
                        <span class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem; opacity: 0.5; pointer-events: none;">← Prev</span>
                    <?php endif; ?>
                    <span style="font-size: 0.9rem; color: #4b5563;">Page <?= $page; ?> of <?= $totalPages; ?></span>
                    <?php if ($nextQuery): ?>
                        <a href="?<?= htmlspecialchars($nextQuery); ?>" class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem;">Next →</a>
                    <?php else: ?>
                        <span class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem; opacity: 0.5; pointer-events: none;">Next →</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- Maintenance Calendar (New Design) -->
    <aside class="booking-card maintenance-calendar-wrapper">
        <h2>Maintenance Calendar</h2>
        
        <!-- Mobile Controls (Mobile Only) -->
        <div class="mobile-controls" id="mobileListControls" style="display:none;">
            <input id="mobileScheduleSearch" type="text" placeholder="Search schedules...">
            <button id="mobileToCalendarBtn" class="mobile-calendar-btn">📅</button>
        </div>
        <div class="mobile-controls" id="mobileCalendarControls" style="display:none;">
            <button id="mobilePrevMonth" class="mobile-toggle-btn">&#8592;</button>
            <span id="mobileMonthLabel" title="Click to jump date"></span>
            <button id="mobileToListBtn" class="mobile-schedule-btn">📋</button>
            <button id="mobileNextMonth" class="mobile-toggle-btn">&#8594;</button>
        </div>

        <!-- Calendar View -->
        <div id="calendarView">
            <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel" title="Click to jump date"></span>
                <div style="display:flex; gap:8px;">
                    <button id="toListBtn" class="schedule-btn" title="Schedule List">📋</button>
                    <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">&#8594;</button>
                </div>
            </div>
            <div class="calendar-weekdays">
                <div>Sunday</div>
                <div>Monday</div>
                <div>Tuesday</div>
                <div>Wednesday</div>
                <div>Thursday</div>
                <div>Friday</div>
                <div>Saturday</div>
            </div>
            <div class="calendar-grid" id="calendarGrid"></div>
            <div class="calendar-details-card">
                <div class="calendar-details" id="calendarDetails">
                    Select a date to view schedule.
                </div>
                <div class="scroll-indicator">⌄</div>
            </div>
        </div>
        
        <!-- List View -->
        <div id="scheduleView" class="hidden">
            <div style="display:flex; gap:10px; align-items:center;">
                <input id="scheduleSearch" type="text" placeholder="Search by task, location, category, status, or date..." style="flex:1;">
                <button id="toCalendarBtn" class="calendar-btn" title="Calendar View">📅</button>
            </div>
            <div id="scheduleListHolder">
                <?php if (empty($mockMaintenanceSchedules)): ?>
                    <p id="noScheduleMsg">No scheduled maintenance.</p>
                <?php else: 
                    foreach ($mockMaintenanceSchedules as $row): 
                        $scheduleDate = date('Y-m-d', strtotime($row['scheduled_start'] ?? 'now'));
                ?>
                    <div class="schedule-item"
                        data-task="<?= htmlspecialchars(strtolower($row['maintenance_type'] ?? $row['task'] ?? '')) ?>"
                        data-location="<?= htmlspecialchars(strtolower($row['facility_name'] ?? $row['location'] ?? '')) ?>"
                        data-category="<?= htmlspecialchars(strtolower($row['category'] ?? '')) ?>"
                        data-status="<?= htmlspecialchars(strtolower($row['status_label'] ?? $row['status'] ?? '')) ?>"
                        data-priority="<?= htmlspecialchars(strtolower($row['priority'] ?? '')) ?>"
                        data-date="<?= htmlspecialchars(strtolower(date("F d, Y", strtotime($scheduleDate)) . '|' . $scheduleDate)) ?>">
                        <div>
                            <strong><?= htmlspecialchars($row['maintenance_type'] ?? $row['task'] ?? 'Maintenance') ?></strong><br>
                            <?= htmlspecialchars($row['facility_name'] ?? $row['location'] ?? '') ?><br>
                            <?php if (!empty($row['category'])): ?>
                                <span class="badge badge-category"><?= htmlspecialchars($row['category']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="schedule-date">
                            <?= date("F d, Y", strtotime($scheduleDate)) ?><br>
                            <?php
                                $priorityClass = 'badge-priority-low';
                                $priorityLower = strtolower($row['priority'] ?? '');
                                if ($priorityLower === 'medium') {
                                    $priorityClass = 'badge-priority-medium';
                                } elseif ($priorityLower === 'high') {
                                    $priorityClass = 'badge-priority-high';
                                } elseif ($priorityLower === 'critical') {
                                    $priorityClass = 'badge-priority-critical';
                                }

                                $statusClass = 'badge-status-planned';
                                $statusLower = strtolower($row['status_label'] ?? $row['status'] ?? '');
                                if ($statusLower === 'completed') {
                                    $statusClass = 'badge-status-completed';
                                } elseif ($statusLower === 'in progress' || $statusLower === 'in_progress') {
                                    $statusClass = 'badge-status-in-progress';
                                } elseif ($statusLower === 'delayed') {
                                    $statusClass = 'badge-status-delayed';
                                } elseif ($statusLower === 'scheduled') {
                                    $statusClass = 'badge-status-scheduled';
                                }
                            ?>
                            <?php if (!empty($row['status_label'])): ?>
                                <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($row['status_label']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['priority'])): ?>
                                <span class="badge <?= $priorityClass ?>"><?= htmlspecialchars($row['priority']) ?> priority</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                    <p id="noResultMsg" style="display:none;">No matching data or result.</p>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<!-- Maintenance History Section -->
<section class="booking-card" style="margin-top: 1.5rem;">
    <h2>Maintenance History</h2>
    <?php if (empty($mockMaintenanceHistory)): ?>
        <p style="color: #8b95b5; text-align: center; padding: 2rem;">No maintenance history available.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Maintenance ID</th>
                        <th>Facility</th>
                        <th>Type</th>
                        <th>Completed Date</th>
                        <th>Duration</th>
                        <th>Technician</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockMaintenanceHistory as $history): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($history['id']); ?></strong></td>
                            <td><?= htmlspecialchars($history['facility_name']); ?></td>
                            <td><?= htmlspecialchars($history['maintenance_type']); ?></td>
                            <td><?= date('M d, Y H:i', strtotime($history['completed_at'])); ?></td>
                            <td><?= htmlspecialchars($history['duration']); ?></td>
                            <td><?= htmlspecialchars($history['technician']); ?></td>
                            <td>
                                <span class="status-badge active">Completed</span>
                            </td>
                            <td>
                                <button class="btn-outline" onclick="viewMaintenanceHistory('<?= htmlspecialchars($history['id']); ?>')" style="padding: 0.35rem 0.6rem; font-size: 0.85rem;">
                                    View Details
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Maintenance Details Modal (will be implemented with JavaScript) -->
<div id="maintenanceModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 id="modalTitle">Maintenance Details</h3>
            <button onclick="closeMaintenanceModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <div id="modalContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<script>
function syncMaintenanceData() {
    const btn = event.target;
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '🔄 Syncing...';

    fetch('<?= base_path(); ?>/scripts/sync_cimm_maintenance.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'FRS-CIMM-Sync' }
    })
    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
    .then(function (result) {
        if (result.ok && result.data && result.data.success) {
            window.location.reload();
            return;
        }
        const msg = (result.data && (result.data.message || result.data.error)) ? (result.data.message || result.data.error) : 'Sync failed.';
        alert(msg);
        btn.disabled = false;
        btn.textContent = originalText;
    })
    .catch(function () {
        alert('Sync request failed. Check server logs or run: php scripts/sync_cimm_maintenance.php');
        btn.disabled = false;
        btn.textContent = originalText;
    });
}

// Test CIMM connection (for debugging)
function testCIMMConnection() {
    alert('To test CIMM connection, run: php test_cimm_connection.php\n\nOr check the error message displayed in the Integration Status card.');
}

function applyMaintenanceFilters() {
    var params = new URLSearchParams(window.location.search);
    params.set('status', document.getElementById('filterStatus').value);
    params.set('priority', document.getElementById('filterPriority').value);
    params.set('page', '1');
    window.location.href = window.location.pathname + '?' + params.toString();
}

function updateMaintenanceCalendar() {
    // Placeholder for calendar update
    const facilityId = document.getElementById('calendarFacility').value;
    console.log('Updating calendar for facility:', facilityId);
    // Will filter maintenance schedules by facility when API is integrated
}

function viewMaintenanceDetails(maintenanceId, date = null) {
    if (!maintenanceId && !date) return;
    
    const modal = document.getElementById('maintenanceModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    // Find the schedule from window.scheduleData
    let schedule = null;
    if (maintenanceId) {
        // Prefer exact ID match (supports both CIMM-S-* and CIMM-R-*)
        schedule = window.scheduleData.find(s => s.id === maintenanceId);
        if (!schedule) {
            // Backward compatibility with old format CIMM-<sched_id>
            const idMatch = maintenanceId.match(/^CIMM-(\d+)$/);
            if (idMatch) {
                schedule = window.scheduleData.find(s => String(s.sched_id) === idMatch[1]);
            }
        }
    } else if (date) {
        schedule = window.scheduleData.find(s => s.schedule_date === date);
    }
    
    if (!schedule) {
        modalTitle.textContent = maintenanceId ? `Maintenance: ${maintenanceId}` : `Maintenance on ${date}`;
        modalContent.innerHTML = '<p>Schedule details not found.</p>';
        modal.style.display = 'flex';
        return;
    }
    
    modalTitle.textContent = `Maintenance: ${schedule.task || 'Maintenance'}`;
    
    const startDate = schedule.starting_date ? new Date(schedule.starting_date).toLocaleString() : 'N/A';
    const endDate = schedule.estimated_completion_date ? new Date(schedule.estimated_completion_date).toLocaleString() : 'N/A';
    
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Facility:</strong> ${schedule.location || 'N/A'}<br>
            <strong>Type:</strong> ${schedule.task || 'N/A'}<br>
            <strong>Scheduled:</strong> ${startDate} - ${endDate}<br>
            <strong>Priority:</strong> ${schedule.priority || 'N/A'}<br>
            <strong>Status:</strong> ${schedule.status_label || schedule.status || 'N/A'}<br>
            <strong>Team:</strong> ${schedule.assigned_team || 'N/A'}<br>
            <strong>Category:</strong> ${schedule.category || 'General Maintenance'}
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color, #e0e6ed);">
            <small>
                <strong>Note:</strong> This facility will be automatically set to 'maintenance' status during this period. 
                Affected reservations will be notified.
            </small>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function viewMaintenanceHistory(maintenanceId) {
    const modal = document.getElementById('maintenanceModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = `Maintenance History: ${maintenanceId}`;
    
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Facility:</strong> Community Convention Hall<br>
            <strong>Type:</strong> Routine Inspection<br>
            <strong>Completed:</strong> December 15, 2024 12:30<br>
            <strong>Duration:</strong> 4 hours<br>
            <strong>Technician:</strong> John Doe<br>
            <strong>Status:</strong> Completed
        </div>
        <div style="margin-bottom: 1rem;">
            <strong>Notes:</strong><br>
            <p style="margin-top: 0.5rem;">All systems operational. No issues found.</p>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function closeMaintenanceModal() {
    document.getElementById('maintenanceModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('maintenanceModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeMaintenanceModal();
    }
});

// =============== SCHEDULE DATA FOR CALENDAR ===============
window.scheduleData = <?= json_encode(array_map(function($schedule) {
    return [
        'id' => $schedule['id'] ?? '',
        'source' => $schedule['source'] ?? 'schedule',
        'sched_id' => $schedule['sched_id'] ?? '',
        'rep_id' => $schedule['rep_id'] ?? '',
        'task' => $schedule['maintenance_type'] ?? $schedule['task'] ?? '',
        'location' => $schedule['facility_name'] ?? $schedule['location'] ?? '',
        'category' => $schedule['category'] ?? 'General Maintenance',
        'priority' => ucfirst($schedule['priority'] ?? 'Low'),
        'status' => $schedule['status_label'] ?? $schedule['status'] ?? 'Scheduled',
        'status_label' => $schedule['status_label'] ?? $schedule['status'] ?? 'Scheduled',
        'assigned_team' => $schedule['assigned_team'] ?? '',
        'starting_date' => $schedule['scheduled_start'] ?? '',
        'estimated_completion_date' => $schedule['scheduled_end'] ?? '',
        'schedule_date' => date('Y-m-d', strtotime($schedule['scheduled_start'] ?? 'now'))
    ];
}, $maintenanceSchedules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// ============ NEW CALENDAR FUNCTIONALITY ============
(function() {
    'use strict';
    
    function isMobileView() {
        return window.innerWidth <= 768;
    }
    
    const calendarGrid = document.getElementById('calendarGrid');
    const calendarDetails = document.getElementById('calendarDetails');
    const monthLabel = document.getElementById('monthLabel');
    const mobileMonthLabel = document.getElementById('mobileMonthLabel');
    const calendarView = document.getElementById('calendarView');
    const scheduleView = document.getElementById('scheduleView');
    const scheduleSearch = document.getElementById('scheduleSearch');
    const scheduleListHolder = document.getElementById('scheduleListHolder');
    const noResultMsg = document.getElementById('noResultMsg');
    const toCalendarBtn = document.getElementById('toCalendarBtn');
    const toListBtn = document.getElementById('toListBtn');
    const mobileListControls = document.getElementById('mobileListControls');
    const mobileCalendarControls = document.getElementById('mobileCalendarControls');
    const mobileToCalendarBtn = document.getElementById('mobileToCalendarBtn');
    const mobileToListBtn = document.getElementById('mobileToListBtn');
    const mobilePrevMonth = document.getElementById('mobilePrevMonth');
    const mobileNextMonth = document.getElementById('mobileNextMonth');
    const mobileScheduleSearch = document.getElementById('mobileScheduleSearch');
    const prevMonthBtn = document.getElementById('prevMonth');
    const nextMonthBtn = document.getElementById('nextMonth');
    
    if (!calendarGrid || !calendarDetails) return;
    
    let currentDate = new Date();
    let showingCalendar = true;
    
    function getStatusKey(statusLabel) {
        const s = (statusLabel || '').toLowerCase();
        if (!s) return 'upcoming';
        if (s.indexOf('delay') !== -1) return 'delayed';
        if (s.indexOf('progress') !== -1 || s.indexOf('on-going') !== -1 || s.indexOf('ongoing') !== -1) return 'ongoing';
        if (s.indexOf('completed') !== -1) return 'completed';
        return 'upcoming';
    }
    
    function renderCalendar() {
        if (!calendarGrid || !calendarDetails) return;
        calendarGrid.innerHTML = '';
        calendarDetails.innerHTML = 'Select a date to view schedule.';
        
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const monthText = currentDate.toLocaleString('default', {month: 'long', year: 'numeric'});
        if (monthLabel) monthLabel.textContent = monthText;
        if (mobileMonthLabel) mobileMonthLabel.textContent = monthText;
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        for (let i = 0; i < firstDay; i++) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'calendar-day';
            calendarGrid.appendChild(emptyDiv);
        }
        
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const events = Array.isArray(window.scheduleData) && window.scheduleData.length
                ? window.scheduleData.filter(e => e.schedule_date === dateStr)
                : [];
            
            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');
            dayDiv.setAttribute('data-date', dateStr);
            
            const dayNumDiv = document.createElement('div');
            dayNumDiv.textContent = d;
            dayDiv.appendChild(dayNumDiv);
            
            if (events.length) {
                const tasksDiv = document.createElement('div');
                tasksDiv.className = 'day-tasks';
                
                if (events.length === 1) {
                    const e = events[0];
                    const btn = document.createElement('button');
                    btn.className = 'task-btn';
                    btn.textContent = isMobileView() ? '1' : (e.task || 'Maintenance');
                    btn.title = `${e.task || 'Maintenance'} (${e.status_label || ''})`;
                    const key = getStatusKey(e.status_label);
                    if (key) btn.classList.add('status-' + key + '-bg');
                    btn.onclick = function(ev) {
                        ev.stopPropagation();
                        viewMaintenanceDetails(e.id || (e.sched_id ? ('CIMM-S-' + e.sched_id) : ''), dateStr);
                    };
                    tasksDiv.appendChild(btn);
                } else if (events.length > 1) {
                    const first = events[0];
                    const firstBtn = document.createElement('button');
                    firstBtn.className = 'task-btn';
                    firstBtn.textContent = isMobileView() ? '1' : (first.task || 'Maintenance');
                    firstBtn.title = `${first.task || 'Maintenance'} (${first.status_label || ''})`;
                    const firstKey = getStatusKey(first.status_label);
                    if (firstKey) firstBtn.classList.add('status-' + firstKey + '-bg');
                    firstBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        viewMaintenanceDetails(first.id || (first.sched_id ? ('CIMM-S-' + first.sched_id) : ''), dateStr);
                    };
                    tasksDiv.appendChild(firstBtn);
                    
                    const moreWrap = document.createElement('div');
                    moreWrap.className = 'more-tasks-wrap';
                    const arrowBtn = document.createElement('button');
                    arrowBtn.className = 'more-tasks-btn';
                    arrowBtn.innerHTML = '▾';
                    arrowBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        const tasks = events.map(e => ({
                            id: e.id,
                            sched_id: e.sched_id,
                            rep_id: e.rep_id,
                            task: e.task,
                            location: e.location,
                            category: e.category,
                            priority: e.priority,
                            status_label: e.status_label,
                            assigned_team: e.assigned_team,
                            schedule_date: dateStr
                        }));
                        openTaskChooser(dateStr, tasks);
                    };
                    moreWrap.appendChild(arrowBtn);
                    if (!isMobileView()) {
                        const counter = document.createElement('span');
                        counter.className = 'task-counter';
                        counter.textContent = `+${events.length - 1}`;
                        moreWrap.appendChild(counter);
                    }
                    tasksDiv.appendChild(moreWrap);
                }
                dayDiv.appendChild(tasksDiv);
            }
            
            dayDiv.addEventListener('click', function() {
                if (events.length) {
                    let detailsHtml = `<strong>${dateStr}</strong><br>`;
                    detailsHtml += events.map(e => `• ${e.task || 'Maintenance'} – ${e.location || ''}`).join('<br>');
                    calendarDetails.innerHTML = detailsHtml;
                } else {
                    calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>No scheduled maintenance.`;
                }
            });
            
            calendarGrid.appendChild(dayDiv);
        }
    }
    
    function openTaskChooser(date, tasks) {
        const modal = document.getElementById('maintenanceModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalContent = document.getElementById('modalContent');
        
        modalTitle.textContent = `Select a Task - ${date}`;
        modalContent.innerHTML = '';
        
        tasks.forEach(t => {
            const btn = document.createElement('button');
            btn.className = 'btn-outline';
            btn.style.cssText = 'width: 100%; margin: 0.5rem 0; padding: 0.75rem; text-align: left; background: var(--bg-secondary, #fff); color: var(--text-primary, #2c3e50); border: 1px solid var(--border-color, #e0e6ed);';
            btn.textContent = `${t.task || 'Maintenance'} – ${t.location || ''}`;
            btn.onclick = () => {
                modal.style.display = 'none';
                viewMaintenanceDetails(t.id || (t.sched_id ? ('CIMM-S-' + t.sched_id) : ''), date);
            };
            modalContent.appendChild(btn);
        });
        
        modal.style.display = 'flex';
    }
    
    function showCalendarView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.remove('hidden');
        scheduleView.classList.add('hidden');
        showingCalendar = true;
        updateMobileControls();
    }
    
    function showListView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.add('hidden');
        scheduleView.classList.remove('hidden');
        showingCalendar = false;
        updateMobileControls();
    }
    
    function updateMobileControls() {
        if (!mobileListControls || !mobileCalendarControls) return;
        if (!isMobileView()) {
            mobileListControls.style.display = 'none';
            mobileCalendarControls.style.display = 'none';
            return;
        }
        if (showingCalendar) {
            mobileCalendarControls.style.display = '';
            mobileListControls.style.display = 'none';
            if (mobileMonthLabel && monthLabel) {
                mobileMonthLabel.textContent = monthLabel.textContent;
            }
        } else {
            mobileListControls.style.display = '';
            mobileCalendarControls.style.display = 'none';
        }
    }
    
    if (prevMonthBtn) prevMonthBtn.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    };
    if (nextMonthBtn) nextMonthBtn.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    };
    if (toCalendarBtn) toCalendarBtn.onclick = showCalendarView;
    if (toListBtn) toListBtn.onclick = showListView;
    if (mobileToCalendarBtn) mobileToCalendarBtn.onclick = showCalendarView;
    if (mobileToListBtn) mobileToListBtn.onclick = showListView;
    if (mobilePrevMonth) mobilePrevMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
        updateMobileControls();
    };
    if (mobileNextMonth) mobileNextMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
        updateMobileControls();
    };
    
    if (scheduleSearch && scheduleListHolder) {
        scheduleSearch.addEventListener('input', function() {
            const searchVal = this.value.trim().toLowerCase();
            const items = scheduleListHolder.querySelectorAll('.schedule-item');
            let shownCount = 0;
            if (!searchVal.length) {
                items.forEach(i => i.style.display = '');
                if (noResultMsg) noResultMsg.style.display = 'none';
                return;
            }
            items.forEach(item => {
                const task = item.getAttribute('data-task') || '';
                const loc = item.getAttribute('data-location') || '';
                const date = item.getAttribute('data-date') || '';
                const cat = item.getAttribute('data-category') || '';
                const stat = item.getAttribute('data-status') || '';
                const prio = item.getAttribute('data-priority') || '';
                if (task.includes(searchVal) || loc.includes(searchVal) || date.includes(searchVal) || 
                    cat.includes(searchVal) || stat.includes(searchVal) || prio.includes(searchVal)) {
                    item.style.display = '';
                    shownCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            if (noResultMsg) {
                noResultMsg.style.display = shownCount === 0 ? '' : 'none';
            }
        });
    }
    
    if (mobileScheduleSearch && scheduleSearch) {
        mobileScheduleSearch.addEventListener('input', e => {
            scheduleSearch.value = e.target.value;
            scheduleSearch.dispatchEvent(new Event('input'));
        });
    }
    
    window.addEventListener('resize', updateMobileControls);
    renderCalendar();
    updateMobileControls();
    
    function updateWeekdayLabels() {
        const desktopDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const shortDays = ['S','M','T','W','T','F','S'];
        const weekdayDivs = document.querySelectorAll('.calendar-weekdays div');
        if (!weekdayDivs.length) return;
        if (window.innerWidth <= 768) {
            weekdayDivs.forEach((el, i) => el.textContent = shortDays[i]);
        } else {
            weekdayDivs.forEach((el, i) => el.textContent = desktopDays[i]);
        }
    }
    
    window.addEventListener('load', updateWeekdayLabels);
    window.addEventListener('resize', updateWeekdayLabels);
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

