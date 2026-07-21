<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/blackout_dates.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'blackout_dates')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pdo = db();
$userId = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = 'Blackout Dates | LGU Facilities Reservation';
$hasTable = frs_blackout_table_exists($pdo);
$base = base_path();

$message = '';
$messageType = 'success';
if (!empty($_SESSION['blackout_flash']) && is_array($_SESSION['blackout_flash'])) {
    $message = (string)($_SESSION['blackout_flash']['msg'] ?? '');
    $messageType = (string)($_SESSION['blackout_flash']['type'] ?? 'success');
    unset($_SESSION['blackout_flash']);
}

$filterYear = (int)($_GET['year'] ?? date('Y'));
if ($filterYear < 2020 || $filterYear > 2100) {
    $filterYear = (int)date('Y');
}
$filterFacility = isset($_GET['facility_id']) && ctype_digit((string)$_GET['facility_id'])
    ? (int)$_GET['facility_id']
    : 0;

$calMonth = (int)($_GET['month'] ?? 0);
if ($calMonth < 1 || $calMonth > 12) {
    $calMonth = ((int)date('Y') === $filterYear) ? (int)date('m') : 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTable) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add') {
            $facilityId = (int)($_POST['facility_id'] ?? 0);
            $date = trim((string)($_POST['blackout_date'] ?? ''));
            $reasonPreset = trim((string)($_POST['reason_preset'] ?? ''));
            $reasonCustom = trim((string)($_POST['reason_custom'] ?? ''));
            $reason = $reasonCustom !== '' ? $reasonCustom : $reasonPreset;

            $r = frs_add_blackout_date($pdo, $facilityId, $date, $reason, $userId);
            if (!empty($r['errors'])) {
                $message = implode(' ', $r['errors']);
                $messageType = 'error';
            } elseif ($r['added'] > 0) {
                logAudit('Added blackout date', 'Blackout Dates', "Facility #{$facilityId} on {$date}: {$reason}");
                $message = 'Blackout date added. Bookings are blocked for that facility on that day.';
                if ($r['affected_reservations'] > 0) {
                    $message .= " {$r['affected_reservations']} existing reservation(s) were auto-postponed.";
                }
                if (!empty($r['announcement']['published'])) {
                    $message .= ' A public announcement was auto-published'
                        . (!empty($r['announcement']['title']) ? ': "' . $r['announcement']['title'] . '".' : '.');
                }
                $messageType = 'success';
            } else {
                $message = 'That date is already blacked out for this facility.';
                $messageType = 'error';
            }
        } elseif ($action === 'add_range') {
            $facilityId = (int)($_POST['facility_id'] ?? 0);
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            $reasonPreset = trim((string)($_POST['reason_preset'] ?? ''));
            $reasonCustom = trim((string)($_POST['reason_custom'] ?? ''));
            $reason = $reasonCustom !== '' ? $reasonCustom : $reasonPreset;

            $r = frs_add_blackout_date_range($pdo, $facilityId, $startDate, $endDate, $reason, $userId);
            if (!empty($r['errors'])) {
                $message = implode(' ', $r['errors']);
                $messageType = 'error';
            } else {
                logAudit(
                    'Added blackout date range',
                    'Blackout Dates',
                    "Facility #{$facilityId} {$startDate} to {$endDate}: {$reason} ({$r['added']} added, {$r['skipped']} skipped)"
                );
                $message = "Added {$r['added']} blackout day(s)."
                    . ($r['skipped'] > 0 ? " {$r['skipped']} day(s) were already blocked." : '');
                if ($r['affected_reservations'] > 0) {
                    $message .= " {$r['affected_reservations']} existing reservation(s) were auto-postponed.";
                }
                if (!empty($r['announcement']['published'])) {
                    $message .= ' A public announcement was auto-published'
                        . (!empty($r['announcement']['title']) ? ': "' . $r['announcement']['title'] . '".' : '.');
                }
                $messageType = $r['added'] > 0 ? 'success' : 'error';
            }
        } elseif ($action === 'delete') {
            $blackoutId = (int)($_POST['blackout_id'] ?? 0);
            $row = frs_get_blackout_by_id($pdo, $blackoutId);
            if (!$row) {
                $message = 'Blackout entry not found.';
                $messageType = 'error';
            } elseif (frs_delete_blackout_date($pdo, $blackoutId)) {
                logAudit(
                    'Removed blackout date',
                    'Blackout Dates',
                    ($row['facility_name'] ?? '') . ' on ' . ($row['blackout_date'] ?? '')
                );
                $message = 'Blackout removed. That date is available for booking again.';
                $messageType = 'success';
            } elseif ($row && frs_blackout_is_cimm_sync($row)) {
                $message = 'CIMM maintenance dates are managed automatically. Update or complete the schedule in CIMM, or use Maintenance Integration to sync.';
                $messageType = 'error';
            } else {
                $message = 'Could not remove blackout.';
                $messageType = 'error';
            }
        }

        if ($messageType === 'success') {
            $redirectYear = (int)($_POST['bo_year'] ?? $filterYear);
            $redirectMonth = (int)($_POST['bo_month'] ?? $calMonth);
            $redirectFacility = (int)($_POST['bo_facility_id'] ?? $filterFacility);
            $redirectDate = null;
            if ($action === 'add') {
                $addedDate = trim((string)($_POST['blackout_date'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $addedDate)) {
                    $redirectDate = $addedDate;
                    $redirectMonth = (int)date('m', strtotime($addedDate));
                    $redirectYear = (int)date('Y', strtotime($addedDate));
                }
            } elseif ($action === 'add_range') {
                $startDate = trim((string)($_POST['start_date'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                    $redirectMonth = (int)date('m', strtotime($startDate));
                    $redirectYear = (int)date('Y', strtotime($startDate));
                }
            }
            $_SESSION['blackout_flash'] = ['msg' => $message, 'type' => $messageType];
            header('Location: ' . blackout_filter_url($redirectYear, $redirectMonth, $redirectFacility, $redirectDate));
            exit;
        }
    }
}

$facilities = $pdo->query(
    "SELECT id, name FROM facilities WHERE status != 'deleted' ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$totalRows = $hasTable ? frs_count_blackout_dates($pdo, $filterYear, $filterFacility ?: null) : 0;
$sourceCounts = $hasTable ? frs_count_blackout_dates_by_source($pdo, $filterYear, $filterFacility ?: null) : ['manual' => 0, 'cimm' => 0, 'total' => 0];

$calMonthTs = mktime(0, 0, 0, $calMonth, 1, $filterYear);
$calDaysInMonth = (int)date('t', $calMonthTs);
$calFirstWeekday = (int)date('w', $calMonthTs);
$calMonthLabel = date('F Y', $calMonthTs);
$calRangeStart = date('Y-m-01', $calMonthTs);
$calRangeEnd = date('Y-m-t', $calMonthTs);

$monthBlackouts = $hasTable
    ? frs_blackout_enrich_rows(frs_list_blackout_dates_between($pdo, $calRangeStart, $calRangeEnd, $filterFacility ?: null))
    : [];

$monthSourceCounts = frs_blackout_count_by_source($monthBlackouts);

$blackoutsByDate = [];
foreach ($monthBlackouts as $row) {
    $d = (string)$row['blackout_date'];
    $blackoutsByDate[$d][] = $row;
}

$selectedDate = trim((string)($_GET['selected_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '';
}
$dayBlackouts = $selectedDate !== '' ? ($blackoutsByDate[$selectedDate] ?? []) : [];
$selectedDateLabel = $selectedDate !== '' ? date('l, F j, Y', strtotime($selectedDate)) : '';

$calPrevTs = strtotime('-1 month', $calMonthTs);
$calNextTs = strtotime('+1 month', $calMonthTs);
$calPrevYear = (int)date('Y', $calPrevTs);
$calPrevMonth = (int)date('m', $calPrevTs);
$calNextYear = (int)date('Y', $calNextTs);
$calNextMonth = (int)date('m', $calNextTs);
$boYearMin = (int)date('Y') - 5;
$boYearMax = (int)date('Y') + 5;

$reasonPresets = [
    'LGU event / program',
    'Barangay activity',
    'Facility maintenance',
    'Holiday closure',
    'Reserved for official use',
    'Other',
];

function blackout_filter_url(int $year, int $month, int $facilityId, ?string $selectedDate = null): string
{
    $q = ['year' => $year, 'month' => $month];
    if ($facilityId > 0) {
        $q['facility_id'] = $facilityId;
    }
    if ($selectedDate !== null && $selectedDate !== '') {
        $q['selected_date'] = $selectedDate;
    }
    return base_path() . '/dashboard/blackout-dates?' . http_build_query($q);
}

$yearMin = $filterYear . '-01-01';
$yearMax = $filterYear . '-12-31';
$filterBaseUrl = blackout_filter_url($filterYear, $calMonth, $filterFacility);

ob_start();
?>
<div class="frs-blackout-page max-w-6xl mx-auto w-full pb-8">
    <!-- Header -->
    <header class="mb-6">
        <nav class="text-sm text-slate-500 mb-2" aria-label="Breadcrumb">
            <span>Facilities</span>
            <span class="mx-1.5 text-slate-300">/</span>
            <span class="text-slate-700 font-medium">Blackout dates</span>
        </nav>
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight frs-heading-with-tip">
                    Facility blackout dates
                    <?= frs_field_tip('Block days when a facility cannot be booked (events, holidays, maintenance). Residents see these as unavailable on the booking calendar.'); ?>
                </h1>
            </div>
            <?php if ($hasTable): ?>
                <div class="frs-bo-year-total flex-shrink-0 rounded-xl bg-slate-100 px-4 py-2.5 text-sm flex flex-col gap-1">
                    <div>
                        <span class="text-slate-500"><?= (int)$filterYear; ?> CPRF blackouts</span>
                        <span class="font-bold text-slate-900"><?= (int)$sourceCounts['manual']; ?></span>
                    </div>
                    <div>
                        <span class="text-slate-500">CIMM maintenance</span>
                        <span class="font-bold text-amber-800"><?= (int)$sourceCounts['cimm']; ?></span>
                    </div>
                    <?php if (!empty($sourceCounts['ipms'])): ?>
                    <div>
                        <span class="text-slate-500">IPMS projects</span>
                        <span class="font-bold text-sky-800"><?= (int)$sourceCounts['ipms']; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$hasTable): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900 text-sm">
            The <code class="rounded bg-amber-100/80 px-1.5 py-0.5 text-xs">facility_blackout_dates</code> table is missing.
            Run <code class="rounded bg-amber-100/80 px-1.5 py-0.5 text-xs">database/migration_add_auto_approval.sql</code>.
        </div>
    <?php else: ?>

        <?php if ($message): ?>
            <div class="mb-5 rounded-xl border px-4 py-3 text-sm flex items-start gap-3 <?= $messageType === 'error'
                ? 'border-red-200 bg-red-50 text-red-800'
                : 'border-emerald-200 bg-emerald-50 text-emerald-800'; ?>" role="alert">
                <i class="bi <?= $messageType === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle'; ?> text-lg flex-shrink-0 mt-0.5"></i>
                <span><?= htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Calendar -->
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 px-4 sm:px-6 py-4 border-b border-slate-100 bg-slate-50/50">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Blackout calendar</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Click a highlighted date to view details. Red = CPRF blackout · Amber = CIMM maintenance · Blue = IPMS project.</p>
                </div>
                <div class="flex flex-wrap items-end gap-2 sm:gap-3 frs-bo-toolbar-filters">
                    <button type="button" id="bo-add-modal-btn" class="btn-primary inline-flex items-center gap-2 w-full sm:w-auto justify-center">
                        <i class="bi bi-plus-lg"></i> Add Blackout
                    </button>
                    <form method="GET" action="<?= htmlspecialchars($base . '/dashboard/blackout-dates'); ?>"
                        class="flex flex-wrap items-end gap-2 sm:gap-3 w-full sm:w-auto frs-bo-filter-form" data-frs-partial="bo-calendar" data-frs-partial-auto>
                    <div class="frs-bo-filter-field">
                        <label for="filter-month" class="block text-xs font-semibold text-slate-500 mb-1">Month</label>
                        <select id="filter-month" name="month"
                            class="w-full min-w-0 sm:min-w-[8.5rem] rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m; ?>" <?= $calMonth === $m ? 'selected' : ''; ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="frs-bo-filter-field">
                        <label for="filter-year" class="block text-xs font-semibold text-slate-500 mb-1">Year</label>
                        <select id="filter-year" name="year"
                            class="w-full min-w-0 sm:min-w-[5.5rem] rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                            <?php for ($y = $boYearMax; $y >= $boYearMin; $y--): ?>
                                <option value="<?= $y; ?>" <?= $filterYear === $y ? 'selected' : ''; ?>><?= $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="frs-bo-filter-field frs-bo-filter-facility flex-1 min-w-[10rem]">
                        <label for="filter-facility" class="block text-xs font-semibold text-slate-500 mb-1">Facility</label>
                        <select id="filter-facility" name="facility_id"
                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                            <option value="0">All facilities</option>
                            <?php foreach ($facilities as $f): ?>
                                <option value="<?= (int)$f['id']; ?>" <?= $filterFacility === (int)$f['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($f['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
                </div>
            </div>

            <div data-frs-partial-id="bo-calendar" data-frs-partial-root>
            <div class="px-4 sm:px-6 py-4 border-b border-slate-100">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2 frs-bo-cal-nav">
                        <a href="<?= htmlspecialchars(blackout_filter_url($calPrevYear, $calPrevMonth, $filterFacility)); ?>"
                            data-frs-partial="bo-calendar"
                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            &larr; <span class="frs-bo-nav-label">Prev</span>
                        </a>
                        <span class="frs-bo-month-label lg:hidden text-sm font-semibold text-slate-900 px-1"><?= htmlspecialchars($calMonthLabel); ?></span>
                        <form method="GET" action="<?= htmlspecialchars($base . '/dashboard/blackout-dates'); ?>"
                            class="hidden lg:inline-flex flex-wrap items-center gap-2" data-frs-partial="bo-calendar" data-frs-partial-auto>
                            <input type="hidden" name="facility_id" value="<?= (int)$filterFacility; ?>">
                            <select name="month" aria-label="Select month"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m; ?>" <?= $calMonth === $m ? 'selected' : ''; ?>>
                                        <?= date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select name="year" aria-label="Select year"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                                <?php for ($y = $boYearMax; $y >= $boYearMin; $y--): ?>
                                    <option value="<?= $y; ?>" <?= $filterYear === $y ? 'selected' : ''; ?>><?= $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </form>
                        <a href="<?= htmlspecialchars(blackout_filter_url($calNextYear, $calNextMonth, $filterFacility)); ?>"
                            data-frs-partial="bo-calendar"
                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            <span class="frs-bo-nav-label">Next</span> &rarr;
                        </a>
                        <a href="<?= htmlspecialchars(blackout_filter_url((int)date('Y'), (int)date('n'), $filterFacility)); ?>"
                            data-frs-partial="bo-calendar"
                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Today
                        </a>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:gap-3 text-xs text-slate-500 frs-bo-legend">
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-red-400"></span> CPRF</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> CIMM</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-400"></span> IPMS</span>
                        <span class="hidden sm:inline"><?= (int)$monthSourceCounts['manual']; ?> blackout · <?= (int)$monthSourceCounts['cimm']; ?> maintenance · <?= (int)($monthSourceCounts['ipms'] ?? 0); ?> project</span>
                    </div>
                </div>
            </div>

            <div class="p-3 sm:p-6 frs-bo-calendar-wrap">
                <div class="frs-bo-calendar-grid frs-bo-calendar-head">
                    <?php
                    $dowFull = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    $dowShort = ['S','M','T','W','T','F','S'];
                    foreach ($dowFull as $i => $dn):
                    ?>
                        <div class="frs-bo-calendar-dow">
                            <span class="frs-bo-dow-full"><?= $dn; ?></span>
                            <span class="frs-bo-dow-short"><?= $dowShort[$i]; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="frs-bo-calendar-grid">
                    <?php for ($i = 0; $i < $calFirstWeekday; $i++): ?>
                        <div class="frs-bo-cal-cell is-empty" aria-hidden="true"></div>
                    <?php endfor;
                    $todayDate = date('Y-m-d');
                    for ($day = 1; $day <= $calDaysInMonth; $day++):
                        $dateStr = sprintf('%04d-%02d-%02d', $filterYear, $calMonth, $day);
                        $cellItems = $blackoutsByDate[$dateStr] ?? [];
                        $hasBlackout = !empty($cellItems);
                        $cellManual = 0;
                        $cellCimm = 0;
                        $cellIpms = 0;
                        foreach ($cellItems as $ci) {
                            $ciType = $ci['source_type'] ?? '';
                            if ($ciType === 'cimm') {
                                $cellCimm++;
                            } elseif ($ciType === 'ipms') {
                                $cellIpms++;
                            } else {
                                $cellManual++;
                            }
                        }
                        $isToday = ($dateStr === $todayDate);
                        $cellUrl = $hasBlackout
                            ? blackout_filter_url($filterYear, $calMonth, $filterFacility, $dateStr)
                            : '';
                        $cellClasses = 'frs-bo-cal-cell';
                        if ($hasBlackout) {
                            $sourcesPresent = (int)($cellManual > 0) + (int)($cellCimm > 0) + (int)($cellIpms > 0);
                            if ($sourcesPresent > 1) {
                                $cellClasses .= ' is-mixed';
                            } elseif ($cellCimm > 0) {
                                $cellClasses .= ' is-cimm';
                            } elseif ($cellIpms > 0) {
                                $cellClasses .= ' is-ipms';
                            } else {
                                $cellClasses .= ' is-blocked';
                            }
                        }
                        if ($isToday) {
                            $cellClasses .= ' is-today';
                        }
                        $chipText = '';
                        if ($hasBlackout) {
                            $n = count($cellItems);
                            if ($n === 1) {
                                $fname = (string)$cellItems[0]['facility_name'];
                                $chipText = strlen($fname) > 12 ? substr($fname, 0, 11) . '...' : $fname;
                            } else {
                                $parts = [];
                                if ($cellManual > 0) {
                                    $parts[] = $cellManual . ' blackout';
                                }
                                if ($cellCimm > 0) {
                                    $parts[] = $cellCimm . ' maint.';
                                }
                                if ($cellIpms > 0) {
                                    $parts[] = $cellIpms . ' project';
                                }
                                $chipText = implode(' · ', $parts);
                            }
                            if ($n === 1 && ($cellItems[0]['source_type'] ?? '') === 'cimm') {
                                $chipText = 'Maint.';
                            } elseif ($n === 1 && ($cellItems[0]['source_type'] ?? '') === 'ipms') {
                                $chipText = 'Project';
                            }
                        }
                    ?>
                        <?php if ($hasBlackout): ?>
                        <a href="<?= htmlspecialchars($cellUrl); ?>" data-frs-partial="bo-calendar" class="<?= $cellClasses; ?>">
                        <?php else: ?>
                        <div class="<?= $cellClasses; ?>">
                        <?php endif; ?>
                            <span class="text-sm font-bold bo-day-num"><?= $day; ?></span>
                            <?php if ($hasBlackout): ?>
                                <span class="frs-bo-cal-chip"><?= htmlspecialchars($chipText); ?></span>
                            <?php endif; ?>
                        <?php if ($hasBlackout): ?>
                        </a>
                        <?php else: ?>
                        </div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>

            <?php if (empty($monthBlackouts)): ?>
                <div class="px-6 pb-8 text-center border-t border-slate-100">
                    <p class="text-sm text-slate-500 py-4">No blackouts this month. Click "Add Blackout" to create one.</p>
                </div>
            <?php endif; ?>

            <div id="bo-day-modal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4 sm:p-6<?= $selectedDate === '' ? ' hidden' : ''; ?>"
                role="dialog" aria-modal="true" aria-labelledby="bo-day-modal-title"
                data-close-url="<?= htmlspecialchars(blackout_filter_url($filterYear, $calMonth, $filterFacility)); ?>">
                <div class="absolute inset-0 bg-slate-900/50" data-bo-modal-close></div>
                <div class="relative w-full max-w-lg max-h-[90vh] overflow-auto rounded-2xl border border-slate-200 bg-white shadow-xl">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 id="bo-day-modal-title" class="text-lg font-semibold text-slate-900">Blackout details</h3>
                            <?php if ($selectedDate !== ''): ?>
                            <p class="text-sm text-slate-500 mt-0.5"><?= htmlspecialchars($selectedDateLabel); ?></p>
                            <?php endif; ?>
                        </div>
                        <a href="<?= htmlspecialchars(blackout_filter_url($filterYear, $calMonth, $filterFacility)); ?>"
                            data-frs-partial="bo-calendar"
                            class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-500 hover:bg-slate-50 hover:text-slate-700"
                            aria-label="Close">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    </div>
                    <div class="px-5 py-4 space-y-3">
                        <?php if ($selectedDate === ''): ?>
                            <p class="text-sm text-slate-500">Select a highlighted date on the calendar.</p>
                        <?php elseif (empty($dayBlackouts)): ?>
                            <p class="text-sm text-slate-500">No blackouts on this date for the current filter.</p>
                        <?php else: ?>
                            <?php foreach ($dayBlackouts as $b):
                                $b = frs_blackout_enrich_row($b);
                                $isCimm = ($b['source_type'] ?? '') === 'cimm';
                                $isIpms = ($b['source_type'] ?? '') === 'ipms';
                            ?>
                                <article class="rounded-xl border p-4 <?= $isCimm
                                    ? 'border-amber-200 bg-amber-50/60'
                                    : ($isIpms ? 'border-sky-200 bg-sky-50/60' : 'border-slate-200 bg-slate-50/50'); ?>">
                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($b['facility_name']); ?></p>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?= $isCimm
                                            ? 'bg-amber-200 text-amber-900'
                                            : ($isIpms ? 'bg-sky-200 text-sky-900' : 'bg-red-100 text-red-800'); ?>">
                                            <?= htmlspecialchars($b['source_label']); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-slate-700 mt-1"><?= htmlspecialchars($b['display_reason'] ?? '—'); ?></p>
                                    <?php if ($isCimm): ?>
                                        <p class="text-xs text-amber-800/80 mt-2">
                                            Synced from CIMM. Completing or cancelling the maintenance schedule in CIMM removes this automatically.
                                        </p>
                                    <?php elseif ($isIpms): ?>
                                        <p class="text-xs text-sky-800/80 mt-2">
                                            Synced from IPMS. This clears automatically once IPMS marks the project completed or cancelled.
                                        </p>
                                    <?php elseif (!empty($b['created_by_name'])): ?>
                                        <p class="text-xs text-slate-400 mt-2">Added by <?= htmlspecialchars($b['created_by_name']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($b['created_at'])): ?>
                                        <p class="text-xs text-slate-400">Recorded <?= htmlspecialchars(date('M j, Y g:i A', strtotime($b['created_at']))); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($b['is_removable'])): ?>
                                    <form method="POST" class="mt-3" onsubmit="return confirm('Remove this blackout?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="blackout_id" value="<?= (int)$b['id']; ?>">
                                        <input type="hidden" name="bo_year" value="<?= (int)$filterYear; ?>">
                                        <input type="hidden" name="bo_month" value="<?= (int)$calMonth; ?>">
                                        <input type="hidden" name="bo_facility_id" value="<?= (int)$filterFacility; ?>">
                                        <button type="submit"
                                            class="inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">
                                            Remove blackout
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <a href="<?= htmlspecialchars($base . '/dashboard/maintenance-integration'); ?>"
                                       class="inline-flex items-center mt-3 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-50">
                                        View in Maintenance Integration
                                    </a>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            </div>
        </section>

        <!-- Add Blackout Modal -->
        <div id="bo-add-modal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4 sm:p-6 hidden"
            role="dialog" aria-modal="true" aria-labelledby="bo-add-modal-title">
            <div class="absolute inset-0 bg-slate-900/50" id="bo-add-modal-backdrop"></div>
            <div class="relative w-full max-w-5xl max-h-[90vh] overflow-auto rounded-2xl border border-slate-200 bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 id="bo-add-modal-title" class="text-lg font-semibold text-slate-900">Add blackout</h3>
                        <p class="text-sm text-slate-500 mt-0.5">Block days when a facility cannot be booked</p>
                    </div>
                    <button type="button" id="bo-add-modal-close" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-500 hover:bg-slate-50 hover:text-slate-700"
                        aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="border-b border-slate-100 px-4 sm:px-6 pt-5 pb-0">
                    <div class="calendar-tabs frs-bo-tabs mb-1" role="tablist" aria-label="Blackout type">
                        <button type="button" id="bo-tab-single" role="tab" aria-selected="true" aria-controls="bo-panel-single" class="active">
                            Single day
                        </button>
                        <button type="button" id="bo-tab-range" role="tab" aria-selected="false" aria-controls="bo-panel-range">
                            Date range
                        </button>
                    </div>
                </div>

                <div id="bo-panel-single" role="tabpanel" class="p-4 sm:p-6">
                    <form method="POST" class="booking-form book-facility-compact frs-bo-form-grid grid grid-cols-1 gap-4 items-end">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="bo_year" value="<?= (int)$filterYear; ?>">
                        <input type="hidden" name="bo_month" value="<?= (int)$calMonth; ?>">
                        <input type="hidden" name="bo_facility_id" value="<?= (int)$filterFacility; ?>">
                        <label>
                            Facility
                            <div class="input-wrapper">
                                <select name="facility_id" required>
                                    <option value="">Select facility...</option>
                                    <?php foreach ($facilities as $f): ?>
                                        <option value="<?= (int)$f['id']; ?>"><?= htmlspecialchars($f['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </label>
                        <label>
                            Date
                            <div class="input-wrapper">
                                <input type="date" name="blackout_date" required min="<?= $yearMin; ?>" max="<?= $yearMax; ?>">
                            </div>
                        </label>
                        <label>
                            Reason
                            <div class="input-wrapper">
                                <select name="reason_preset">
                                    <?php foreach ($reasonPresets as $preset): ?>
                                        <option value="<?= htmlspecialchars($preset); ?>"><?= htmlspecialchars($preset); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </label>
                        <label>
                            Custom note <span style="font-weight:400;color:#8b95b5;">(optional)</span>
                            <div class="input-wrapper">
                                <input type="text" name="reason_custom" maxlength="255" placeholder="e.g. Foundation Day program">
                            </div>
                        </label>
                        <div class="flex justify-end frs-bo-submit-wrap">
                            <button type="submit" class="btn-primary inline-flex w-full sm:w-auto items-center justify-center gap-2">
                                <i class="bi bi-calendar-x"></i> Add blackout
                            </button>
                        </div>
                    </form>
                </div>

                <div id="bo-panel-range" role="tabpanel" class="hidden p-4 sm:p-6 border-t border-slate-100">
                    <p class="text-xs text-slate-500 mb-4">Block every day between two dates (max 366 days)&mdash;ideal for week-long events.</p>
                    <form method="POST" class="booking-form book-facility-compact frs-bo-form-grid grid grid-cols-1 gap-4 items-end">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="add_range">
                        <input type="hidden" name="bo_year" value="<?= (int)$filterYear; ?>">
                        <input type="hidden" name="bo_month" value="<?= (int)$calMonth; ?>">
                        <input type="hidden" name="bo_facility_id" value="<?= (int)$filterFacility; ?>">
                        <label>
                            Facility
                            <div class="input-wrapper">
                                <select name="facility_id" required>
                                    <option value="">Select facility...</option>
                                    <?php foreach ($facilities as $f): ?>
                                        <option value="<?= (int)$f['id']; ?>"><?= htmlspecialchars($f['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </label>
                        <label>
                            From
                            <div class="input-wrapper">
                                <input type="date" name="start_date" required min="<?= $yearMin; ?>" max="<?= $yearMax; ?>">
                            </div>
                        </label>
                        <label>
                            To
                            <div class="input-wrapper">
                                <input type="date" name="end_date" required min="<?= $yearMin; ?>" max="<?= $yearMax; ?>">
                            </div>
                        </label>
                        <label>
                            Reason
                            <div class="input-wrapper">
                                <select name="reason_preset">
                                    <?php foreach ($reasonPresets as $preset): ?>
                                        <option value="<?= htmlspecialchars($preset); ?>"><?= htmlspecialchars($preset); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </label>
                        <label>
                            Custom note <span style="font-weight:400;color:#8b95b5;">(optional)</span>
                            <div class="input-wrapper">
                                <input type="text" name="reason_custom" maxlength="255" placeholder="e.g. Sports fest">
                            </div>
                        </label>
                        <div class="flex justify-end frs-bo-submit-wrap">
                            <button type="submit" class="btn-primary inline-flex w-full sm:w-auto items-center justify-center gap-2">
                                <i class="bi bi-calendar-range"></i> Add date range
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <p class="mt-4 text-xs text-slate-500 leading-relaxed">
            <strong class="text-slate-600">CPRF blackouts</strong> are events or closures you add here (red on the calendar).
            <strong class="text-amber-800">CIMM maintenance</strong> dates are synced automatically from the maintenance system (amber) and cannot be removed manually.
            Both block bookings on the
            <a href="<?= htmlspecialchars($base . '/dashboard/book-facility'); ?>" class="text-blue-600 hover:underline font-medium">Book a Facility</a>
            calendar.
            Manage CIMM sync on
            <a href="<?= htmlspecialchars($base . '/dashboard/maintenance-integration'); ?>" class="text-blue-600 hover:underline font-medium">Maintenance Integration</a>.
        </p>
    <?php endif; ?>
</div>


<style>
.frs-blackout-page .book-facility-compact.frs-bo-form-grid.booking-form label { margin-bottom: 0; }
.frs-blackout-page .book-facility-compact.frs-bo-form-grid.booking-form .input-wrapper { margin-top: 0.35rem; }
.frs-blackout-page .frs-bo-tabs.calendar-tabs { margin-top: 0; margin-bottom: 0; }
.frs-blackout-page .frs-bo-year-total {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.35rem;
}
.frs-blackout-page .frs-bo-filter-form {
    flex: 1;
    min-width: 0;
}
@media (max-width: 639px) {
    .frs-blackout-page .frs-bo-filter-form {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        width: 100%;
    }
    .frs-blackout-page .frs-bo-filter-facility {
        grid-column: 1 / -1;
    }
    .frs-blackout-page .frs-bo-toolbar-filters {
        flex-direction: column;
        align-items: stretch;
    }
}
.frs-bo-dow-short { display: none; }
@media (max-width: 639px) {
    .frs-bo-dow-full { display: none; }
    .frs-bo-dow-short { display: inline; }
    .frs-bo-calendar-dow { font-size: 0.65rem; padding: 0.15rem 0; }
    .frs-bo-calendar-grid .frs-bo-cal-cell {
        min-height: 3.25rem;
        padding: 0.2rem 0.25rem;
        border-radius: 0.375rem;
    }
    .frs-bo-calendar-grid .frs-bo-cal-cell .bo-day-num {
        font-size: 0.75rem;
    }
    .frs-bo-cal-chip {
        font-size: 0.55rem;
        padding: 0.1rem 0.3rem;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .frs-bo-cal-nav a {
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
    }
    .frs-bo-month-label {
        font-size: 0.85rem;
        max-width: 7rem;
        text-align: center;
        line-height: 1.2;
    }
    .frs-bo-nav-label {
        display: none;
    }
    .frs-blackout-page header h1 {
        font-size: 1.35rem;
    }
}
.frs-bo-calendar-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
@media (max-width: 639px) {
    .frs-bo-calendar-grid {
        min-width: 280px;
        gap: 0.2rem;
    }
}
.frs-blackout-page #bo-add-modal label {
    display: block;
    width: 100%;
}
.frs-blackout-page #bo-add-modal .input-wrapper {
    width: 100%;
}
.frs-blackout-page #bo-add-modal .input-wrapper input,
.frs-blackout-page #bo-add-modal .input-wrapper select {
    width: 100%;
    padding: 0.75rem 1rem;
    min-height: 2.75rem;
    box-sizing: border-box;
    font-size: 0.95rem;
}
.frs-bo-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0.35rem;
}
.frs-bo-calendar-head { margin-bottom: 0.35rem; }
.frs-bo-calendar-dow {
    text-align: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--bo-dow-fg, #64748b);
    padding: 0.25rem 0;
}
.frs-bo-calendar-grid .frs-bo-cal-cell {
    min-height: 4.5rem;
    border-radius: 0.5rem;
    border: 1px solid var(--bo-cell-border, #e2e8f0);
    padding: 0.35rem 0.5rem;
    display: flex;
    flex-direction: column;
    text-align: left;
    text-decoration: none;
    color: inherit;
    background: var(--bo-cell-bg, #fff);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-blocked {
    background: var(--bo-cell-blocked-bg, #fef2f2);
    border-color: var(--bo-cell-blocked-border, #fecaca);
    cursor: pointer;
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-blocked:hover {
    background: var(--bo-cell-blocked-hover, #fee2e2);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-cimm {
    background: var(--bo-cell-cimm-bg, #fffbeb);
    border-color: var(--bo-cell-cimm-border, #fcd34d);
    cursor: pointer;
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-cimm:hover {
    background: var(--bo-cell-cimm-hover, #fef3c7);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-ipms {
    background: var(--bo-cell-ipms-bg, #f0f9ff);
    border-color: var(--bo-cell-ipms-border, #7dd3fc);
    cursor: pointer;
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-ipms:hover {
    background: var(--bo-cell-ipms-hover, #e0f2fe);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-mixed {
    background: linear-gradient(135deg, #fef2f2 50%, #fffbeb 50%);
    border-color: #f59e0b;
    cursor: pointer;
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-cimm .frs-bo-cal-chip {
    background: var(--bo-chip-cimm-bg, rgba(251, 191, 36, 0.35));
    color: var(--bo-chip-cimm-fg, #92400e);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-ipms .frs-bo-cal-chip {
    background: var(--bo-chip-ipms-bg, rgba(125, 211, 252, 0.35));
    color: var(--bo-chip-ipms-fg, #075985);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-today {
    box-shadow: 0 0 0 2px var(--bo-today-ring, #3b82f6);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-empty {
    border-color: transparent;
    background: transparent;
    min-height: 4.5rem;
}
.frs-bo-calendar-grid .frs-bo-cal-cell .bo-day-num {
    color: var(--bo-day-num-fg, #1e293b);
}
.frs-bo-calendar-grid .frs-bo-cal-cell.is-today .bo-day-num {
    color: var(--bo-today-num-fg, #1d4ed8);
}
.frs-bo-cal-chip {
    margin-top: auto;
    align-self: flex-start;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    background: var(--bo-chip-bg, rgba(254, 202, 202, 0.85));
    color: var(--bo-chip-fg, #7f1d1d);
    line-height: 1.2;
}
@media (min-width: 640px) {
    .frs-bo-calendar-grid { gap: 0.5rem; }
    .frs-bo-calendar-grid .frs-bo-cal-cell { min-height: 5.5rem; }
}
</style>
<script>
(function () {
    function mountBoDayModal() {
        const partialRoot = document.querySelector('[data-frs-partial-id="bo-calendar"]');
        const inPartial = partialRoot ? partialRoot.querySelector('#bo-day-modal') : null;
        document.querySelectorAll('#bo-day-modal').forEach(function (el) {
            if (inPartial && el !== inPartial) {
                el.remove();
            }
        });
        const modal = inPartial || document.getElementById('bo-day-modal');
        if (!modal) return null;
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        return modal;
    }

    function initBoTabs() {
        const tabSingle = document.getElementById('bo-tab-single');
        const tabRange = document.getElementById('bo-tab-range');
        const panelSingle = document.getElementById('bo-panel-single');
        const panelRange = document.getElementById('bo-panel-range');
        if (!tabSingle || !tabRange || !panelSingle || !panelRange) return;
        if (tabSingle.dataset.boBound === '1') return;
        tabSingle.dataset.boBound = '1';
        tabRange.dataset.boBound = '1';

        function setTab(active) {
            const isSingle = active === 'single';
            panelSingle.classList.toggle('hidden', !isSingle);
            panelRange.classList.toggle('hidden', isSingle);
            tabSingle.setAttribute('aria-selected', isSingle ? 'true' : 'false');
            tabRange.setAttribute('aria-selected', !isSingle ? 'true' : 'false');
            tabSingle.classList.toggle('active', isSingle);
            tabRange.classList.toggle('active', !isSingle);
        }
        tabSingle.addEventListener('click', function () { setTab('single'); });
        tabRange.addEventListener('click', function () { setTab('range'); });
    }

    function initBoAddModal() {
        const addModalBtn = document.getElementById('bo-add-modal-btn');
        const addModal = document.getElementById('bo-add-modal');
        const addModalBackdrop = document.getElementById('bo-add-modal-backdrop');
        const addModalClose = document.getElementById('bo-add-modal-close');
        if (!addModal) return;

        if (addModal.parentNode !== document.body) {
            document.body.appendChild(addModal);
        }

        if (addModalBtn && addModalBtn.dataset.boBound !== '1') {
            addModalBtn.dataset.boBound = '1';
            addModalBtn.addEventListener('click', function () {
                const modal = document.getElementById('bo-add-modal');
                if (modal) {
                    if (modal.parentNode !== document.body) document.body.appendChild(modal);
                    modal.classList.remove('hidden');
                    document.body.style.overflow = 'hidden';
                }
            });
        }
        if (addModalClose && addModalClose.dataset.boBound !== '1') {
            addModalClose.dataset.boBound = '1';
            addModalClose.addEventListener('click', function () {
                const modal = document.getElementById('bo-add-modal');
                if (modal) {
                    modal.classList.add('hidden');
                    document.body.style.overflow = '';
                }
            });
        }
        if (addModalBackdrop && addModalBackdrop.dataset.boBound !== '1') {
            addModalBackdrop.dataset.boBound = '1';
            addModalBackdrop.addEventListener('click', function () {
                const modal = document.getElementById('bo-add-modal');
                if (modal) {
                    modal.classList.add('hidden');
                    document.body.style.overflow = '';
                }
            });
        }
    }

    function initBoDayModal() {
        mountBoDayModal();
    }

    window.frsInitBlackoutPage = function () {
        initBoTabs();
        initBoAddModal();
        initBoDayModal();
    };

    if (!window.__boPageDelegationBound) {
        window.__boPageDelegationBound = true;
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            const addModal = document.getElementById('bo-add-modal');
            if (addModal && !addModal.classList.contains('hidden')) {
                addModal.classList.add('hidden');
                document.body.style.overflow = '';
                return;
            }
            const dayModal = document.getElementById('bo-day-modal');
            if (dayModal && !dayModal.classList.contains('hidden')) {
                const closeUrl = dayModal.getAttribute('data-close-url');
                if (closeUrl && typeof window.frsPartialLoad === 'function') {
                    window.frsPartialLoad(closeUrl, 'bo-calendar');
                } else if (closeUrl) {
                    window.location.href = closeUrl;
                }
            }
        });

        document.addEventListener('click', function (e) {
            const closeEl = e.target.closest('[data-bo-modal-close]');
            if (!closeEl) return;
            const dayModal = document.getElementById('bo-day-modal');
            if (!dayModal) return;
            e.preventDefault();
            const closeUrl = closeEl.getAttribute('data-frs-partial-url')
                || dayModal.getAttribute('data-close-url');
            if (closeUrl && typeof window.frsPartialLoad === 'function') {
                window.frsPartialLoad(closeUrl, 'bo-calendar');
            } else if (closeUrl) {
                window.location.href = closeUrl;
            }
        });

        document.addEventListener('frs:partial-loaded', function (e) {
            if (e.detail && e.detail.id === 'bo-calendar') {
                mountBoDayModal();
            }
        });

        document.addEventListener('frs:dashboard-page-loaded', function () {
            if (typeof window.frsInitBlackoutPage === 'function') {
                window.frsInitBlackoutPage();
            }
        });
    }

    window.frsInitBlackoutPage();
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
