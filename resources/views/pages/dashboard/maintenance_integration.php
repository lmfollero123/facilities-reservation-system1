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
require_once __DIR__ . '/../../../../config/predictive_maintenance.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';
require_once __DIR__ . '/../../../../config/integration_status.php';

$pdo = db();
$base = base_path();
$pageTitle = 'Maintenance Integration | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';
$canSubmit = frs_can_create($role, 'maintenance') || frs_can_update($role, 'maintenance');

$activeTab = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? 'schedules'));
if (!in_array($activeTab, ['schedules', 'insights'], true)) {
    $activeTab = 'schedules';
}

$filterBand = strtolower(trim((string)($_GET['band'] ?? 'all')));
if (!in_array($filterBand, ['all', 'high', 'medium', 'low'], true)) {
    $filterBand = 'all';
}

$predictiveRows = frs_compute_predictive_maintenance_rows($pdo);
$recentRequests = frs_fetch_recent_maintenance_requests($pdo, 10);
$highCount = count(array_filter($predictiveRows, static fn($r) => ($r['risk_band'] ?? '') === 'High'));
$mediumCount = count(array_filter($predictiveRows, static fn($r) => ($r['risk_band'] ?? '') === 'Medium'));
$actionableCount = count(array_filter($predictiveRows, static fn($r) => !empty($r['show_request_action'])));
$pendingSent = count(array_filter($recentRequests, static fn($r) => in_array($r['status'] ?? '', ['pending', 'sent', 'acknowledged'], true)));
$displayRows = $predictiveRows;
if ($filterBand !== 'all') {
    $displayRows = array_values(array_filter(
        $predictiveRows,
        static fn($r) => strtolower((string)($r['risk_band'] ?? '')) === $filterBand
    ));
}

$insightsSearch = trim((string)($_GET['iq'] ?? ''));
if ($insightsSearch !== '') {
    $needle = strtolower($insightsSearch);
    $displayRows = array_values(array_filter($displayRows, function ($r) use ($needle) {
        $haystack = strtolower(($r['facility_name'] ?? '') . ' ' . ($r['location'] ?? ''));
        return str_contains($haystack, $needle);
    }));
}

if ($activeTab === 'insights' && ($_GET['export'] ?? '') === 'csv') {
    frs_export_maintenance_insights_csv($displayRows);
    exit;
}

// Pagination for the insights facility grid - capped at 6 per page so the
// grid stays scannable instead of dumping every facility on one screen.
$insightsPerPage = 6;
$insightsTotal = count($displayRows);
$insightsTotalPages = max(1, (int)ceil($insightsTotal / $insightsPerPage));
$insightsPage = max(1, min($insightsTotalPages, (int)($_GET['ipage'] ?? 1)));
$insightsOffset = ($insightsPage - 1) * $insightsPerPage;
$displayRowsPaged = array_slice($displayRows, $insightsOffset, $insightsPerPage);

// Action feedback
$success = '';
$error = '';
$isAjaxRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'FRSAjaxForm';

// Manual "Sync Now" - runs the same fetch+sync the cron does
// (scripts/sync_cimm_maintenance.php), on demand. Page loads otherwise only
// read the cache below - no live CIMM API call on every view.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_now'])) {
    if (!frs_csrf_ok()) {
        $error = 'Your session expired or the form is invalid. Please refresh and try again.';
    } elseif (!$canSubmit) {
        $error = 'You do not have permission to sync CIMM maintenance data.';
    } else {
        $syncResult = frs_cimm_run_sync($pdo);
        if ($syncResult['success']) {
            $s = $syncResult['summary'];
            $success = 'CIMM sync complete: ' . (int)($s['matched_schedule_count'] ?? 0) . ' schedule(s) matched, '
                . (int)($s['updated_to_maintenance'] ?? 0) . ' facility(ies) set to maintenance, '
                . (int)($s['updated_to_available'] ?? 0) . ' returned to available.';
        } else {
            $error = 'CIMM sync failed: ' . (string)($syncResult['error'] ?? 'Unknown error');
        }
    }
    if ($isAjaxRequest && ($success !== '' || $error !== '')) {
        header('X-FRS-Toast: ' . rawurlencode(json_encode([
            'message' => $success !== '' ? $success : $error,
            'type' => $success !== '' ? 'success' : 'error',
        ])));
    }
}

// Schedules come from the cache frs_cimm_run_sync() populates (cron job or
// the Sync Now action above) - rendering this page never makes a live CIMM
// API call itself.
$schedulesCache = frs_cimm_load_schedules_cache();
$maintenanceSchedules = $schedulesCache['schedules'];
$schedulesCachedAt = $schedulesCache['cached_at'];

$cimmSyncState = frs_cimm_load_sync_state();

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

// Filters and pagination for the maintenance schedules table
$statusFilter = $_GET['status'] ?? 'all';
$priorityFilter = $_GET['priority'] ?? 'all';
$searchFilter = trim((string)($_GET['q'] ?? ''));
$upcomingFiltered = $upcomingSchedules;
if ($statusFilter !== 'all') {
    $upcomingFiltered = array_filter($upcomingFiltered, fn($s) => (strtolower($s['status'] ?? '') === $statusFilter));
}
if ($priorityFilter !== 'all') {
    $upcomingFiltered = array_filter($upcomingFiltered, fn($s) => (strtolower($s['priority'] ?? '') === $priorityFilter));
}
if ($searchFilter !== '') {
    $needle = strtolower($searchFilter);
    $upcomingFiltered = array_filter($upcomingFiltered, function ($s) use ($needle) {
        $haystack = strtolower(
            ($s['facility_name'] ?? '') . ' ' . ($s['maintenance_type'] ?? '') . ' ' . ($s['category'] ?? '')
        );
        return str_contains($haystack, $needle);
    });
}

// Vicinity-only categories (roads/street lights/drainage) never affect the facility
// itself - see cimmCategoryAffectsFacility() - so they're noise in this table by
// default. Hidden unless staff explicitly ask to see them.
$showVicinity = ($_GET['show_vicinity'] ?? '') === '1';
$vicinityHiddenCount = 0;
foreach ($upcomingFiltered as $s) {
    if (!cimmCategoryAffectsFacility((string)($s['category'] ?? ''), (string)($s['maintenance_type'] ?? ''))) {
        $vicinityHiddenCount++;
    }
}
if (!$showVicinity) {
    $upcomingFiltered = array_filter(
        $upcomingFiltered,
        fn($s) => cimmCategoryAffectsFacility((string)($s['category'] ?? ''), (string)($s['maintenance_type'] ?? ''))
    );
}

$totalFiltered = count($upcomingFiltered);
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$upcomingPaginated = array_slice(array_values($upcomingFiltered), $offset, $perPage);

ob_start();
?>
<style>
.mi-tabs { display:flex; gap:0.35rem; border-bottom:2px solid #e8ecf4; margin-bottom:1.25rem; flex-wrap:wrap; }
.mi-tab {
    display:inline-block; padding:0.55rem 1rem; border-radius:8px 8px 0 0;
    text-decoration:none; color:#4c5b7c; font-weight:700; font-size:0.9rem;
    border:1px solid transparent; margin-bottom:-2px;
}
.mi-tab.active { color:#0369a1; background:#fff; border-color:#e8ecf4; border-bottom-color:#fff; }
.mi-tab-pane { display:none; }
.mi-tab-pane.active { display:block; }
.pm-panel .pm-intro { margin-bottom:0.75rem; color:#475569; font-size:0.86rem; line-height:1.5; }
.pm-info-link { background:none; border:none; padding:0; margin-left:0.35rem; color:#0369a1; font-weight:700; font-size:0.86rem; text-decoration:underline; cursor:pointer; }
.pm-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:0.55rem; margin-bottom:0.75rem; }
.pm-stat { background:#fff; border:1px solid #e8ecf4; border-radius:10px; padding:0.6rem 0.8rem; }
.pm-stat-label { font-size:0.68rem; color:#64748b; font-weight:700; text-transform:uppercase; }
.pm-stat-value { font-size:1.25rem; font-weight:800; color:#0f172a; margin-top:0.1rem; }
.pm-stat-value.danger { color:#dc2626; } .pm-stat-value.warn { color:#d97706; } .pm-stat-value.ok { color:#16a34a; }
.pm-toolbar { display:flex; justify-content:space-between; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.65rem; align-items:center; }
.pm-filters { display:flex; gap:0.35rem; flex-wrap:wrap; }
.pm-filter-btn { border:1px solid #dbe2ef; background:#fff; color:#475569; padding:0.3rem 0.65rem; border-radius:999px; font-size:0.76rem; font-weight:700; text-decoration:none; }
.pm-filter-btn.active, .pm-filter-btn:hover { background:#0ea5e9; border-color:#0ea5e9; color:#fff; }
.pm-toolbar-actions { display:flex; gap:0.5rem; flex-wrap:wrap; }
.pm-export-btn { padding:0.35rem 0.75rem; font-size:0.78rem; font-weight:700; border-radius:8px; }
.pm-manual-btn { border-color:#fca5a5; color:#b91c1c; background:#fef2f2; }
.pm-manual-btn:hover { background:#fee2e2; }
.pm-search-bar { display:flex; gap:0.5rem; margin-bottom:0.75rem; }
.pm-search-bar input[type="text"] { flex:1; min-width:180px; padding:0.45rem 0.65rem; border:1px solid #e0e6ed; border-radius:8px; font-size:0.82rem; }
.pm-layout { display:grid; grid-template-columns:1fr 280px; gap:0.85rem; align-items:start; }
@media (max-width:1000px){ .pm-layout { grid-template-columns:1fr; } }
.pm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:0.65rem; align-items:stretch; }
.pm-card { background:#fff; border:1px solid #e8ecf4; border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(15,23,42,0.04); display:flex; flex-direction:column; height:100%; }
.pm-card-media { height:85px; background-size:cover; background-position:center; position:relative; background-color:#e2e8f0; flex-shrink:0; }
.pm-risk-pill { position:absolute; top:0.4rem; right:0.4rem; padding:0.15rem 0.5rem; border-radius:999px; font-size:0.66rem; font-weight:800; }
.pm-card-body { padding:0.65rem 0.75rem 0.75rem; display:flex; flex-direction:column; flex:1; }
.pm-card-title { margin:0; font-size:0.92rem; font-weight:800; color:#0f172a; display:-webkit-box; -webkit-line-clamp:2; line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.pm-card-meta { margin:0.15rem 0 0.5rem; font-size:0.72rem; color:#64748b; }
.pm-risk-bar { height:6px; border-radius:999px; background:#f1f5f9; overflow:hidden; margin-top:0.2rem; }
.pm-risk-bar > span { display:block; height:100%; border-radius:999px; }
.pm-risk-bar-label { display:flex; justify-content:space-between; font-size:0.68rem; color:#64748b; font-weight:600; }
.pm-risk-breakdown { font-size:0.65rem; color:#94a3b8; margin-top:0.25rem; }
.pm-metrics { display:grid; grid-template-columns:1fr 1fr; gap:0.35rem; margin:0.5rem 0; }
.pm-metric { background:#f8fafc; border-radius:8px; padding:0.35rem 0.5rem; font-size:0.7rem; color:#64748b; }
.pm-metric strong { display:block; color:#0f172a; font-size:0.85rem; margin-top:0.05rem; }
.pm-window { font-size:0.74rem; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:0.35rem 0.5rem; margin-bottom:0.5rem; }
.pm-ai-explain { margin-bottom:0.5rem; }
.pm-ai-btn { background:none; border:1px dashed #c7d2fe; color:#4338ca; padding:0.3rem 0.6rem; border-radius:8px; font-size:0.72rem; font-weight:700; cursor:pointer; width:100%; }
.pm-ai-btn:hover { background:#eef2ff; }
.pm-ai-btn:disabled { opacity:0.6; cursor:default; }
.pm-ai-text { font-size:0.75rem; color:#3730a3; background:#eef2ff; border-radius:8px; padding:0.5rem 0.6rem; margin:0; line-height:1.4; }
.pm-manual-tag { display:inline-block; background:#fee2e2; color:#b91c1c; font-size:0.62rem; font-weight:800; text-transform:uppercase; padding:0.05rem 0.35rem; border-radius:999px; margin-left:0.3rem; vertical-align:middle; }
.pm-how-list { margin:0.5rem 0; padding-left:1.15rem; font-size:0.85rem; line-height:1.5; color:#334155; }
.pm-how-list li { margin-bottom:0.4rem; }
.pm-card-actions { margin-top:auto; padding-top:0.5rem; }
.pm-btn-request { width:100%; border:none; border-radius:8px; padding:0.5rem; font-weight:800; font-size:0.8rem; cursor:pointer; background:linear-gradient(135deg,#0284c7,#0369a1); color:#fff; }
.pm-btn-request.is-sent { background:#e2e8f0; color:#64748b; cursor:not-allowed; }
.pm-side-panel { background:#fff; border:1px solid #e8ecf4; border-radius:12px; padding:0.85rem 1rem; }
.pm-side-panel h3 { margin:0 0 0.65rem; font-size:0.95rem; }
.pm-request-list { list-style:none; margin:0; padding:0; display:grid; gap:0.55rem; }
.pm-request-item { border:1px solid #eef2f7; border-radius:10px; padding:0.55rem 0.65rem; font-size:0.78rem; }
.pm-status { display:inline-block; margin-top:0.25rem; padding:0.12rem 0.4rem; border-radius:999px; font-size:0.65rem; font-weight:800; text-transform:uppercase; }
.pm-status.sent { background:#dbeafe; color:#1d4ed8; } .pm-status.pending { background:#fef3c7; color:#b45309; }
.pm-status.failed { background:#fee2e2; color:#b91c1c; } .pm-status.acknowledged { background:#dcfce7; color:#166534; }
.pm-empty { text-align:center; padding:2rem; color:#94a3b8; border:1px dashed #dbe2ef; border-radius:12px; }
.pm-muted { color:#94a3b8; font-size:0.82rem; }
.pm-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:1200; align-items:center; justify-content:center; padding:1rem; }
.pm-modal-backdrop.open { display:flex; }
.pm-modal { background:#fff; border-radius:14px; width:min(460px,100%); max-height:90vh; overflow-y:auto; padding:1.15rem 1.25rem; }
.pm-modal label { display:block; font-size:0.8rem; font-weight:700; color:#475569; margin:0.65rem 0 0.3rem; }
.pm-modal textarea, .pm-modal select, .pm-modal input[type="date"] { width:100%; border:1px solid #dbe2ef; border-radius:8px; padding:0.55rem; font-size:0.85rem; box-sizing:border-box; }
.pm-modal textarea { min-height:84px; }
.pm-modal-actions { display:flex; gap:0.5rem; justify-content:flex-end; margin-top:0.85rem; }
.pm-modal-actions button:not(.primary) { background:#fff; border:1px solid #dbe2ef; border-radius:8px; padding:0.45rem 0.85rem; font-weight:700; cursor:pointer; color:#475569; }
.pm-modal-actions .primary { background:#0284c7; color:#fff; border:none; border-radius:8px; padding:0.45rem 0.85rem; font-weight:700; cursor:pointer; }

/* Dark mode - the whole Maintenance Insights panel (.pm-*) was built with
   light-only hardcoded colors and never got a dark pass; fixing all of it
   here rather than leaving future .pm-* additions to repeat the gap. */
[data-theme="dark"] .pm-panel .pm-intro,
[data-theme="dark"] .pm-filter-btn,
[data-theme="dark"] .pm-card-meta,
[data-theme="dark"] .pm-risk-bar-label,
[data-theme="dark"] .pm-metric,
[data-theme="dark"] .pm-stat-label,
[data-theme="dark"] .pm-modal label {
    color: var(--text-secondary);
}
[data-theme="dark"] .pm-stat {
    background: var(--bg-secondary);
    border-color: var(--border-color);
}
[data-theme="dark"] .pm-stat-value { color: var(--text-primary); }
[data-theme="dark"] .pm-filter-btn {
    background: var(--bg-secondary);
    border-color: var(--border-color);
}
[data-theme="dark"] .pm-filter-btn.active,
[data-theme="dark"] .pm-filter-btn:hover {
    background: #0ea5e9;
    border-color: #0ea5e9;
    color: #fff;
}
[data-theme="dark"] .pm-search-bar input[type="text"] {
    background: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--text-primary);
}
[data-theme="dark"] .pm-card {
    background: var(--bg-secondary);
    border-color: var(--border-color);
}
[data-theme="dark"] .pm-card-title { color: var(--text-primary); }
[data-theme="dark"] .pm-risk-bar { background: var(--bg-tertiary); }
[data-theme="dark"] .pm-risk-breakdown { color: var(--text-tertiary); }
[data-theme="dark"] .pm-metric { background: var(--bg-tertiary); }
[data-theme="dark"] .pm-metric strong { color: var(--text-primary); }
[data-theme="dark"] .pm-window {
    background: var(--bg-tertiary);
    border-color: var(--border-color);
    color: var(--text-primary);
}
[data-theme="dark"] .pm-ai-btn {
    border-color: #4c1d95;
    color: #c4b5fd;
}
[data-theme="dark"] .pm-ai-btn:hover { background: rgba(139,92,246,0.15); }
[data-theme="dark"] .pm-ai-text {
    background: rgba(139,92,246,0.12);
    color: #ddd6fe;
}
[data-theme="dark"] .pm-how-list { color: var(--text-secondary); }
[data-theme="dark"] .pm-btn-request.is-sent {
    background: var(--bg-tertiary);
    color: var(--text-tertiary);
}
[data-theme="dark"] .pm-side-panel {
    background: var(--bg-secondary);
    border-color: var(--border-color);
}
[data-theme="dark"] .pm-side-panel h3 { color: var(--text-primary); }
[data-theme="dark"] .pm-request-item {
    border-color: var(--border-color);
    color: var(--text-primary);
}
[data-theme="dark"] .pm-empty {
    color: var(--text-tertiary);
    border-color: var(--border-color);
}
[data-theme="dark"] .pm-muted { color: var(--text-tertiary); }
[data-theme="dark"] .pm-modal {
    background: var(--bg-secondary);
    color: var(--text-primary);
}
[data-theme="dark"] .pm-modal h3 { color: var(--text-primary); }
[data-theme="dark"] .pm-modal textarea,
[data-theme="dark"] .pm-modal select,
[data-theme="dark"] .pm-modal input[type="date"] {
    background: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--text-primary);
}
[data-theme="dark"] .pm-modal-actions button:not(.primary) {
    background: var(--bg-tertiary);
    border-color: var(--border-color);
    color: var(--text-primary);
}
[data-theme="dark"] .pm-manual-btn {
    background: rgba(239,68,68,0.12);
    border-color: rgba(248,113,113,0.5);
    color: #fca5a5;
}
[data-theme="dark"] .pm-manual-btn:hover { background: rgba(239,68,68,0.2); }
.mi-sync-bar {
    display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;
    background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:0.75rem 1rem; margin-bottom:1.25rem;
    font-size:0.85rem; color:#0c4a6e;
}
.mi-sync-bar .mi-sync-meta { margin:0; }
.mi-sync-bar .mi-sync-warn { color:#b45309; font-weight:600; }
[data-theme="dark"] .mi-sync-bar {
    background: rgba(14,165,233,0.12);
    border-color: rgba(14,165,233,0.3);
    color: var(--text-primary);
}
.mi-schedule-layout { grid-template-columns: 1fr !important; }
.mi-view-toggle { display:flex; justify-content:flex-end; align-items:center; margin-bottom:1rem; gap:0.5rem; }
.mi-view-toggle-btn { padding:0.4rem 0.85rem; font-size:0.85rem; }
.mi-view-toggle-btn.active { background:#0284c7; color:#fff; border-color:#0284c7; }
.mi-filter-bar { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
.mi-filter-bar input[type="text"], .mi-filter-bar select {
    padding:0.5rem 0.65rem; border:1px solid #e0e6ed; border-radius:8px; font-size:0.85rem;
}
.mi-filter-bar input[type="text"] { flex:1; min-width:180px; }
.frs-partial-loading { opacity:0.5; pointer-events:none; transition:opacity 0.15s; }
</style>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Maintenance</span>
    </div>
    <?= frs_page_title('Maintenance', 'CIMM schedules, calendar, and predictive maintenance requests.'); ?>
</div>

<nav class="mi-tabs" aria-label="Maintenance sections">
    <a class="mi-tab <?= $activeTab === 'schedules' ? 'active' : ''; ?>" href="?tab=schedules">Schedules &amp; Calendar</a>
    <a class="mi-tab <?= $activeTab === 'insights' ? 'active' : ''; ?>" href="?tab=insights">Maintenance Insights</a>
</nav>

<?php if ($success): ?>
    <div class="message success" style="background:#e3f8ef;color:#0d7a43;padding:0.85rem 1rem;border-radius:10px;margin-bottom:1rem;border:1px solid rgba(16,185,129,0.25);">
        <?= htmlspecialchars($success); ?>
    </div>
<?php elseif ($error): ?>
    <div class="message error" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:10px;margin-bottom:1rem;border:1px solid rgba(239,68,68,0.25);">
        <?= htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="mi-tab-pane <?= $activeTab === 'schedules' ? 'active' : ''; ?>" id="mi-tab-schedules">

<div class="mi-sync-bar">
    <span class="mi-sync-meta">
        <?php if ($schedulesCachedAt): ?>
            Schedule data synced <?= htmlspecialchars(date('M j, Y g:i A', strtotime($schedulesCachedAt))); ?>
        <?php else: ?>
            <span class="mi-sync-warn">No CIMM sync has run yet — click Sync Now.</span>
        <?php endif; ?>
        <?php $lastUnmatched = (int)($cimmSyncState['last_summary']['unmatched_schedule_count'] ?? 0); ?>
        <?php if ($lastUnmatched > 0): ?>
            · <strong><?= $lastUnmatched; ?> unmatched</strong> last sync (verify facility names in CIMM vs CPRF)
        <?php endif; ?>
    </span>
    <?php if ($canSubmit): ?>
        <form method="post" style="margin:0;">
            <?= csrf_field(); ?>
            <input type="hidden" name="sync_now" value="1">
            <input type="hidden" name="tab" value="schedules">
            <button type="submit" class="btn-outline" style="padding:0.4rem 0.85rem; font-size:0.85rem; font-weight:700;">
                🔄 Sync Now
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="mi-view-toggle">
    <button type="button" id="mi-view-calendar-btn" class="btn-outline mi-view-toggle-btn active">📅 Calendar</button>
    <button type="button" id="mi-view-table-btn" class="btn-outline mi-view-toggle-btn">📋 Table</button>
</div>

<div class="booking-wrapper mi-schedule-layout" id="mi-calendar-view-wrap">
    <!-- Maintenance Calendar (New Design) -->
    <aside class="booking-card maintenance-calendar-wrapper">
        <h2>Maintenance Calendar</h2>

        <!-- Calendar View -->
        <div id="calendarView">
            <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.82rem; color:#4b5563; margin-bottom:0.65rem; cursor:pointer;">
                <input type="checkbox" id="calendarShowVicinity">
                Show roads/street lights/drainage
            </label>
            <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel" title="Click to jump date"></span>
                <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">&#8594;</button>
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
    </aside>
</div>

<div class="booking-card" id="mi-table-view-wrap" data-frs-partial-id="mi-schedule-table" data-frs-partial-root style="display:none;">
        <h2 style="margin-top:0;">Upcoming Maintenance Schedules</h2>

        <form method="get" data-frs-partial="mi-schedule-table" data-frs-partial-auto class="mi-filter-bar">
            <input type="hidden" name="tab" value="schedules">
            <input type="hidden" name="page" value="1">
            <input type="text" name="q" value="<?= htmlspecialchars($searchFilter); ?>" placeholder="Search facility, type, or category...">
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            <select name="priority">
                <option value="all" <?= $priorityFilter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                <option value="high" <?= $priorityFilter === 'high' ? 'selected' : ''; ?>>High</option>
                <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="low" <?= $priorityFilter === 'low' ? 'selected' : ''; ?>>Low</option>
            </select>
            <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.85rem; color:#4b5563; white-space:nowrap;">
                <input type="checkbox" name="show_vicinity" value="1" <?= $showVicinity ? 'checked' : ''; ?>>
                Show roads/street lights/drainage
                <?php if (!$showVicinity && $vicinityHiddenCount > 0): ?>
                    <span style="color:#8b95b5;">(<?= $vicinityHiddenCount; ?> hidden)</span>
                <?php endif; ?>
            </label>
            <button type="submit" class="btn-outline" style="padding:0.5rem 0.85rem; font-size:0.85rem;">Filter</button>
        </form>

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
                            $priorityClass = in_array($schedule['priority'], ['high', 'critical'], true) ? 'offline' : ($schedule['priority'] === 'medium' ? 'maintenance' : 'active');
                            $statusClass = $schedule['status'] === 'in_progress' ? 'maintenance' : ($schedule['status'] === 'completed' ? 'active' : 'offline');
                            $statusDisplay = ucfirst(str_replace('_', ' ', $schedule['status']));
                        ?>
                            <tr>
                                <td data-label="Maintenance ID"><strong><?= htmlspecialchars($schedule['id']); ?></strong></td>
                                <td data-label="Facility"><?= htmlspecialchars($schedule['facility_name']); ?></td>
                                <td data-label="Type"><?= htmlspecialchars($schedule['maintenance_type']); ?></td>
                                <td data-label="Scheduled Date">
                                    <?= date('M d, Y', strtotime($schedule['scheduled_start'])); ?><br>
                                    <small style="color: #8b95b5;">
                                        <?= date('H:i', strtotime($schedule['scheduled_start'])); ?> -
                                        <?= date('H:i', strtotime($schedule['scheduled_end'])); ?>
                                    </small>
                                </td>
                                <td data-label="Duration"><?= htmlspecialchars($schedule['estimated_duration']); ?></td>
                                <td class="td-badge" data-label="Priority">
                                    <span class="status-badge status-badge--cell <?= $priorityClass; ?>" style="text-transform: capitalize;" title="<?= htmlspecialchars($schedule['priority']); ?>">
                                        <?= htmlspecialchars($schedule['priority']); ?>
                                    </span>
                                </td>
                                <td class="td-badge" data-label="Status">
                                    <span class="status-badge status-badge--cell <?= $statusClass; ?>" title="<?= htmlspecialchars($statusDisplay); ?>">
                                        <?= $statusDisplay; ?>
                                    </span>
                                </td>
                                <td data-label="Affected">
                                    <?php if ($schedule['affected_reservations'] > 0): ?>
                                        <span style="color: #dc3545; font-weight: 600;">
                                            <?= $schedule['affected_reservations']; ?> reservation(s)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #8b95b5;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
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
            $linkParams = array_filter([
                'tab' => 'schedules',
                'status' => $statusFilter !== 'all' ? $statusFilter : null,
                'priority' => $priorityFilter !== 'all' ? $priorityFilter : null,
                'q' => $searchFilter !== '' ? $searchFilter : null,
                'show_vicinity' => $showVicinity ? '1' : null,
            ]);
            $prevQuery = $page > 1 ? http_build_query($linkParams + ['page' => $page - 1]) : '';
            $nextQuery = $page < $totalPages ? http_build_query($linkParams + ['page' => $page + 1]) : '';
            ?>
            <div class="pagination-bar" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
                <span style="color: #6b7280; font-size: 0.9rem;">
                    Showing <?= $totalFiltered ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $totalFiltered); ?> of <?= $totalFiltered; ?>
                </span>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <?php if ($prevQuery): ?>
                        <a href="?<?= htmlspecialchars($prevQuery); ?>" data-frs-partial="mi-schedule-table" class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem;">← Prev</a>
                    <?php else: ?>
                        <span class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem; opacity: 0.5; pointer-events: none;">← Prev</span>
                    <?php endif; ?>
                    <span style="font-size: 0.9rem; color: #4b5563;">Page <?= $page; ?> of <?= $totalPages; ?></span>
                    <?php if ($nextQuery): ?>
                        <a href="?<?= htmlspecialchars($nextQuery); ?>" data-frs-partial="mi-schedule-table" class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem;">Next →</a>
                    <?php else: ?>
                        <span class="btn-outline" style="padding: 0.4rem 0.75rem; font-size: 0.875rem; opacity: 0.5; pointer-events: none;">Next →</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
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
                            <td data-label="Maintenance ID"><strong><?= htmlspecialchars($history['id']); ?></strong></td>
                            <td data-label="Facility"><?= htmlspecialchars($history['facility_name']); ?></td>
                            <td data-label="Type"><?= htmlspecialchars($history['maintenance_type']); ?></td>
                            <td data-label="Completed Date"><?= date('M d, Y H:i', strtotime($history['completed_at'])); ?></td>
                            <td data-label="Duration"><?= htmlspecialchars($history['duration']); ?></td>
                            <td data-label="Technician"><?= htmlspecialchars($history['technician']); ?></td>
                            <td data-label="Status">
                                <span class="status-badge active">Completed</span>
                            </td>
                            <td data-label="Action">
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
</div><!-- /mi-tab-schedules -->

<div class="mi-tab-pane <?= $activeTab === 'insights' ? 'active' : ''; ?>" id="mi-tab-insights">
    <?php include __DIR__ . '/partials/maintenance_insights_panel.php'; ?>
</div>

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

// =============== CALENDAR / TABLE VIEW TOGGLE ===============
(function () {
    const calendarBtn = document.getElementById('mi-view-calendar-btn');
    const tableBtn = document.getElementById('mi-view-table-btn');
    const calendarWrap = document.getElementById('mi-calendar-view-wrap');
    const tableWrap = document.getElementById('mi-table-view-wrap');
    if (!calendarBtn || !tableBtn || !calendarWrap || !tableWrap) return;

    function showCalendar() {
        calendarWrap.style.display = '';
        tableWrap.style.display = 'none';
        calendarBtn.classList.add('active');
        tableBtn.classList.remove('active');
    }
    function showTable() {
        calendarWrap.style.display = 'none';
        tableWrap.style.display = '';
        tableBtn.classList.add('active');
        calendarBtn.classList.remove('active');
    }
    calendarBtn.addEventListener('click', showCalendar);
    tableBtn.addEventListener('click', showTable);
})();

// =============== SCHEDULE DATA FOR CALENDAR ===============
// Same vicinity-only concept as the table view (roads/street lights/drainage
// never affect facility booking - see cimmCategoryAffectsFacility()) - sent
// as a per-event flag so the calendar's own toggle can filter instantly on
// the client, no reload (the table's checkbox originally needed a Filter
// click before it applied and that was a real reported bug - not repeating it).
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
        'affects_facility' => cimmCategoryAffectsFacility((string)($schedule['category'] ?? ''), (string)($schedule['maintenance_type'] ?? '')),
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
    const prevMonthBtn = document.getElementById('prevMonth');
    const nextMonthBtn = document.getElementById('nextMonth');
    const showVicinityCheckbox = document.getElementById('calendarShowVicinity');

    if (!calendarGrid || !calendarDetails) return;

    let currentDate = new Date();

    function visibleSchedules() {
        const all = Array.isArray(window.scheduleData) ? window.scheduleData : [];
        return showVicinityCheckbox && showVicinityCheckbox.checked
            ? all
            : all.filter(e => e.affects_facility !== false);
    }

    // Same category + same facility on the same day is almost always
    // separate CIMM work-order records for the same ongoing job (e.g. five
    // "Roads" entries at one facility), not five different things - one
    // chip with a count reads far clearer than a wall of identical labels.
    function groupEvents(events) {
        const groups = [];
        const seen = new Map();
        events.forEach(e => {
            const key = (e.category || 'General Maintenance') + '|' + (e.location || '');
            if (seen.has(key)) {
                seen.get(key).items.push(e);
            } else {
                const group = { items: [e] };
                seen.set(key, group);
                groups.push(group);
            }
        });
        return groups;
    }

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
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        for (let i = 0; i < firstDay; i++) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'calendar-day';
            calendarGrid.appendChild(emptyDiv);
        }
        
        const visible = visibleSchedules();

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const events = visible.filter(e => e.schedule_date === dateStr);
            const groups = groupEvents(events);

            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');
            dayDiv.setAttribute('data-date', dateStr);

            const dayNumDiv = document.createElement('div');
            dayNumDiv.textContent = d;
            dayDiv.appendChild(dayNumDiv);

            if (groups.length) {
                const tasksDiv = document.createElement('div');
                tasksDiv.className = 'day-tasks';

                const groupLabel = function(group) {
                    // Prefer the specific task name (e.g. "Roads") when every item in
                    // the group agrees on it - more recognizable than the broader
                    // category CIMM files it under (e.g. "Infrastructure Report").
                    // Falls back to the category only when the group's tasks differ.
                    const tasks = new Set(group.items.map(e => e.task).filter(Boolean));
                    const base = tasks.size === 1
                        ? [...tasks][0]
                        : (group.items[0].category || group.items[0].task || 'Maintenance');
                    return group.items.length > 1 ? `${base} (×${group.items.length})` : base;
                };
                const groupClick = function(group) {
                    return function(ev) {
                        ev.stopPropagation();
                        if (group.items.length === 1) {
                            const e = group.items[0];
                            viewMaintenanceDetails(e.id || (e.sched_id ? ('CIMM-S-' + e.sched_id) : ''), dateStr);
                        } else {
                            openTaskChooser(dateStr, group.items.map(e => ({
                                id: e.id, sched_id: e.sched_id, rep_id: e.rep_id, task: e.task,
                                location: e.location, category: e.category, priority: e.priority,
                                status_label: e.status_label, assigned_team: e.assigned_team, schedule_date: dateStr
                            })));
                        }
                    };
                };

                if (groups.length === 1) {
                    const group = groups[0];
                    const btn = document.createElement('button');
                    btn.className = 'task-btn';
                    btn.textContent = isMobileView() ? String(group.items.length) : groupLabel(group);
                    btn.title = `${groupLabel(group)} – ${group.items[0].location || ''}`;
                    const key = getStatusKey(group.items[0].status_label);
                    if (key) btn.classList.add('status-' + key + '-bg');
                    btn.onclick = groupClick(group);
                    tasksDiv.appendChild(btn);
                } else {
                    const first = groups[0];
                    const firstBtn = document.createElement('button');
                    firstBtn.className = 'task-btn';
                    firstBtn.textContent = isMobileView() ? String(events.length) : groupLabel(first);
                    firstBtn.title = `${groupLabel(first)} – ${first.items[0].location || ''}`;
                    const firstKey = getStatusKey(first.items[0].status_label);
                    if (firstKey) firstBtn.classList.add('status-' + firstKey + '-bg');
                    firstBtn.onclick = groupClick(first);
                    tasksDiv.appendChild(firstBtn);

                    const moreWrap = document.createElement('div');
                    moreWrap.className = 'more-tasks-wrap';
                    const arrowBtn = document.createElement('button');
                    arrowBtn.className = 'more-tasks-btn';
                    arrowBtn.innerHTML = '▾';
                    arrowBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        openTaskChooser(dateStr, events.map(e => ({
                            id: e.id, sched_id: e.sched_id, rep_id: e.rep_id, task: e.task,
                            location: e.location, category: e.category, priority: e.priority,
                            status_label: e.status_label, assigned_team: e.assigned_team, schedule_date: dateStr
                        })));
                    };
                    moreWrap.appendChild(arrowBtn);
                    if (!isMobileView()) {
                        const counter = document.createElement('span');
                        counter.className = 'task-counter';
                        counter.textContent = `+${groups.length - 1}`;
                        moreWrap.appendChild(counter);
                    }
                    tasksDiv.appendChild(moreWrap);
                }
                dayDiv.appendChild(tasksDiv);
            }

            dayDiv.addEventListener('click', function() {
                if (groups.length) {
                    let detailsHtml = `<strong>${dateStr}</strong><br>`;
                    detailsHtml += groups.map(g => {
                        const tasks = new Set(g.items.map(e => e.task).filter(Boolean));
                        const label = tasks.size === 1 ? [...tasks][0] : (g.items[0].category || g.items[0].task || 'Maintenance');
                        const suffix = g.items.length > 1 ? ` (×${g.items.length})` : '';
                        return `• ${label}${suffix} – ${g.items[0].location || ''}`;
                    }).join('<br>');
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
    
    if (prevMonthBtn) prevMonthBtn.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    };
    if (nextMonthBtn) nextMonthBtn.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    };
    if (showVicinityCheckbox) showVicinityCheckbox.addEventListener('change', renderCalendar);

    renderCalendar();

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

