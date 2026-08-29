<?php
/**
 * Energy Efficiency module — LGU Energy system integration.
 *
 * Tabs: Meter Readings (record + push monthly manual readings), Recommendations
 * (engineer-approved advice pulled from the Energy system), Facility Mapping
 * (link CPRF facilities to Energy-system facilities).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'energy')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/energy_helper.php';

$pdo = db();
$pageTitle = 'Energy Efficiency | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';

$canCreate = frs_can_create($role, 'energy');
$canUpdate = frs_can_update($role, 'energy');
$canDelete = frs_can_delete($role, 'energy');
$syncEnabled = energy_api_enabled();

$message = '';
$messageType = '';
$hasTables = frs_energy_tables_exist($pdo);

$tab = (string)($_GET['tab'] ?? 'recommendations');
if (!in_array($tab, ['recommendations', 'mapping', 'profiles', 'reports'], true)) {
    $tab = 'recommendations';
}

$reportTab = (string)($_GET['report_tab'] ?? 'overview');
if (!in_array($reportTab, ['overview', 'consumption', 'cost', 'savings'], true)) {
    $reportTab = 'overview';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $hasTables) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } elseif ($_POST['action'] === 'save_mapping' && $canUpdate) {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $pair = trim((string)($_POST['energy_facility'] ?? '')); // "id|name"
        $sep = strpos($pair, '|');
        if ($facilityId <= 0 || $sep === false) {
            $message = 'Please choose an Energy-system facility.';
            $messageType = 'error';
        } else {
            $energyFacilityId = (int)substr($pair, 0, $sep);
            if ($energyFacilityId <= 0) {
                $message = 'Please choose an Energy-system facility.';
                $messageType = 'error';
            } else {
                $energyFacilityName = substr($pair, $sep + 1);
                frs_energy_save_mapping($pdo, $facilityId, $energyFacilityId, $energyFacilityName, (int)($_SESSION['user_id'] ?? 0) ?: null);
                $message = 'Facility mapping saved.';
                $messageType = 'success';
            }
        }
        $tab = 'mapping';
    } elseif ($_POST['action'] === 'update_recommendation' && $canUpdate) {
        $tab = 'recommendations';
        try {
            if (!$syncEnabled) {
                throw new RuntimeException('Sync is disabled. Enable Energy sync before updating progress.');
            }
            $result = frs_energy_push_recommendation_progress(
                $pdo,
                (int)($_POST['recommendation_id'] ?? 0),
                $_POST,
                (int)($_SESSION['user_id'] ?? 0) ?: null
            );
            if (!$result['success']) {
                throw new RuntimeException((string)$result['error']);
            }
            $message = 'Implementation progress saved and synced to the Energy system.';
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to update recommendation: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$facilities = $pdo->query("SELECT id, name, status FROM facilities WHERE status != 'deleted' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$mapping = $hasTables ? frs_energy_get_mapping($pdo) : [];
$syncState = $hasTables ? frs_energy_load_sync_state($pdo) : ['last_pull_at' => null, 'last_push_at' => null, 'last_summary' => null];
$pendingRecoCount = $hasTables ? (int)$pdo->query(
    "SELECT COUNT(*) FROM energy_recommendations_cache WHERE status = 'approved' AND implementation_status NOT IN ('implemented', 'verified')"
)->fetchColumn() : 0;

$latestReadings = [];
$pendingCount = 0;
$hasSyncedReadings = false;
$approvedRecommendationPeriods = [];
if ($hasTables) {
    $rows = $pdo->query('
        SELECT r.*, f.name AS facility_name, u.name AS recorded_by_name
        FROM energy_meter_readings r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN users u ON u.id = r.recorded_by
        ORDER BY r.year DESC, r.month DESC, r.id DESC
        LIMIT 200
    ')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $fid = (int)$row['facility_id'];
        if (!isset($latestReadings[$fid])) {
            $latestReadings[$fid] = $row;
        }
        if (in_array($row['sync_status'], ['pending', 'failed'], true)) {
            $pendingCount++;
        }
        if ($row['sync_status'] === 'synced') {
            $hasSyncedReadings = true;
        }
    }

    $approvedRows = $pdo->query("
        SELECT facility_id, year, month
        FROM energy_recommendations_cache
        WHERE status = 'approved'
          AND facility_id IS NOT NULL
        GROUP BY facility_id, year, month
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($approvedRows as $approvedRow) {
        $key = (int)$approvedRow['facility_id'] . '-' . (int)$approvedRow['year'] . '-' . (int)$approvedRow['month'];
        $approvedRecommendationPeriods[$key] = true;
    }
}

$recommendations = [];
$periodRaw = '';
if ($hasTables && $tab === 'recommendations') {
    $filterFacility = (int)($_GET['facility_id'] ?? 0);
    $filterYear = 0;
    $filterMonth = 0;
    $periodRaw = trim((string)($_GET['period'] ?? ''));
    if ($periodRaw !== '') {
        $parts = explode('-', $periodRaw);
        if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && (int)$parts[1] >= 1 && (int)$parts[1] <= 12) {
            $filterYear = (int)$parts[0];
            $filterMonth = (int)$parts[1];
        }
    }
    $sql = '
        SELECT c.*, f.name AS facility_name
        FROM energy_recommendations_cache c
        INNER JOIN energy_facility_map m
            ON m.facility_id = c.facility_id
           AND m.energy_facility_id = c.energy_facility_id
        INNER JOIN facilities f ON f.id = c.facility_id
        WHERE c.status = \'approved\'
        ' . ($filterFacility > 0 ? 'AND c.facility_id = :fid' : '') . '
        ' . ($filterYear > 0 ? 'AND c.year = :fy AND c.month = :fm' : '') . '
        ORDER BY c.year DESC, c.month DESC, c.id DESC
        LIMIT 100
    ';
    $stmt = $pdo->prepare($sql);
    if ($filterFacility > 0) {
        $stmt->bindValue('fid', $filterFacility, PDO::PARAM_INT);
    }
    if ($filterYear > 0) {
        $stmt->bindValue('fy', $filterYear, PDO::PARAM_INT);
        $stmt->bindValue('fm', $filterMonth, PDO::PARAM_INT);
    }
    $stmt->execute();
    $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$energyFacilities = [];
$energyFacilitiesError = null;
if ($tab === 'mapping' && $canUpdate) {
    $result = fetchEnergyFacilities();
    $energyFacilities = $result['data'];
    $energyFacilitiesError = $result['error'];
}

$profiles = [];
if ($hasTables && $tab === 'profiles') {
    $profiles = $pdo->query('
        SELECT f.id AS facility_id, f.name AS facility_name,
               p.main_meter_name, p.electric_meter_no, p.utility_provider, p.contract_account_no, p.main_energy_source,
               p.backup_power, p.transformer_capacity, p.number_of_meters, p.baseline_kwh,
               p.engineer_approved, p.baseline_locked, p.baseline_source, p.energy_updated_at
        FROM facilities f
        JOIN energy_facility_map m ON m.facility_id = f.id
        LEFT JOIN energy_profile_cache p ON p.facility_id = f.id
        WHERE f.status != "deleted"
        ORDER BY f.name
    ')->fetchAll(PDO::FETCH_ASSOC);
}

// Energy report data is kept local so reports remain available even when the
// Energy API is temporarily offline.
$reportFacility = max(0, (int)($_GET['report_facility'] ?? 0));
$reportYearRaw = trim((string)($_GET['report_year'] ?? 'all'));
$reportYear = ctype_digit($reportYearRaw) ? (int)$reportYearRaw : null;
$reportYears = [];
$reportSummary = [
    'reading_count' => 0,
    'facility_count' => 0,
    'consumption_kwh' => 0.0,
    'energy_cost' => 0.0,
    'average_kwh' => 0.0,
];
$reportMonthly = [];
$reportByFacility = [];
$reportSavings = [];
$reportSavingsSummary = [
    'recommendation_count' => 0,
    'expected_savings_kwh' => 0.0,
    'actual_savings_kwh' => 0.0,
    'implemented_count' => 0,
    'verified_count' => 0,
];

if ($hasTables && $tab === 'reports') {
    $reportYears = array_map(
        'intval',
        $pdo->query('SELECT DISTINCT year FROM energy_meter_readings ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN)
    );

    $readingWhere = [];
    $readingParams = [];
    if ($reportFacility > 0) {
        $readingWhere[] = 'r.facility_id = :facility_id';
        $readingParams['facility_id'] = $reportFacility;
    }
    if ($reportYear !== null) {
        $readingWhere[] = 'r.year = :report_year';
        $readingParams['report_year'] = $reportYear;
    }
    $readingWhereSql = $readingWhere === [] ? '' : ' WHERE ' . implode(' AND ', $readingWhere);

    $summaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS reading_count, COUNT(DISTINCT r.facility_id) AS facility_count,
                COALESCE(SUM(r.consumption_kwh), 0) AS consumption_kwh,
                COALESCE(SUM(r.consumption_kwh * r.rate_per_kwh), 0) AS energy_cost,
                COALESCE(AVG(r.consumption_kwh), 0) AS average_kwh
         FROM energy_meter_readings r' . $readingWhereSql
    );
    $summaryStmt->execute($readingParams);
    $reportSummary = array_merge($reportSummary, $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $monthlyStmt = $pdo->prepare(
        'SELECT r.year, r.month, COUNT(*) AS reading_count,
                SUM(r.consumption_kwh) AS consumption_kwh,
                SUM(r.consumption_kwh * r.rate_per_kwh) AS energy_cost
         FROM energy_meter_readings r' . $readingWhereSql . '
         GROUP BY r.year, r.month ORDER BY r.year, r.month'
    );
    $monthlyStmt->execute($readingParams);
    $reportMonthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

    $facilityStmt = $pdo->prepare(
        'SELECT f.id AS facility_id, f.name AS facility_name, COUNT(*) AS reading_count,
                SUM(r.consumption_kwh) AS consumption_kwh,
                SUM(r.consumption_kwh * r.rate_per_kwh) AS energy_cost,
                AVG(r.rate_per_kwh) AS average_rate
         FROM energy_meter_readings r
         JOIN facilities f ON f.id = r.facility_id' . $readingWhereSql . '
         GROUP BY f.id, f.name ORDER BY consumption_kwh DESC, f.name'
    );
    $facilityStmt->execute($readingParams);
    $reportByFacility = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);

    $savingsWhere = ["c.status = 'approved'"];
    $savingsParams = [];
    if ($reportFacility > 0) {
        $savingsWhere[] = 'c.facility_id = :facility_id';
        $savingsParams['facility_id'] = $reportFacility;
    }
    if ($reportYear !== null) {
        $savingsWhere[] = 'c.year = :report_year';
        $savingsParams['report_year'] = $reportYear;
    }
    $savingsWhereSql = ' WHERE ' . implode(' AND ', $savingsWhere);

    $savingsSummaryStmt = $pdo->prepare(
        "SELECT COUNT(*) AS recommendation_count,
                COALESCE(SUM(c.expected_savings_kwh), 0) AS expected_savings_kwh,
                COALESCE(SUM(c.actual_savings_kwh), 0) AS actual_savings_kwh,
                SUM(CASE WHEN c.implementation_status IN ('implemented', 'verified') THEN 1 ELSE 0 END) AS implemented_count,
                SUM(CASE WHEN c.implementation_status = 'verified' THEN 1 ELSE 0 END) AS verified_count
         FROM energy_recommendations_cache c" . $savingsWhereSql
    );
    $savingsSummaryStmt->execute($savingsParams);
    $reportSavingsSummary = array_merge($reportSavingsSummary, $savingsSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $savingsStmt = $pdo->prepare(
        'SELECT c.year, c.month, c.implementation_status,
                COALESCE(SUM(c.expected_savings_kwh), 0) AS expected_savings_kwh,
                COALESCE(SUM(c.actual_savings_kwh), 0) AS actual_savings_kwh,
                COUNT(*) AS recommendation_count
         FROM energy_recommendations_cache c' . $savingsWhereSql . '
         GROUP BY c.year, c.month, c.implementation_status
         ORDER BY c.year, c.month, c.implementation_status'
    );
    $savingsStmt->execute($savingsParams);
    $reportSavings = $savingsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$tabUrl = static fn (string $t): string => base_path() . '/dashboard/energy-efficiency?tab=' . $t;
$reportUrl = static function (string $r) use ($reportFacility, $reportYearRaw): string {
    return base_path() . '/dashboard/energy-efficiency?' . http_build_query([
        'tab' => 'reports',
        'report_tab' => $r,
        'report_facility' => $reportFacility,
        'report_year' => $reportYearRaw,
    ]);
};
$reportMonthlyLabels = [];
$reportConsumptionData = [];
$reportCostData = [];
foreach ($reportMonthly as $row) {
    $reportMonthlyLabels[] = ($monthNames[(int)$row['month']] ?? (string)$row['month']) . ' ' . (int)$row['year'];
    $reportConsumptionData[] = round((float)$row['consumption_kwh'], 2);
    $reportCostData[] = round((float)$row['energy_cost'], 2);
}
$reportMonthlyPeriodCount = count($reportMonthly);
$reportSavingsByPeriod = [];
foreach ($reportSavings as $row) {
    $key = sprintf('%04d-%02d', (int)$row['year'], (int)$row['month']);
    if (!isset($reportSavingsByPeriod[$key])) {
        $reportSavingsByPeriod[$key] = ['expected' => 0.0, 'actual' => 0.0];
    }
    $reportSavingsByPeriod[$key]['expected'] += (float)$row['expected_savings_kwh'];
    $reportSavingsByPeriod[$key]['actual'] += (float)$row['actual_savings_kwh'];
}
ksort($reportSavingsByPeriod);
$reportSavingsLabels = [];
$reportExpectedSavingsData = [];
$reportActualSavingsData = [];
foreach ($reportSavingsByPeriod as $period => $values) {
    [$year, $month] = array_map('intval', explode('-', $period));
    $reportSavingsLabels[] = ($monthNames[$month] ?? (string)$month) . ' ' . $year;
    $reportExpectedSavingsData[] = round($values['expected'], 2);
    $reportActualSavingsData[] = round($values['actual'], 2);
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Energy Efficiency</span>
    </div>
    <?= frs_page_title('Energy Efficiency (LGU Energy)', 'Record monthly electricity meter readings per facility, push them to the LGU Energy system, and review engineer-approved energy-saving recommendations.'); ?>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-building text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Facilities tracked</p>
            <p class="text-lg font-bold text-slate-900"><?= count($mapping); ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-lightbulb text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Recommendations pending</p>
            <p class="text-lg font-bold text-slate-900"><?= (int)$pendingRecoCount; ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-arrow-repeat text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Last pulled</p>
            <p class="text-lg font-bold text-slate-900"><?= !empty($syncState['last_pull_at']) ? htmlspecialchars(date('M j, g:i A', strtotime($syncState['last_pull_at']))) : 'Never'; ?></p>
        </div>
    </div>
</div>

<style>
html[data-theme="dark"] input[type="month"],
html[data-theme="dark"] input[type="number"] {
    background: #0f172a;
    border-color: #475569 !important;
    color: #e2e8f0;
}
</style>

<?php if ($message): ?>
    <div class="message <?= htmlspecialchars($messageType); ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!$hasTables): ?>
    <section class="booking-card">
        <h2>Setup required</h2>
        <p style="color:#8b95b5;">Run <code>database/migration_add_energy_integration.sql</code> to create the energy integration tables.</p>
    </section>
<?php else: ?>

<nav class="booking-hub-tabs" aria-label="Energy sections">
    <a class="booking-hub-tab <?= $tab === 'recommendations' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('recommendations')); ?>">Recommendations</a>
    <a class="booking-hub-tab <?= $tab === 'profiles' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('profiles')); ?>">Facility Profiles</a>
    <a class="booking-hub-tab <?= $tab === 'reports' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('reports')); ?>">Reports</a>
    <?php if ($canUpdate): ?>
        <a class="booking-hub-tab <?= $tab === 'mapping' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a>
    <?php endif; ?>
</nav>

<?php if ($tab === 'recommendations'): ?>
    <section class="booking-card">
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:center; justify-content:space-between; margin-bottom:1rem;">
            <h2 style="margin:0;">Energy-Saving Recommendations</h2>
            <form method="GET" action="<?= htmlspecialchars(base_path() . '/dashboard/energy-efficiency'); ?>">
                <input type="hidden" name="tab" value="recommendations">
                <input type="month" name="period" value="<?= htmlspecialchars($periodRaw); ?>" onchange="this.form.submit()" style="padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                <select name="facility_id" onchange="this.form.submit()" style="padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <option value="0">All facilities</option>
                    <?php foreach ($facilities as $f): ?>
                        <option value="<?= (int)$f['id']; ?>" <?= ((int)($_GET['facility_id'] ?? 0)) === (int)$f['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($f['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <p style="color:#8b95b5;">Engineer-approved advice from the LGU Energy system. Last pulled: <?= htmlspecialchars($syncState['last_pull_at'] ?? 'never'); ?>.</p>
        <?php if ($recommendations === []): ?>
            <?php if ($hasSyncedReadings): ?>
                <div style="max-width:620px; margin:1.25rem auto 0; padding:1.25rem 1.5rem; border:1px solid #f0d9a8; border-radius:12px; background:#fffaf0; text-align:center;">
                    <div style="font-weight:700; color:#9a5b00; margin-bottom:0.35rem;">Waiting for Energy Admin approval</div>
                    <p style="color:#7b6848; margin:0;">Your monthly record was submitted successfully. Approved recommendations will appear here automatically after you run Sync Now.</p>
                </div>
            <?php else: ?>
                <p style="color:#8b95b5; text-align:center; padding:2rem;">No recommendations yet. Submit a monthly utility reading on the <a href="<?= htmlspecialchars(base_path() . '/dashboard/utilities-integration'); ?>">UMAN Integration page</a> first.</p>
            <?php endif; ?>
        <?php else: ?>
            <?php foreach ($recommendations as $reco): ?>
                <article style="border:1px solid #edf2f7; border-radius:8px; padding:1rem; margin-bottom:0.9rem;">
                    <div style="display:flex; flex-wrap:wrap; gap:0.5rem 1rem; align-items:baseline; justify-content:space-between;">
                        <strong><?= htmlspecialchars((string)($reco['facility_name'] ?? ('Energy facility #' . (int)$reco['energy_facility_id'] . ' (unmapped)'))); ?></strong>
                        <span style="color:#8b95b5;">
                            <?= htmlspecialchars(($monthNames[(int)$reco['month']] ?? $reco['month']) . ' ' . $reco['year']); ?>
                            · <span class="status-badge active"><?= htmlspecialchars(ucfirst((string)$reco['status'])); ?></span>
                        </span>
                    </div>
                    <p style="margin:0.6rem 0 0.3rem;"><?= nl2br(htmlspecialchars((string)$reco['generated_message'])); ?></p>
                    <?php if (!empty($reco['engineer_recommendation'])): ?>
                        <p style="margin:0.3rem 0; color:#0d7a43;"><strong>Engineer:</strong> <?= nl2br(htmlspecialchars((string)$reco['engineer_recommendation'])); ?></p>
                    <?php endif; ?>
                    <small style="color:#8b95b5;">
                        <?php if ($reco['expected_savings_kwh'] !== null): ?>Expected savings: <?= number_format((float)$reco['expected_savings_kwh'], 2); ?> kWh · <?php endif; ?>
                        <?php if (!empty($reco['target_date'])): ?>Target: <?= htmlspecialchars((string)$reco['target_date']); ?> · <?php endif; ?>
                        Fetched: <?= htmlspecialchars((string)$reco['fetched_at']); ?>
                    </small>
                    <?php
                    $implementationStatus = (string)($reco['implementation_status'] ?? 'pending');
                    $implementationLabels = [
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'implemented' => 'Implemented',
                        'verified' => 'Verified by Energy',
                    ];
                    ?>
                    <div style="margin-top:0.9rem; padding-top:0.9rem; border-top:1px solid #edf2f7;">
                        <strong>Implementation: <?= htmlspecialchars($implementationLabels[$implementationStatus] ?? ucfirst($implementationStatus)); ?></strong>
                        <?php if ($reco['actual_savings_kwh'] !== null): ?>
                            <span style="color:#0d7a43;"> &middot; Actual savings: <?= number_format((float)$reco['actual_savings_kwh'], 2); ?> kWh</span>
                        <?php endif; ?>
                        <?php if (!empty($reco['implementation_notes'])): ?>
                            <p style="margin:0.45rem 0; color:#56627a;"><?= nl2br(htmlspecialchars((string)$reco['implementation_notes'])); ?></p>
                        <?php endif; ?>

                        <?php if ($canUpdate && $implementationStatus !== 'verified'): ?>
                            <form method="POST" action="<?= htmlspecialchars($tabUrl('recommendations')); ?>" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:0.65rem; align-items:end; margin-top:0.75rem;">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="update_recommendation">
                                <input type="hidden" name="recommendation_id" value="<?= (int)$reco['id']; ?>">
                                <label>
                                    Progress Status
                                    <select name="implementation_status" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                                        <?php foreach (['pending' => 'Pending', 'in_progress' => 'In Progress', 'implemented' => 'Implemented'] as $value => $label): ?>
                                            <option value="<?= $value; ?>" <?= $implementationStatus === $value ? 'selected' : ''; ?>><?= $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    Actual Savings (kWh)
                                    <input type="number" name="actual_savings_kwh" min="0" step="0.01" value="<?= $reco['actual_savings_kwh'] !== null ? htmlspecialchars((string)$reco['actual_savings_kwh']) : ''; ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                                </label>
                                <label>
                                    Implementation Notes
                                    <textarea name="implementation_notes" rows="2" maxlength="5000" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"><?= htmlspecialchars((string)($reco['implementation_notes'] ?? '')); ?></textarea>
                                </label>
                                <button type="submit" class="btn-primary" <?= $syncEnabled ? '' : 'disabled'; ?>>Save Progress</button>
                            </form>
                            <small style="display:block; margin-top:0.45rem; color:#8b95b5;">Facilities records the action here. Final verification stays with the Energy engineer.</small>
                        <?php elseif ($implementationStatus === 'verified'): ?>
                            <small style="display:block; margin-top:0.45rem; color:#0d7a43;">Final verification was completed in the Energy system.</small>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'reports'): ?>
    <section class="booking-card energy-reports-shell">
        <div class="energy-reports-header">
            <div>
                <h2 style="margin:0 0 0.3rem;">Energy Reports</h2>
                <p style="color:#8b95b5; margin:0;">Analyze recorded electricity use, estimated cost, and recommendation savings.</p>
            </div>
            <form method="GET" action="<?= htmlspecialchars(base_path() . '/dashboard/energy-efficiency'); ?>" class="energy-report-filter">
                <input type="hidden" name="tab" value="reports">
                <input type="hidden" name="report_tab" value="<?= htmlspecialchars($reportTab); ?>">
                <label>
                    <span>Facility</span>
                    <select name="report_facility">
                        <option value="0">All facilities</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= (int)$f['id']; ?>" <?= $reportFacility === (int)$f['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string)$f['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Year</span>
                    <select name="report_year">
                        <option value="all">All years</option>
                        <?php foreach ($reportYears as $year): ?>
                            <option value="<?= $year; ?>" <?= $reportYear === $year ? 'selected' : ''; ?>><?= $year; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn-primary">Apply Filter</button>
            </form>
        </div>

        <nav class="energy-report-tabs" aria-label="Energy report views">
            <?php foreach (['overview' => 'Overview', 'consumption' => 'Consumption', 'cost' => 'Cost', 'savings' => 'Savings'] as $key => $label): ?>
                <a href="<?= htmlspecialchars($reportUrl($key)); ?>" class="<?= $reportTab === $key ? 'is-active' : ''; ?>" <?= $reportTab === $key ? 'aria-current="page"' : ''; ?>><?= $label; ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($reportTab !== 'savings' && (int)$reportSummary['reading_count'] === 0): ?>
            <div class="energy-report-empty">
                <strong>No meter readings for this filter.</strong>
                <span>Add a monthly reading or choose a different facility and year.</span>
            </div>
        <?php elseif ($reportTab === 'overview'): ?>
            <div class="energy-report-kpis">
                <article><span>Total Consumption</span><strong><?= number_format((float)$reportSummary['consumption_kwh'], 2); ?> kWh</strong><small><?= number_format((int)$reportSummary['reading_count']); ?> monthly reading(s)</small></article>
                <article><span>Estimated Energy Cost</span><strong>&#8369;<?= number_format((float)$reportSummary['energy_cost'], 2); ?></strong><small>Consumption &times; recorded rate</small></article>
                <article><span>Average Consumption</span><strong><?= number_format((float)$reportSummary['average_kwh'], 2); ?> kWh</strong><small>Per submitted reading</small></article>
                <article><span>Facilities Covered</span><strong><?= number_format((int)$reportSummary['facility_count']); ?></strong><small>With readings in this period</small></article>
            </div>
            <div class="reports-grid energy-report-charts">
                <article class="report-card">
                    <h3>Consumption Trend</h3>
                    <p>Monthly electricity consumption in kWh.</p>
                    <canvas id="energy-consumption-chart" height="220"></canvas>
                </article>
                <article class="report-card">
                    <h3>Cost Trend</h3>
                    <p>Estimated monthly cost using each reading's recorded tariff.</p>
                    <canvas id="energy-cost-chart" height="220"></canvas>
                </article>
            </div>
            <div class="energy-report-table-wrap">
                <h3>Facility Summary</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Facility</th><th>Readings</th><th>Consumption</th><th>Estimated Cost</th><th>Avg. Rate</th></tr></thead>
                        <tbody>
                            <?php foreach ($reportByFacility as $row): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string)$row['facility_name']); ?></strong></td>
                                    <td><?= number_format((int)$row['reading_count']); ?></td>
                                    <td><?= number_format((float)$row['consumption_kwh'], 2); ?> kWh</td>
                                    <td>&#8369;<?= number_format((float)$row['energy_cost'], 2); ?></td>
                                    <td>&#8369;<?= number_format((float)$row['average_rate'], 2); ?>/kWh</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($reportTab === 'consumption'): ?>
            <div class="energy-report-kpis energy-report-kpis-compact">
                <article><span>Total Consumption</span><strong><?= number_format((float)$reportSummary['consumption_kwh'], 2); ?> kWh</strong></article>
                <article><span>Monthly Average</span><strong><?= $reportMonthlyPeriodCount > 0 ? number_format((float)$reportSummary['consumption_kwh'] / $reportMonthlyPeriodCount, 2) : '0.00'; ?> kWh</strong></article>
                <article><span>Readings</span><strong><?= number_format((int)$reportSummary['reading_count']); ?></strong></article>
            </div>
            <article class="report-card energy-report-wide-chart">
                <h3>Monthly Consumption</h3>
                <canvas id="energy-consumption-chart" height="115"></canvas>
            </article>
            <div class="energy-report-table-wrap">
                <h3>Monthly Breakdown</h3>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Period</th><th>Readings</th><th>Consumption</th><th>Share of Total</th></tr></thead>
                    <tbody><?php foreach (array_reverse($reportMonthly) as $row): ?>
                        <?php $share = (float)$reportSummary['consumption_kwh'] > 0 ? ((float)$row['consumption_kwh'] / (float)$reportSummary['consumption_kwh']) * 100 : 0; ?>
                        <tr><td><strong><?= htmlspecialchars(($monthNames[(int)$row['month']] ?? (string)$row['month']) . ' ' . (int)$row['year']); ?></strong></td><td><?= number_format((int)$row['reading_count']); ?></td><td><?= number_format((float)$row['consumption_kwh'], 2); ?> kWh</td><td><?= number_format($share, 1); ?>%</td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>
        <?php elseif ($reportTab === 'cost'): ?>
            <div class="energy-report-kpis energy-report-kpis-compact">
                <article><span>Estimated Total Cost</span><strong>&#8369;<?= number_format((float)$reportSummary['energy_cost'], 2); ?></strong></article>
                <article><span>Average Cost / Reading</span><strong>&#8369;<?= (int)$reportSummary['reading_count'] > 0 ? number_format((float)$reportSummary['energy_cost'] / (int)$reportSummary['reading_count'], 2) : '0.00'; ?></strong></article>
                <article><span>Consumption</span><strong><?= number_format((float)$reportSummary['consumption_kwh'], 2); ?> kWh</strong></article>
            </div>
            <article class="report-card energy-report-wide-chart">
                <h3>Monthly Estimated Cost</h3>
                <canvas id="energy-cost-chart" height="115"></canvas>
            </article>
            <div class="energy-report-table-wrap">
                <h3>Cost by Facility</h3>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Facility</th><th>Consumption</th><th>Average Rate</th><th>Estimated Cost</th><th>Share of Total</th></tr></thead>
                    <tbody><?php foreach ($reportByFacility as $row): ?>
                        <?php $costShare = (float)$reportSummary['energy_cost'] > 0 ? ((float)$row['energy_cost'] / (float)$reportSummary['energy_cost']) * 100 : 0; ?>
                        <tr><td><strong><?= htmlspecialchars((string)$row['facility_name']); ?></strong></td><td><?= number_format((float)$row['consumption_kwh'], 2); ?> kWh</td><td>&#8369;<?= number_format((float)$row['average_rate'], 2); ?>/kWh</td><td>&#8369;<?= number_format((float)$row['energy_cost'], 2); ?></td><td><?= number_format($costShare, 1); ?>%</td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>
        <?php else: ?>
            <?php
            $recommendationCount = (int)$reportSavingsSummary['recommendation_count'];
            $implementationRate = $recommendationCount > 0 ? ((int)$reportSavingsSummary['implemented_count'] / $recommendationCount) * 100 : 0;
            ?>
            <?php if ($recommendationCount === 0): ?>
                <div class="energy-report-empty"><strong>No approved recommendations for this filter.</strong><span>Run Sync Now after the Energy Admin approves recommendations.</span></div>
            <?php else: ?>
                <div class="energy-report-kpis">
                    <article><span>Expected Savings</span><strong><?= number_format((float)$reportSavingsSummary['expected_savings_kwh'], 2); ?> kWh</strong><small>Engineer-approved target</small></article>
                    <article><span>Actual Savings</span><strong><?= number_format((float)$reportSavingsSummary['actual_savings_kwh'], 2); ?> kWh</strong><small>Reported implementation result</small></article>
                    <article><span>Implementation Rate</span><strong><?= number_format($implementationRate, 1); ?>%</strong><small><?= number_format((int)$reportSavingsSummary['implemented_count']); ?> of <?= number_format($recommendationCount); ?> implemented</small></article>
                    <article><span>Verified</span><strong><?= number_format((int)$reportSavingsSummary['verified_count']); ?></strong><small>Confirmed by Energy</small></article>
                </div>
                <article class="report-card energy-report-wide-chart">
                    <h3>Expected vs. Actual Savings</h3>
                    <canvas id="energy-savings-chart" height="115"></canvas>
                </article>
                <div class="energy-report-table-wrap">
                    <h3>Savings Breakdown</h3>
                    <div class="table-responsive"><table class="table">
                        <thead><tr><th>Period</th><th>Status</th><th>Recommendations</th><th>Expected Savings</th><th>Actual Savings</th></tr></thead>
                        <tbody><?php foreach (array_reverse($reportSavings) as $row): ?>
                            <tr><td><strong><?= htmlspecialchars(($monthNames[(int)$row['month']] ?? (string)$row['month']) . ' ' . (int)$row['year']); ?></strong></td><td><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$row['implementation_status']))); ?></td><td><?= number_format((int)$row['recommendation_count']); ?></td><td><?= number_format((float)$row['expected_savings_kwh'], 2); ?> kWh</td><td><?= number_format((float)$row['actual_savings_kwh'], 2); ?> kWh</td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <style>
        .energy-reports-header { display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:1rem; }
        .energy-report-filter { display:flex; flex-wrap:wrap; align-items:flex-end; gap:0.65rem; }
        .energy-report-filter label { display:grid; gap:0.25rem; color:#5b6888; font-size:0.82rem; font-weight:600; }
        .energy-report-filter select { min-width:150px; padding:0.5rem; border:1px solid #e0e6ed; border-radius:7px; background:var(--bg-primary, #fff); color:inherit; }
        .energy-report-tabs { display:flex; gap:0.4rem; margin:1.25rem 0; padding-bottom:0.7rem; border-bottom:1px solid #e8ecf4; overflow-x:auto; }
        .energy-report-tabs a { padding:0.48rem 0.9rem; border-radius:7px; color:#5b6888; text-decoration:none; font-weight:600; white-space:nowrap; }
        .energy-report-tabs a:hover, .energy-report-tabs a.is-active { color:#fff; background:var(--gov-blue, #1f5fae); }
        .energy-report-kpis { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:0.85rem; margin-bottom:1rem; }
        .energy-report-kpis-compact { grid-template-columns:repeat(3, minmax(0, 1fr)); }
        .energy-report-kpis article { display:grid; gap:0.3rem; padding:1rem; border:1px solid #e8ecf4; border-radius:10px; background:var(--bg-primary, #fff); }
        .energy-report-kpis span, .energy-report-kpis small { color:#7b86a2; }
        .energy-report-kpis strong { color:var(--gov-blue-dark, #173f73); font-size:1.35rem; }
        .energy-report-charts { margin:0 0 1rem; }
        .energy-report-charts .report-card, .energy-report-wide-chart { padding:1rem; }
        .energy-report-charts h3, .energy-report-wide-chart h3, .energy-report-table-wrap h3 { margin:0 0 0.35rem; }
        .energy-report-charts p { margin:0 0 0.8rem; color:#8b95b5; font-size:0.9rem; }
        .energy-report-wide-chart { margin-bottom:1rem; }
        .energy-report-table-wrap { margin-top:1rem; }
        .energy-report-empty { display:grid; gap:0.35rem; place-items:center; padding:2.5rem 1rem; color:#8b95b5; text-align:center; }
        .energy-report-empty strong { color:#56627a; font-size:1.05rem; }
        html[data-theme="dark"] .energy-report-tabs { border-color:var(--border-color, #475569); }
        html[data-theme="dark"] .energy-report-kpis article { border-color:var(--border-color, #475569); }
        html[data-theme="dark"] .energy-report-kpis strong { color:var(--text-primary, #f1f5f9); }
        @media (max-width:900px) { .energy-report-kpis { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        @media (max-width:600px) { .energy-report-kpis, .energy-report-kpis-compact { grid-template-columns:1fr; } .energy-report-filter, .energy-report-filter label, .energy-report-filter select, .energy-report-filter button { width:100%; } }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') return;
        const styles = getComputedStyle(document.documentElement);
        const textColor = styles.getPropertyValue('--text-primary').trim() || '#334155';
        const gridColor = styles.getPropertyValue('--border-color').trim() || '#e8ecf4';
        const labels = <?= json_encode($reportMonthlyLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const baseOptions = {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: textColor } } },
            scales: {
                x: { ticks: { color: textColor }, grid: { color: gridColor } },
                y: { beginAtZero: true, ticks: { color: textColor }, grid: { color: gridColor } }
            }
        };
        const consumptionCanvas = document.getElementById('energy-consumption-chart');
        if (consumptionCanvas) new Chart(consumptionCanvas, { type:'line', data:{ labels:labels, datasets:[{ label:'Consumption (kWh)', data:<?= json_encode($reportConsumptionData); ?>, borderColor:'#1f5fae', backgroundColor:'rgba(31,95,174,0.14)', fill:true, tension:0.25 }] }, options:baseOptions });
        const costCanvas = document.getElementById('energy-cost-chart');
        if (costCanvas) new Chart(costCanvas, { type:'bar', data:{ labels:labels, datasets:[{ label:'Estimated Cost (PHP)', data:<?= json_encode($reportCostData); ?>, backgroundColor:'#0d9f6e', borderRadius:5 }] }, options:baseOptions });
        const savingsCanvas = document.getElementById('energy-savings-chart');
        if (savingsCanvas) new Chart(savingsCanvas, { type:'bar', data:{ labels:<?= json_encode($reportSavingsLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, datasets:[{ label:'Expected (kWh)', data:<?= json_encode($reportExpectedSavingsData); ?>, backgroundColor:'#f4b740', borderRadius:5 }, { label:'Actual (kWh)', data:<?= json_encode($reportActualSavingsData); ?>, backgroundColor:'#0d9f6e', borderRadius:5 }] }, options:baseOptions });
    });
    </script>

<?php elseif ($tab === 'mapping' && $canUpdate): ?>
    <section class="booking-card">
        <h2>Facility Mapping</h2>
        <p style="color:#8b95b5;">Link each CPRF facility to its counterpart in the Energy system. Suggested matches are pre-selected — confirm or override, then save per row.</p>
        <?php if ($energyFacilitiesError !== null): ?>
            <p style="color:#b23030; padding:1rem; background:#fdecee; border-radius:8px;"><?= htmlspecialchars($energyFacilitiesError); ?></p>
        <?php elseif ($energyFacilities === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No facilities returned from the Energy system.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>CPRF Facility</th><th>Energy-System Facility</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($facilities as $f): ?>
                            <?php
                            $fid = (int)$f['id'];
                            $current = $mapping[$fid] ?? null;
                            $suggested = $current === null ? frs_energy_suggest_match((string)$f['name'], $energyFacilities) : null;
                            $selectedId = $current['energy_facility_id'] ?? ($suggested['id'] ?? 0);
                            ?>
                            <tr>
                                <td data-label="CPRF Facility"><?= htmlspecialchars($f['name']); ?></td>
                                <td data-label="Energy-System Facility">
                                    <form method="POST" action="<?= htmlspecialchars($tabUrl('mapping')); ?>" style="display:flex; gap:0.5rem; align-items:center;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="save_mapping">
                                        <input type="hidden" name="facility_id" value="<?= $fid; ?>">
                                        <select name="energy_facility" required style="padding:0.4rem; border:1px solid #e0e6ed; border-radius:6px; min-width:220px;">
                                            <option value="">— Select —</option>
                                            <?php foreach ($energyFacilities as $ef): ?>
                                                <?php $efId = (int)($ef['id'] ?? 0); $efName = (string)($ef['name'] ?? ''); ?>
                                                <option value="<?= $efId . '|' . htmlspecialchars($efName); ?>" <?= $efId === $selectedId ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($efName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-primary" style="padding:0.4rem 0.9rem;">Save</button>
                                    </form>
                                </td>
                                <td data-label="Status">
                                    <?php if ($current !== null): ?>
                                        <span class="status-badge active">Mapped</span>
                                    <?php elseif ($suggested !== null): ?>
                                        <span class="status-badge maintenance" title="Name match score: <?= (int)$suggested['score']; ?>">Suggested</span>
                                    <?php else: ?>
                                        <span class="status-badge offline">Unmapped</span>
                                    <?php endif; ?>
                                </td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'profiles'): ?>
    <section class="booking-card">
        <h2>Facility Energy Profiles</h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">
            Utility, meter, and baseline details configured for each facility by the LGU Energy team. This data is read-only here and refreshes automatically whenever a sync runs.
            <?php if ($canUpdate): ?>
                Only facilities mapped to the Energy system are listed below — see <a href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a> to map more.
            <?php else: ?>
                Only facilities mapped to the Energy system are listed below.
            <?php endif; ?>
        </p>
        <?php if ($profiles === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No facilities are mapped to the Energy system yet. Set up mapping first under Facility Mapping.</p>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:1rem;">
                <?php foreach ($profiles as $p): ?>
                    <?php $hasProfile = $p['main_meter_name'] !== null || $p['utility_provider'] !== null || $p['baseline_kwh'] !== null || $p['electric_meter_no'] !== null; ?>
                    <article class="booking-card" style="margin:0;">
                        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:baseline; justify-content:space-between; margin-bottom:0.6rem;">
                            <strong><?= htmlspecialchars((string)$p['facility_name']); ?></strong>
                            <?php if ($hasProfile): ?>
                                <span>
                                    <span class="status-badge <?= $p['engineer_approved'] ? 'approved' : 'pending'; ?>"><?= $p['engineer_approved'] ? 'Approved' : 'Pending Approval'; ?></span>
                                    <?php if ($p['baseline_locked']): ?>
                                        <span class="status-badge admin">Baseline Locked</span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$hasProfile): ?>
                            <p style="color:#8b95b5; margin:0;">No energy profile set yet — the Energy team hasn't configured this facility.</p>
                        <?php else: ?>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.4rem 1rem; font-size:0.9rem;">
                                <div style="grid-column:1 / -1;"><span style="color:#8b95b5;">Main Meter Name</span><br><strong><?= htmlspecialchars((string)($p['main_meter_name'] ?? '—')); ?></strong></div>
                                <div><span style="color:#8b95b5;">Utility Provider</span><br><?= htmlspecialchars((string)($p['utility_provider'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Contract Acct.</span><br><?= htmlspecialchars((string)($p['contract_account_no'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Electric Meter No.</span><br><?= htmlspecialchars((string)($p['electric_meter_no'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Main Energy Source</span><br><?= htmlspecialchars((string)($p['main_energy_source'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Backup Power</span><br><?= htmlspecialchars((string)($p['backup_power'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Transformer Capacity</span><br><?= htmlspecialchars((string)($p['transformer_capacity'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Number of Meters</span><br><?= htmlspecialchars((string)($p['number_of_meters'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Baseline kWh</span><br><?= $p['baseline_kwh'] !== null ? number_format((float)$p['baseline_kwh'], 2) : '—'; ?></div>
                                <div><span style="color:#8b95b5;">Baseline Source</span><br><?= htmlspecialchars((string)($p['baseline_source'] ?? '—')); ?></div>
                            </div>
                            <p style="color:#8b95b5; font-size:0.8rem; margin:0.75rem 0 0;">Last updated from Energy: <?= htmlspecialchars((string)($p['energy_updated_at'] ?? 'never')); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php endif; // hasTables ?>

<script>
(function () {
    'use strict';
    document.querySelectorAll('.ee-readings-card .table-responsive').forEach(function (el) {
        function sync() {
            el.classList.toggle('is-scrollable', el.scrollWidth > el.clientWidth + 1);
        }
        sync();
        window.addEventListener('resize', sync);
        el.addEventListener('scroll', function () {
            el.classList.toggle('is-scroll-end', el.scrollLeft + el.clientWidth >= el.scrollWidth - 1);
        });
    });

    document.querySelectorAll('.ee-missing-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sel = document.getElementById('energy-facility-select');
            if (!sel) return;
            sel.value = btn.getAttribute('data-facility-id');
            sel.dispatchEvent(new Event('change'));
            sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            sel.focus();
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
