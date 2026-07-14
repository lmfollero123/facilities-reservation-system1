<?php
/** Shared My Reservations calendar + modals (hub include). */
$__frsMineOnBookHub = !empty($GLOBALS['frsMineCalOnHubBookFacility']);
$__frsMineMod = $__frsMineOnBookHub ? ['module' => 'mine'] : [];
$__mineCalQ = static function (array $q) use ($__frsMineMod): string {
    return '?' . http_build_query(array_merge($__frsMineMod, $q));
};
$__mineCalPath = $__frsMineOnBookHub
    ? (base_path() . '/dashboard/book-facility')
    : (base_path() . '/dashboard/my-reservations');
$hubUserRole = (string)($_SESSION['role'] ?? 'Resident');
$hubStaffView = in_array($hubUserRole, ['Admin', 'Staff'], true);
$calendarScope = (isset($_GET['scope']) && $_GET['scope'] === 'all' && $hubStaffView) ? 'all' : 'mine';
$hubMineDetailUrl = static function (int $reservationId) use ($__mineCalPath, $__frsMineOnBookHub): string {
    if ($__frsMineOnBookHub) {
        return base_path() . '/dashboard/book-facility?module=mine&reservation_id=' . $reservationId;
    }
    return base_path() . '/dashboard/my-reservations?reservation_id=' . $reservationId;
};
?>
<!-- Calendar View for My Reservations -->
<style>
.my-reservations-calendar {
    background: var(--bg-secondary, #ffffff);
    border-radius: 12px;
    border: 1px solid var(--border-color, #e5e7eb);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    min-height: 60vh;
    min-width: 0;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
}
.my-reservations-calendar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}
.my-reservations-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0.35rem;
    flex: 1;
    min-width: 0;
    width: 100%;
    max-width: 100%;
    grid-auto-rows: minmax(90px, 1fr);
}
.my-reservations-calendar-dayname {
    font-size: 0.8rem;
    font-weight: 600;
    color: #6b7280;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
}
.my-reservations-calendar-cell {
    min-height: 64px;
    border-radius: 10px;
    padding: 0.25rem 0.35rem;
    font-size: 0.8rem;
    position: relative;
    cursor: pointer;
    transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
}
.my-reservations-calendar-cell:hover {
    background: rgba(37, 99, 235, 0.06);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
    transform: translateY(-1px);
}
.my-reservations-calendar-cell.empty {
    cursor: default;
    background: transparent;
    box-shadow: none;
}
.my-reservations-calendar-cell .date-label {
    font-weight: 700;
    margin-bottom: 0.25rem;
    color: #0f172a;
}
[data-theme="dark"] .my-reservations-calendar-cell .date-label {
    color: #e5e7eb;
}
.my-reservations-calendar-cell.today .date-label {
    color: #1d4ed8;
}
.my-reservations-calendar-cell .status-chip {
    margin-top: auto;
    align-self: flex-start;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    background: rgba(15,23,42,0.1);
    color: #0f172a;
}
[data-theme="dark"] .my-reservations-calendar-cell .status-chip {
    background: rgba(15,23,42,0.4);
    color: #e5e7eb;
}
.my-reservations-calendar-cell.status-approved {
    background: #dcfce7;
    border-color: #bbf7d0;
}
.my-reservations-calendar-cell.status-pending {
    background: #fef9c3;
    border-color: #fde68a;
}
.my-reservations-calendar-cell.status-denied {
    background: #fee2e2;
    border-color: #fecaca;
}
.my-reservations-calendar-cell.status-past {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    opacity: 0.92;
}
.my-reservations-calendar-cell.status-past .date-label {
    color: #64748b;
}
.my-reservations-calendar-cell.status-past .status-chip {
    background: #e2e8f0;
    color: #475569;
}
[data-theme="dark"] .my-reservations-calendar-cell.status-approved {
    background: rgba(22,163,74,0.25);
    border-color: rgba(34,197,94,0.8);
}
[data-theme="dark"] .my-reservations-calendar-cell.status-pending {
    background: rgba(234,179,8,0.15);
    border-color: rgba(234,179,8,0.7);
}
[data-theme="dark"] .my-reservations-calendar-cell.status-denied {
    background: rgba(220,38,38,0.2);
    border-color: rgba(248,113,113,0.9);
}
[data-theme="dark"] .my-reservations-calendar-cell.status-past {
    background: rgba(100,116,139,0.2);
    border-color: rgba(148,163,184,0.5);
}
.my-reservations-res-card.is-past {
    border-color: #e2e8f0 !important;
    background: #f8fafc;
    opacity: 0.95;
}
.my-reservations-res-card.is-past .res-past-badge {
    background: #e2e8f0;
    color: #475569;
}
.my-reservations-legend {
    display:flex;
    flex-wrap:wrap;
    gap:0.5rem 1rem;
    font-size:0.8rem;
    color:#6b7280;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
    margin-bottom: 0.65rem;
}
.my-reservations-legend-item {
    display:flex;
    align-items:center;
    gap:0.35rem;
}
.my-reservations-legend-dot {
    width:10px;
    height:10px;
    border-radius:999px;
}
.mine-cal-nav-row {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
}
.mine-cal-jump-form {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin: 0;
}
.mine-cal-month-select,
.mine-cal-year-select {
    padding: 0.4rem 0.55rem;
    border: 1px solid var(--border-color, #dbe3ef);
    border-radius: 8px;
    background: var(--bg-secondary, #fff);
    font-size: 0.88rem;
    color: var(--text-primary, #1e293b);
    cursor: pointer;
}
.mine-cal-month-select:hover,
.mine-cal-year-select:hover {
    border-color: #94a3b8;
}
.mine-cal-month-select:focus,
.mine-cal-year-select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}
.mine-cal-nav-btn {
    text-decoration: none;
    padding: 0.4rem 0.75rem;
    border-radius: 8px;
    font-size: 0.88rem;
    white-space: nowrap;
}
@media (max-width: 640px) {
    .my-reservations-calendar {
        padding: 0.75rem;
        min-height: 0;
        margin-bottom: 1rem;
        border-radius: 10px;
    }
    .my-reservations-calendar-grid {
        gap: 0.15rem;
        grid-auto-rows: minmax(46px, auto);
    }
    .my-reservations-calendar-dayname {
        font-size: 0.65rem;
        padding: 0.15rem 0;
    }
    .my-reservations-calendar-cell {
        min-height: 46px;
        padding: 0.2rem 0.15rem 0.35rem;
        border-radius: 7px;
    }
    .my-reservations-calendar-cell .date-label {
        font-size: 0.72rem;
        margin-bottom: 0.1rem;
    }
    .my-reservations-calendar-cell .status-chip {
        max-width: 100%;
        padding: 0.05rem 0.25rem;
        font-size: 0.55rem;
        line-height: 1.15;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .my-reservations-calendar-cell .status-chip[data-chip-short] {
        font-size: 0;
        line-height: 0;
    }
    .my-reservations-calendar-cell .status-chip[data-chip-short]::after {
        content: attr(data-chip-short);
        font-size: 0.55rem;
        line-height: 1.15;
        font-weight: 700;
    }
    .my-reservations-legend {
        flex-wrap: wrap;
        gap: 0.4rem 0.75rem;
        overflow-x: visible;
        padding-bottom: 0.25rem;
        margin-bottom: 0.5rem;
        font-size: 0.72rem;
        width: 100%;
        max-width: 100%;
    }
    .my-reservations-legend-item {
        flex: 0 1 auto;
        min-width: 0;
        white-space: normal;
    }
    .my-reservations-legend-item.my-reservations-legend-demand,
    .my-reservations-legend-item[style*="margin-left"] {
        margin-left: 0 !important;
        border-left: none !important;
        padding-left: 0 !important;
    }
    .my-reservations-legend-dot {
        width: 8px;
        height: 8px;
    }
    .mine-cal-nav-row {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem;
        align-items: stretch;
    }
    .mine-cal-jump-form {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem;
        width: 100%;
        min-width: 0;
    }
    .mine-cal-month-select,
    .mine-cal-year-select {
        min-width: 0;
        width: 100%;
        font-size: 0.8rem;
        padding: 0.4rem 0.45rem;
    }
    .mine-cal-nav-btn {
        padding: 0.4rem 0.35rem;
        font-size: 0.75rem;
        text-align: center;
        justify-content: center;
        min-width: 0;
        white-space: nowrap;
    }
    .mine-cal-nav-btn:last-child {
        grid-column: 1 / -1;
    }
    .my-reservations-calendar-header {
        flex-direction: column;
        align-items: stretch;
        gap: 0.65rem;
        width: 100%;
        max-width: 100%;
    }
    .my-reservations-calendar-header > * {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }
    .my-reservations-calendar-grid {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
        gap: 0.1rem;
    }
    .my-reservations-calendar-grid > * {
        min-width: 0;
        max-width: 100%;
        box-sizing: border-box;
    }
    .mine-scope-tabs {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        width: 100%;
    }
    .mine-scope-tabs a {
        flex: 1 1 auto;
        text-align: center;
        font-size: 0.8rem !important;
        padding: 0.45rem 0.55rem !important;
    }
    .my-reservations-res-card,
    .mine-day-modal__sheet {
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .mine-action-modal .modal-dialog {
        width: 95% !important;
        max-width: 95% !important;
        padding: 1.1rem !important;
        margin: 0.75rem;
        max-height: 90dvh;
        overflow-y: auto;
    }
    .mine-action-modal .modal-dialog > div[style*="display: flex"] {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .mine-action-modal .modal-dialog .btn-primary,
    .mine-action-modal .modal-dialog .btn-outline {
        flex: 1 1 auto;
        min-height: 44px;
        text-align: center;
        justify-content: center;
    }

    /* Day reservations modal — stack cards for phone */
    .mine-day-modal {
        padding: 0 !important;
        align-items: flex-end !important;
    }
    .mine-day-modal__sheet {
        max-width: 100% !important;
        max-height: 92dvh !important;
        border-radius: 16px 16px 0 0 !important;
        padding: 1rem !important;
    }
    .mine-day-modal__header {
        flex-wrap: wrap;
        gap: 0.65rem;
    }
    .my-reservations-res-card__top {
        flex-direction: column;
        align-items: stretch !important;
        gap: 0.65rem !important;
    }
    .my-reservations-res-card__info {
        min-width: 0 !important;
        width: 100%;
    }
    .my-reservations-res-card__meta {
        width: 100%;
        justify-content: flex-start;
    }
    .my-reservations-res-card__actions {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        width: 100%;
    }
    .my-reservations-res-card__actions-row {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        width: 100%;
    }
    .my-reservations-res-card__actions-row > .btn-outline,
    .my-reservations-res-card__actions-row > .btn-primary,
    .my-reservations-res-card__actions-row > a.btn-primary {
        width: 100%;
        justify-content: center;
        text-align: center;
        box-sizing: border-box;
    }
}
</style>
<?php
// ===== Calendar month navigation (prev/next) — prefixed vars so book hub calendar does not collide =====
$mineTabCalYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$mineTabCalMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
if ($mineTabCalMonth < 1) $mineTabCalMonth = 1;
if ($mineTabCalMonth > 12) $mineTabCalMonth = 12;
if ($mineTabCalYear < 2020 || $mineTabCalYear > 2100) $mineTabCalYear = (int)date('Y');

$mineTabMonthTs = mktime(0, 0, 0, $mineTabCalMonth, 1, $mineTabCalYear);
$mineTabDaysInMonth = (int)date('t', $mineTabMonthTs);
$mineTabFirstWeekday = (int)date('w', $mineTabMonthTs); // 0=Sun
$mineTabMonthLabel = date('F Y', $mineTabMonthTs);

$mineTabRangeStart = date('Y-m-01', $mineTabMonthTs);
$mineTabRangeEnd = date('Y-m-t', $mineTabMonthTs);

// Fetch reservations for this month based on selected scope.
// scope=mine => own reservations only
// scope=all  => all users' reservations (including mine)
$calendarReservations = [];
try {
    if ($calendarScope === 'all') {
        $calStmt = $pdo->prepare(
            "SELECT r.id, r.user_id, r.facility_id, r.reservation_date, r.time_slot, r.purpose, r.expected_attendees, r.status, r.reschedule_count,
                    f.name AS facility_name, f.status AS facility_status, f.capacity_threshold, f.operating_hours, f.base_rate,
                    u.name AS requester_name
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             JOIN users u ON r.user_id = u.id
             WHERE r.reservation_date BETWEEN :start AND :end
             ORDER BY r.reservation_date ASC, r.time_slot ASC"
        );
        $calStmt->execute([
            'start' => $mineTabRangeStart,
            'end' => $mineTabRangeEnd,
        ]);
    } else {
        $calStmt = $pdo->prepare(
            "SELECT r.id, r.user_id, r.facility_id, r.reservation_date, r.time_slot, r.purpose, r.expected_attendees, r.status, r.reschedule_count,
                    f.name AS facility_name, f.status AS facility_status, f.capacity_threshold, f.operating_hours, f.base_rate,
                    u.name AS requester_name
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             JOIN users u ON r.user_id = u.id
             WHERE r.user_id = :uid AND r.reservation_date BETWEEN :start AND :end
             ORDER BY r.reservation_date ASC, r.time_slot ASC"
        );
        $calStmt->execute([
            'uid' => $userId,
            'start' => $mineTabRangeStart,
            'end' => $mineTabRangeEnd,
        ]);
    }
    $calendarReservations = $calStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $calendarReservations = [];
}

// Group reservations by date for quick lookup
$mineTabByDate = [];
foreach ($calendarReservations as $reservation) {
    $d = $reservation['reservation_date'];
    $mineTabByDate[$d][] = $reservation;
}

require_once __DIR__ . '/../../../../../config/reservation_documents.php';
$mineReservationDocsById = frs_list_reservation_documents_for_ids(
    array_map(static fn ($r) => (int)($r['id'] ?? 0), $calendarReservations)
);

// Selected date (opens the day modal)
$selectedDate = $_GET['selected_date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$selectedDate)) {
    $selectedDate = '';
}
$dayReservations = $selectedDate ? ($mineTabByDate[$selectedDate] ?? []) : [];
$selectedDateLabel = $selectedDate ? date('F j, Y', strtotime($selectedDate)) : '';

// Prev/Next month links
$mineTabPrevMonthTs = strtotime('-1 month', $mineTabMonthTs);
$mineTabNextMonthTs = strtotime('+1 month', $mineTabMonthTs);
$mineTabPrevYear = (int)date('Y', $mineTabPrevMonthTs);
$mineTabPrevMonthNum = (int)date('m', $mineTabPrevMonthTs);
$mineTabNextYear = (int)date('Y', $mineTabNextMonthTs);
$mineTabNextMonthNum = (int)date('m', $mineTabNextMonthTs);
$mineTabYearMin = (int)date('Y') - 5;
$mineTabYearMax = (int)date('Y') + 5;
?>

<div data-frs-partial-id="mine-calendar" data-frs-partial-root>
<script type="application/json" id="mine-cal-reservation-data"><?= json_encode($calendarReservations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
<script type="application/json" id="mine-cal-docs-data"><?= json_encode($mineReservationDocsById, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
<div class="my-reservations-calendar">
    <div class="my-reservations-calendar-header">
        <div class="mine-scope-tabs" style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.5rem;">
            <a class="<?= $calendarScope === 'mine' ? 'btn-primary' : 'btn-outline'; ?>" style="text-decoration:none; padding:0.35rem 0.7rem; border-radius:8px;" data-frs-partial="mine-calendar" href="<?= htmlspecialchars($__mineCalPath . $__mineCalQ(['scope' => 'mine', 'year' => $mineTabCalYear, 'month' => $mineTabCalMonth]), ENT_QUOTES, 'UTF-8'); ?>">
                My Current Reservations
            </a>
            <?php if ($hubStaffView): ?>
            <a class="<?= $calendarScope === 'all' ? 'btn-primary' : 'btn-outline'; ?>" style="text-decoration:none; padding:0.35rem 0.7rem; border-radius:8px;" data-frs-partial="mine-calendar" href="<?= htmlspecialchars($__mineCalPath . $__mineCalQ(['scope' => 'all', 'year' => $mineTabCalYear, 'month' => $mineTabCalMonth]), ENT_QUOTES, 'UTF-8'); ?>">
                All Reservations
            </a>
            <?php endif; ?>
        </div>
        <div class="mine-cal-nav-row">
            <form method="get" action="<?= htmlspecialchars($__mineCalPath, ENT_QUOTES, 'UTF-8'); ?>" class="mine-cal-jump-form" data-frs-partial="mine-calendar" data-frs-partial-auto>
                <?php if ($__frsMineOnBookHub): ?>
                    <input type="hidden" name="module" value="mine">
                <?php endif; ?>
                <input type="hidden" name="scope" value="<?= htmlspecialchars($calendarScope, ENT_QUOTES, 'UTF-8'); ?>">
                <select name="month" class="mine-cal-month-select" aria-label="Select month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m; ?>" <?= $mineTabCalMonth === $m ? 'selected' : ''; ?>>
                            <?= date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select name="year" class="mine-cal-year-select" aria-label="Select year">
                    <?php for ($y = $mineTabYearMax; $y >= $mineTabYearMin; $y--): ?>
                        <option value="<?= $y; ?>" <?= $mineTabCalYear === $y ? 'selected' : ''; ?>><?= $y; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
            <a class="btn-outline mine-cal-nav-btn" data-frs-partial="mine-calendar" href="<?= htmlspecialchars($__mineCalPath . $__mineCalQ(['scope' => $calendarScope, 'year' => $mineTabPrevYear, 'month' => $mineTabPrevMonthNum]), ENT_QUOTES, 'UTF-8'); ?>">&larr; Prev</a>
            <a class="btn-outline mine-cal-nav-btn" data-frs-partial="mine-calendar" href="<?= htmlspecialchars($__mineCalPath . $__mineCalQ(['scope' => $calendarScope, 'year' => $mineTabNextYear, 'month' => $mineTabNextMonthNum]), ENT_QUOTES, 'UTF-8'); ?>">Next &rarr;</a>
            <a class="btn-outline mine-cal-nav-btn" data-frs-partial="mine-calendar" href="<?= htmlspecialchars($__mineCalPath . $__mineCalQ(['scope' => $calendarScope, 'year' => (int)date('Y'), 'month' => (int)date('n')]), ENT_QUOTES, 'UTF-8'); ?>">Today</a>
        </div>
    </div>
    <div class="my-reservations-legend">
        <div class="my-reservations-legend-item">
            <span class="my-reservations-legend-dot" style="background:#22c55e;"></span> Approved
        </div>
        <div class="my-reservations-legend-item">
            <span class="my-reservations-legend-dot" style="background:#eab308;"></span> Pending / Postponed
        </div>
        <div class="my-reservations-legend-item">
            <span class="my-reservations-legend-dot" style="background:#ef4444;"></span> Denied / Cancelled
        </div>
        <div class="my-reservations-legend-item">
            <span class="my-reservations-legend-dot" style="background:#94a3b8;"></span> Past
        </div>
    </div>
    <div class="my-reservations-calendar-grid">
        <?php
        $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        foreach ($dayNames as $dn): ?>
            <div class="my-reservations-calendar-dayname"><?= $dn; ?></div>
        <?php endforeach; ?>

        <?php
        for ($i = 0; $i < $mineTabFirstWeekday; $i++): ?>
            <div class="my-reservations-calendar-cell empty"></div>
        <?php endfor;

        $todayDate = date('Y-m-d');
        for ($day = 1; $day <= $mineTabDaysInMonth; $day++):
            $dateStr = date('Y-m-' . sprintf('%02d', $day), $mineTabMonthTs);
            $cellReservations = $mineTabByDate[$dateStr] ?? [];
            $isToday = ($dateStr === $todayDate);
            // Determine dominant status for the day (for background color)
            $dayStatusClass = '';
            $chipLabel = '';
            $chipShort = '';
            $activeCellReservations = [];
            foreach ($cellReservations as $r) {
                if (!frs_reservation_slot_has_passed((string)$r['reservation_date'], (string)$r['time_slot'])) {
                    $activeCellReservations[] = $r;
                }
            }
            if (!empty($cellReservations) && empty($activeCellReservations)) {
                $dayStatusClass = ' status-past';
                $chipLabel = 'Past';
                $chipShort = 'Past';
            } elseif (!empty($activeCellReservations)) {
                $hasApproved = false;
                $hasPending = false;
                $hasDenied = false;
                foreach ($activeCellReservations as $r) {
                    $s = strtolower($r['status']);
                    if ($s === 'approved') $hasApproved = true;
                    elseif (in_array($s, ['pending_payment', 'pending', 'postponed', 'on_hold'], true)) $hasPending = true;
                    elseif (in_array($s, ['denied', 'cancelled', 'completed'], true)) $hasDenied = true;
                }
                if ($hasApproved) $dayStatusClass = ' status-approved';
                elseif ($hasPending) $dayStatusClass = ' status-pending';
                elseif ($hasDenied) $dayStatusClass = ' status-denied';
                if ($dayStatusClass === ' status-approved') { $chipLabel = 'Approved'; $chipShort = 'OK'; }
                elseif ($dayStatusClass === ' status-pending') { $chipLabel = 'Pending'; $chipShort = 'Pend'; }
                elseif ($dayStatusClass === ' status-denied') { $chipLabel = 'Denied / Cancelled'; $chipShort = 'Done'; }
                else { $chipLabel = 'Mixed'; $chipShort = 'Mix'; }
            }
            $cellHref = '';
            if (!empty($cellReservations)) {
                $cellHref = $__mineCalPath . $__mineCalQ([
                    'scope' => $calendarScope,
                    'year' => $mineTabCalYear,
                    'month' => $mineTabCalMonth,
                    'selected_date' => $dateStr,
                    'open_day_modal' => '1',
                ]);
            }
        ?>
            <div class="my-reservations-calendar-cell<?= $isToday ? ' today' : ''; ?><?= empty($cellHref) ? ' empty' : ''; ?><?= $dayStatusClass; ?>"<?= $cellHref !== '' ? ' data-frs-partial="mine-calendar" data-frs-partial-url="' . htmlspecialchars($cellHref, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                <div class="date-label"><?= $day; ?></div>
                <?php if (!empty($cellReservations) && $chipLabel !== ''): ?>
                    <div class="status-chip" title="<?= htmlspecialchars($chipLabel); ?>"<?= $chipShort !== '' ? ' data-chip-short="' . htmlspecialchars($chipShort, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?= htmlspecialchars($chipLabel); ?></div>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>
<?php if (empty($calendarReservations)): ?>
    <div style="text-align: center; padding: 2.5rem 1.5rem; background: var(--bg-secondary); border-radius: 12px; border: 2px dashed var(--border-color);">
        <div style="font-size: 3rem; margin-bottom: 0.75rem; opacity: 0.5;">📋</div>
        <h2 style="font-size: 1.3rem; margin-bottom: 0.5rem; color: var(--text-primary);">
            No reservations in this month yet.
        </h2>
        <p style="font-size: 1rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
            Book a facility to see it appear on your calendar.
        </p>
        <a href="<?= base_path(); ?>/dashboard/book-facility" class="btn-primary" style="padding: 0.85rem 1.75rem; font-size: 1rem; display: inline-block; text-decoration: none;">
            Book a Facility
        </a>
    </div>
<?php endif; ?>

<!-- Day Reservations Modal (opens when you click a date) -->
<div id="dayReservationsModal" class="mine-day-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center; padding:1.25rem;">
    <div class="mine-day-modal__sheet" style="background: var(--bg-primary, #ffffff); border-radius: 14px; width:100%; max-width: 900px; max-height: 90vh; overflow:auto; padding: 1.25rem;">
        <div class="mine-day-modal__header" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; border-bottom:1px solid var(--border-color,#e5e7eb); padding-bottom:0.85rem; margin-bottom:1rem;">
            <div>
                <div style="font-weight:800; color: var(--text-primary,#0f172a); font-size:1.1rem;">
                    Reservations on <?= htmlspecialchars($selectedDateLabel ?: ''); ?>
                </div>
                <div style="color: var(--text-secondary,#6b7280); font-size:0.9rem;">Manage your reservation from here.</div>
            </div>
            <button type="button" id="closeDayReservationsModal" class="btn-outline" style="padding:0.4rem 0.75rem; border-radius:10px;">Close</button>
        </div>

        <?php if (!$selectedDate): ?>
            <div style="color: var(--text-secondary,#6b7280);">Select a date with reservations from the calendar.</div>
        <?php elseif (empty($dayReservations)): ?>
            <div style="color: var(--text-secondary,#6b7280);">No reservations found for this day.</div>
        <?php else: ?>
            <div style="display:grid; gap:0.85rem;">
                <?php foreach ($dayReservations as $reservation): ?>
                    <?php
                        $isOwnReservation = ((int)($reservation['user_id'] ?? 0) === (int)$userId);
                        $status = strtolower($reservation['status'] ?? 'pending');
                        $slotHasPassed = frs_reservation_slot_has_passed((string)$reservation['reservation_date'], (string)$reservation['time_slot']);
                        $isOngoing = frs_reservation_slot_is_ongoing((string)$reservation['reservation_date'], (string)$reservation['time_slot']);
                        $slotStartedOrPassed = $slotHasPassed || $isOngoing;
                        // Only apply past display to approved reservations
                        $isPastDisplay = $slotHasPassed && $status === 'approved';

                        $statusBg = '#fef9c3'; $statusColor = '#854d0e';
                        if ($status === 'approved') { $statusBg = '#dcfce7'; $statusColor = '#166534'; }
                        elseif (in_array($status, ['denied','cancelled'], true)) { $statusBg = '#fee2e2'; $statusColor = '#991b1b'; }
                        elseif ($status === 'postponed') { $statusBg = '#e0f2fe'; $statusColor = '#075985'; }
                        elseif ($status === 'pending_payment') { $statusBg = '#ffedd5'; $statusColor = '#9a3412'; }
                        if ($isPastDisplay) {
                            $statusBg = '#e2e8f0';
                            $statusColor = '#475569';
                        }

                        $reservationDate = new DateTime($reservation['reservation_date']);
                        $today = new DateTime('today');
                        $daysUntil = $today->diff($reservationDate)->days;
                        $rescheduleCount = (int)($reservation['reschedule_count'] ?? 0);

                        $canReschedule = $isOwnReservation && ($daysUntil >= 3)
                            && $rescheduleCount < 1
                            && in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)
                            && !$slotStartedOrPassed
                            && (($reservation['facility_status'] ?? 'available') === 'available');

                        // Resident can cancel own upcoming: pending-payment, pending, approved, or postponed
                        $canCancel = $isOwnReservation && in_array($reservation['status'], ['pending_payment', 'pending', 'approved', 'postponed'], true) && !$slotHasPassed;

                        $canEditDetails = $isOwnReservation && in_array($reservation['status'], ['pending', 'approved', 'postponed'], true) && !$slotHasPassed;
                        $canPayNow = $isOwnReservation && (($reservation['status'] ?? '') === 'pending_payment');
                    ?>
                    <div class="my-reservations-res-card<?= $isPastDisplay ? ' is-past' : ''; ?>" style="border:1px solid var(--border-color,#e5e7eb); border-radius:12px; padding:1rem;">
                        <div class="my-reservations-res-card__top" style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                            <div class="my-reservations-res-card__info">
                                <div style="font-weight:800; color: var(--text-primary,#0f172a);"><?= htmlspecialchars($reservation['facility_name'] ?? 'Facility'); ?></div>
                                <div style="color: var(--text-secondary,#6b7280); margin-top:0.15rem;">
                                    <?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?>
                                </div>
                                <?php if ($calendarScope === 'all' && $canViewOtherReservationDetails): ?>
                                    <div style="margin-top:0.2rem; color:#64748b; font-size:0.82rem;">
                                        Booked by: <?= $isOwnReservation ? 'You' : htmlspecialchars((string)($reservation['requester_name'] ?? 'Resident')); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="my-reservations-res-card__meta" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                <span class="<?= $isPastDisplay ? 'res-past-badge' : ''; ?>" style="display:inline-flex; align-items:center; gap:0.35rem; padding:0.25rem 0.65rem; border-radius:999px; background:<?= $statusBg; ?>; color:<?= $statusColor; ?>; font-weight:800; font-size:0.85rem;">
                                    <?php if ($calendarScope === 'all' && !$isOwnReservation && !$canViewOtherReservationDetails): ?>
                                        Reserved
                                    <?php elseif ($isPastDisplay): ?>
                                        Past · <?= ucfirst(str_replace('_', ' ', $status)); ?>
                                    <?php else: ?>
                                        <?= ucfirst(str_replace('_', ' ', $status)); ?>
                                    <?php endif; ?>
                                </span>
                                <?php if (!($calendarScope === 'all' && !$isOwnReservation && !$canViewOtherReservationDetails)): ?>
                                    <button type="button" class="btn-outline" style="padding:0.4rem 0.75rem; border-radius:10px;" onclick="openReservationDetailModal(<?= (int)$reservation['id']; ?>)">View details</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!($calendarScope === 'all' && !$isOwnReservation && !$canViewOtherReservationDetails)): ?>
                            <details style="margin-top:0.75rem;">
                                <summary style="cursor:pointer; font-weight:700; color: var(--gov-blue,#2563eb);">View Details</summary>
                                <div style="margin-top:0.6rem; color: var(--text-secondary,#6b7280);">
                                    <div><strong style="color: var(--text-primary,#0f172a);">Purpose:</strong> <?= htmlspecialchars($reservation['purpose'] ?? ''); ?></div>
                                    <div style="margin-top:0.25rem;"><strong style="color: var(--text-primary,#0f172a);">Expected attendees:</strong> <?= htmlspecialchars((string)($reservation['expected_attendees'] ?? 'N/A')); ?></div>
                                    <?php
                                    $mineDocs = $mineReservationDocsById[(int)($reservation['id'] ?? 0)] ?? [];
                                    if ($isOwnReservation && !empty($mineDocs)):
                                    ?>
                                    <div style="margin-top:0.65rem;">
                                        <strong style="color: var(--text-primary,#0f172a);">Supporting documents:</strong>
                                        <ul style="margin:0.35rem 0 0; padding:0; list-style:none; display:flex; flex-direction:column; gap:0.35rem;">
                                            <?php foreach ($mineDocs as $mdoc):
                                                $mdocId = (int)($mdoc['id'] ?? 0);
                                            ?>
                                            <li>
                                                <a href="<?= htmlspecialchars(frs_reservation_document_download_url($mdocId, 'view'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" style="color: var(--gov-blue,#2563eb); font-weight:600; text-decoration:none;">
                                                    <?= htmlspecialchars(frs_reservation_document_type_label((string)($mdoc['document_type'] ?? 'other'))); ?>
                                                </a>
                                                <span style="color:#94a3b8; font-size:0.82rem;"> — <?= htmlspecialchars((string)($mdoc['file_name'] ?? '')); ?></span>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                        <div class="my-reservations-res-card__actions" style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.85rem;">
                            <div class="my-reservations-res-card__actions-row" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <?php if ($canPayNow): ?>
                                    <a href="<?= base_path(); ?>/dashboard/pay-now?reservation_id=<?= (int)$reservation['id']; ?>"
                                        class="btn-primary"
                                        style="padding:0.45rem 0.8rem; border-radius:10px; text-decoration:none;">
                                        Pay Now
                                    </a>
                                <?php endif; ?>
                                <?php if ($canCancel): ?>
                                    <button type="button"
                                        class="btn-outline"
                                        style="border-color:#dc3545; color:#dc3545; padding:0.45rem 0.8rem; border-radius:10px;"
                                        onclick="openCancelReservationModal(<?= (int)$reservation['id']; ?>, '<?= htmlspecialchars($reservation['facility_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', '<?= date('F j, Y', strtotime($reservation['reservation_date'])); ?>', '<?= htmlspecialchars($reservation['time_slot'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');">
                                        Cancel
                                    </button>
                                <?php endif; ?>

                                <?php if ($canEditDetails): ?>
                                    <button type="button"
                                        class="btn-outline"
                                        style="padding:0.45rem 0.8rem; border-radius:10px;"
                                        onclick="openEditDetailsModal(this)"
                                        data-id="<?= (int)$reservation['id']; ?>"
                                        data-purpose="<?= htmlspecialchars($reservation['purpose'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-attendees="<?= htmlspecialchars((string)($reservation['expected_attendees'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-capacity-threshold="<?= (int)($reservation['capacity_threshold'] ?? 0); ?>">
                                        Edit
                                    </button>
                                <?php endif; ?>

                                <?php if ($canReschedule): ?>
                                    <button type="button"
                                        class="btn-primary"
                                        style="padding:0.45rem 0.8rem; border-radius:10px;"
                                        onclick="openRescheduleModal(this)"
                                        data-id="<?= (int)$reservation['id']; ?>"
                                        data-facility-id="<?= (int)($reservation['facility_id'] ?? 0); ?>"
                                        data-date="<?= htmlspecialchars($reservation['reservation_date']); ?>"
                                        data-time="<?= htmlspecialchars($reservation['time_slot'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-facility="<?= htmlspecialchars($reservation['facility_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-operating-hours="<?= htmlspecialchars($reservation['operating_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        Reschedule
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php if ($calendarScope === 'all' && !$isOwnReservation && !$canViewOtherReservationDetails): ?>
                                <div class="info-message info-warning" style="margin-top:0.25rem;">
                                    <span class="info-icon">ℹ️</span>
                                    <span class="info-text">This slot is reserved. Personal booking details are hidden for privacy.</span>
                                </div>
                            <?php elseif (!$isOwnReservation): ?>
                                <div class="info-message info-warning" style="margin-top:0.25rem;">
                                    <span class="info-icon">ℹ️</span>
                                    <span class="info-text">You can view this reservation, but only the owner can manage it.</span>
                                </div>
                            <?php elseif ($isOngoing && in_array($reservation['status'], ['pending', 'approved'], true)): ?>
                                <div class="info-message info-error" style="margin-top:0.25rem;">
                                    <span class="info-icon">⚠️</span>
                                    <span class="info-text">This reservation has already started and cannot be rescheduled or cancelled.</span>
                                </div>
                            <?php elseif (($reservation['status'] ?? '') === 'pending_payment'): ?>
                                <div class="info-message info-warning" style="margin-top:0.25rem;">
                                    <span class="info-icon">💳</span>
                                    <span class="info-text">Please complete payment first to unlock edit and reschedule actions.</span>
                                </div>
                            <?php elseif (!in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)): ?>
                                <div class="info-message info-error" style="margin-top:0.25rem;">
                                    <span class="info-icon">ℹ️</span>
                                    <span class="info-text">
                                        <?php if ($reservation['status'] === 'denied'): ?>
                                            Rejected reservations cannot be rescheduled. Please create a new reservation request.
                                        <?php elseif ($reservation['status'] === 'cancelled'): ?>
                                            Cancelled reservations cannot be rescheduled. Please create a new reservation request.
                                        <?php else: ?>
                                            This reservation cannot be updated due to its current status.
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php elseif (($reservation['reschedule_count'] ?? 0) >= 1): ?>
                                <div class="info-message info-warning" style="margin-top:0.25rem;">
                                    <span class="info-icon">⚠️</span>
                                    <span class="info-text">This reservation has already been rescheduled once. Only one reschedule is allowed per reservation.</span>
                                </div>
                            <?php elseif ($daysUntil < 3 && in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)): ?>
                                <div class="info-message info-warning" style="margin-top:0.25rem;">
                                    <span class="info-icon">⏰</span>
                                    <span class="info-text">Rescheduling is only allowed up to 3 days before the event. (<?= $daysUntil; ?> day(s) remaining)</span>
                                </div>
                            <?php elseif (($reservation['facility_status'] ?? 'available') !== 'available' && in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)): ?>
                                <div class="info-message info-warning" style="margin-top:0.25rem;">
                                    <span class="info-icon">🔧</span>
                                    <span class="info-text">The facility is currently <?= htmlspecialchars($reservation['facility_status']); ?> and rescheduling is not available. Please contact support.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Reservation Detail Modal (wide layout matches Confirm Booking; above day modal) -->
<div id="reservationDetailModal" class="modal-confirm" style="display: none; opacity: 0; visibility: hidden; z-index: 100020; position: fixed; inset: 0;">
    <div class="modal-dialog booking-confirm-dialog" style="z-index: 100021;">
        <div class="booking-confirm-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--gov-blue), var(--gov-blue-dark)); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-info-circle" style="font-size: 1.5rem; color: white;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; color: var(--gov-blue-dark); font-size: 1.25rem; font-weight: 700;">Reservation Details</h3>
                    <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.875rem;">View your booking information</p>
                </div>
            </div>
            <button type="button" onclick="closeReservationDetailModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; padding: 0.5rem; line-height: 1;" aria-label="Close">&times;</button>
        </div>

        <div class="booking-confirm-grid">
            <div class="booking-confirm-card">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-building" style="color: var(--gov-blue);"></i>
                    <span>Facility & Schedule</span>
                </div>
                <div class="booking-confirm-fields">
                    <div class="booking-confirm-field booking-confirm-field--full">
                        <span class="booking-confirm-label">Facility</span>
                        <span id="detail-facility" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Date</span>
                        <span id="detail-date" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Duration</span>
                        <span id="detail-duration" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field booking-confirm-field--full">
                        <span class="booking-confirm-label">Time</span>
                        <span id="detail-time" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field booking-confirm-field--full">
                        <span class="booking-confirm-label">Status</span>
                        <span id="detail-status" class="booking-confirm-value"></span>
                    </div>
                </div>
            </div>

            <div class="booking-confirm-card">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-file-text" style="color: var(--gov-blue);"></i>
                    <span>Event Details</span>
                </div>
                <div class="booking-confirm-fields booking-confirm-fields--single">
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Purpose</span>
                        <span id="detail-purpose" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Attendees</span>
                        <span id="detail-attendees" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Notes</span>
                        <span id="detail-notes" class="booking-confirm-value"></span>
                    </div>
                </div>
            </div>

            <div class="booking-confirm-card">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-paperclip" style="color: var(--gov-blue);"></i>
                    <span>Supporting Document</span>
                </div>
                <div class="booking-confirm-fields booking-confirm-fields--single">
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Type</span>
                        <span id="detail-doc-type" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">File</span>
                        <span id="detail-doc-file" class="booking-confirm-value"></span>
                    </div>
                </div>
            </div>

            <div class="booking-confirm-card booking-confirm-card--cost">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-currency-peso"></i>
                    <span>Cost Breakdown</span>
                </div>
                <div class="booking-confirm-fields booking-confirm-fields--single">
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Rate per Hour</span>
                        <span id="detail-rate" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Hours</span>
                        <span id="detail-hours" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field" style="margin-top: 0.35rem; padding-top: 0.65rem; border-top: 1px solid rgba(255,255,255,0.25);">
                        <span class="booking-confirm-label">Total Cost</span>
                        <span id="detail-total" class="booking-confirm-value booking-confirm-total"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="booking-confirm-actions">
            <button type="button" class="btn-outline" onclick="closeReservationDetailModal()">Close</button>
        </div>
    </div>
</div>

<script>
(function(){
    const detailModal = document.getElementById('reservationDetailModal');
    if (detailModal && detailModal.parentNode !== document.body) {
        document.body.appendChild(detailModal);
    }

    function closeReservationDetailModal() {
        const modal = document.getElementById('reservationDetailModal');
        if (modal) {
            modal.style.display = 'none';
            modal.style.opacity = '0';
            modal.style.visibility = 'hidden';
            modal.classList.remove('open');
        }
        document.body.style.overflow = '';
        document.body.style.removeProperty('overflow');
    }
    window.closeReservationDetailModal = closeReservationDetailModal;

    function formatTime(time24) {
        const [hours, minutes] = time24.split(':');
        const hour = parseInt(hours, 10);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const hour12 = hour % 12 || 12;
        return `${hour12}:${minutes} ${ampm}`;
    }

    function readMineCalJson(id) {
        const el = document.getElementById(id);
        if (!el || !el.textContent) return null;
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }

    window.openReservationDetailModal = function(reservationId) {
        const reservationData = readMineCalJson('mine-cal-reservation-data') || [];
        const reservation = reservationData.find(function (r) { return Number(r.id) === Number(reservationId); });

        if (!reservation) {
            alert('Reservation not found');
            return;
        }

        const timeParts = reservation.time_slot.split(' - ');
        if (timeParts.length !== 2) {
            alert('Invalid time slot format');
            return;
        }

        const startTime = new Date('2000-01-01T' + timeParts[0]);
        const endTime = new Date('2000-01-01T' + timeParts[1]);
        const durationMs = endTime - startTime;
        const durationHours = durationMs / (1000 * 60 * 60);

        const ratePerHour = parseFloat(reservation.base_rate) || 0;
        const totalCost = ratePerHour * durationHours;

        document.getElementById('detail-facility').textContent = reservation.facility_name;
        document.getElementById('detail-date').textContent = new Date(reservation.reservation_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        document.getElementById('detail-time').textContent = formatTime(timeParts[0]) + ' - ' + formatTime(timeParts[1]);
        document.getElementById('detail-duration').textContent = durationHours.toFixed(1) + ' hour(s)';
        document.getElementById('detail-status').textContent = reservation.status.charAt(0).toUpperCase() + reservation.status.slice(1);
        document.getElementById('detail-purpose').textContent = reservation.purpose;
        document.getElementById('detail-attendees').textContent = reservation.expected_attendees;
        document.getElementById('detail-notes').textContent = 'N/A';

        const docs = readMineCalJson('mine-cal-docs-data') || {};
        const reservationDocs = docs[reservationId] || [];
        if (reservationDocs.length > 0) {
            document.getElementById('detail-doc-type').textContent = reservationDocs[0].document_type || 'None';
            document.getElementById('detail-doc-file').textContent = reservationDocs[0].file_name || 'None';
        } else {
            document.getElementById('detail-doc-type').textContent = 'None';
            document.getElementById('detail-doc-file').textContent = 'None';
        }

        document.getElementById('detail-rate').textContent = '₱' + ratePerHour.toFixed(2);
        document.getElementById('detail-hours').textContent = durationHours.toFixed(1);
        document.getElementById('detail-total').textContent = '₱' + totalCost.toFixed(2);

        const modal = document.getElementById('reservationDetailModal');
        if (modal && modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        if (modal) {
            modal.style.zIndex = '100020';
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.visibility = 'visible';
            modal.classList.add('open');
        }
        document.body.style.overflow = 'hidden';
    };

    document.addEventListener('frs:partial-loaded', function (e) {
        if (e.detail && e.detail.id === 'mine-calendar') {
            const params = new URLSearchParams(window.location.search);
            if (params.get('open_day_modal') === '1' && params.get('selected_date')) {
                /* handled by frs-partial-update.js */
            }
        }
    });
})();
</script>

<!-- Cancel Reservation Confirmation Modal -->
<div id="cancelReservationModal" class="modal mine-action-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 100010; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: var(--bg-primary); border-radius: 8px; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="color: var(--text-primary); margin: 0;">Cancel Reservation</h3>
            <button type="button" onclick="closeCancelReservationModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        <p id="cancelReservationSummary" style="color: var(--text-secondary); margin-bottom: 1rem;"></p>
        <div style="margin-bottom: 1rem; padding: 1rem; background: var(--bg-secondary); border-radius: 6px; border-left: 4px solid #f59e0b;">
            <strong style="color: var(--text-primary);">⚠️ Cancellation policy</strong>
            <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0; font-size: 0.9rem; color: var(--text-secondary);">
                <li>You can cancel <strong>upcoming</strong> reservations (pending, approved, or postponed).</li>
                <li>If you already paid, a refund will be issued automatically when possible.</li>
                <li>Free facility bookings do not involve payment or refund.</li>
                <li>Once cancelled, the time slot becomes available for others.</li>
                <li>Past or already-started reservations cannot be cancelled.</li>
            </ul>
        </div>
        <form method="POST" id="cancelReservationForm">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="cancel_reservation">
            <input type="hidden" name="reservation_id" id="cancel_reservation_id">
            <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                <button type="button" class="btn-outline" onclick="closeCancelReservationModal()" style="flex: 1;">Keep Reservation</button>
                <button type="submit" class="btn-primary" style="flex: 1; background: #dc3545; border-color: #dc3545;">Cancel Reservation</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Purpose / Attendees Modal -->
<div id="editDetailsModal" class="modal mine-action-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 100010; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Purpose / Attendees</h3>
            <button onclick="closeEditDetailsModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="editDetailsForm" data-capacity-threshold="0">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="edit_details">
            <input type="hidden" name="reservation_id" id="edit_details_reservation_id">
            <div style="margin-bottom: 1rem; padding: 1rem; background: #e8f5e9; border-radius: 6px; border-left: 4px solid #4caf50;">
                <strong>ℹ️ No approval needed</strong> unless attendee count exceeds the facility&apos;s capacity threshold.
            </div>
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Purpose / Event Description <span style="color: #dc3545;">*</span></label>
            <textarea name="purpose" id="edit_details_purpose" required placeholder="Purpose or event description" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem; min-height: 80px; font-family: inherit; resize: vertical;"></textarea>
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Expected Attendees</label>
            <input type="number" name="expected_attendees" id="edit_details_expected_attendees" min="0" placeholder="Optional" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            <small id="edit_details_capacity_warning" style="display: none; color: #f59e0b; margin-top: -0.5rem; margin-bottom: 1rem; font-size: 0.85rem;"></small>
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeEditDetailsModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary" style="flex: 1;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Request Reschedule Modal -->
<div id="rescheduleModal" class="modal mine-action-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 100010; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Request Reschedule</h3>
            <button onclick="closeRescheduleModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="rescheduleForm">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="reservation_id" id="reschedule_reservation_id">
            <input type="hidden" id="reschedule_facility_id" value="">
            <input type="hidden" id="reschedule_current_date" value="">
            <input type="hidden" id="reschedule_current_time_slot" value="">
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #e3f2fd; border-radius: 6px; border-left: 4px solid #2196f3;">
                <strong>ℹ️ Request Reschedule Policy:</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem; padding: 0; font-size: 0.9rem;">
                    <li>Requests allowed up to <strong>3 days before</strong> the event (same-day not allowed)</li>
                    <li>Only <strong>one reschedule request</strong> per reservation</li>
                    <li>Staff will <strong>review and apply changes</strong> upon approval</li>
                    <li>Approved/postponed reservations will require re-approval after staff applies the change</li>
                    <li>Reservations that have <strong>already started</strong> cannot be rescheduled</li>
                    <li>Rejected or cancelled reservations cannot be rescheduled</li>
                </ul>
            </div>
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #f5f5f5; border-radius: 6px;">
                <strong>Current Schedule:</strong>
                <div style="margin-top: 0.5rem;">
                    <span id="reschedule_current_schedule"></span>
                </div>
            </div>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Date <span style="color: #dc3545;">*</span>
            </label>
            <input type="date" name="new_date" id="reschedule_new_date" required min="<?= date('Y-m-d'); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Start Time <span style="color: #dc3545;">*</span>
            </label>
            <select name="start_time" id="reschedule_start_time" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="">Select start time...</option>
                <?php for ($hour = 8; $hour <= 21; $hour++): for ($minute = 0; $minute < 60; $minute += 30): if ($hour == 21 && $minute > 0) break; $tv = sprintf('%02d:%02d', $hour, $minute); $td = date('g:i A', strtotime($tv)); ?>
                <option value="<?= $tv; ?>"><?= $td; ?></option>
                <?php endfor; endfor; ?>
            </select>
            <small id="reschedule_start_help" style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:-0.5rem; margin-bottom:1rem;">Facility operating hours: 8:00 AM - 9:00 PM</small>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                End Time <span style="color: #dc3545;">*</span>
            </label>
            <select name="end_time" id="reschedule_end_time" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="">Select end time...</option>
                <?php for ($hour = 8; $hour <= 21; $hour++): for ($minute = 0; $minute < 60; $minute += 30): if ($hour == 21 && $minute > 0) break; $tv = sprintf('%02d:%02d', $hour, $minute); $td = date('g:i A', strtotime($tv)); ?>
                <option value="<?= $tv; ?>"><?= $td; ?></option>
                <?php endfor; endfor; ?>
            </select>
            <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:-0.5rem; margin-bottom:1rem;">Must be after start time. Minimum 30 minutes.</small>
            <input type="hidden" name="new_time_slot" id="reschedule_new_time_slot">
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason for Reschedule Request <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="reschedule_reason" required placeholder="Enter the reason for your reschedule request (e.g., schedule conflict, change of plans, etc.)" style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>
            
            <div id="reschedule-conflict-warning" style="display:none; border-radius:8px; padding:1rem; margin-top:1rem; transition: all 0.3s ease;">
                <div id="reschedule-conflict-header" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                    <span id="reschedule-conflict-icon" style="font-size:1.2rem;">⏳</span>
                    <h4 id="reschedule-conflict-title" style="margin:0; font-size:0.95rem;">Checking Availability...</h4>
                </div>
                <p id="reschedule-conflict-message" style="margin:0 0 0.75rem; font-size:0.85rem;"></p>
                <div id="reschedule-conflict-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; font-size:0.85rem; font-weight:600;">Alternative time slots:</p>
                    <ul id="reschedule-alternatives-list" style="margin:0; padding-left:1.25rem; font-size:0.85rem;"></ul>
                </div>
                <p id="reschedule-conflict-risk" style="margin:0; font-size:0.82rem; display:none;"></p>
            </div>
            
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeRescheduleModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Submit reschedule request? Staff will review and apply changes upon approval." style="flex: 1;">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
(function mountMineActionModals() {
    ['cancelReservationModal', 'editDetailsModal', 'rescheduleModal'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });
})();

// Edit Details Modal
function openEditDetailsModal(btn) {
    const modal = document.getElementById('editDetailsModal');
    if (modal && modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    const id = btn.getAttribute('data-id');
    const purpose = btn.getAttribute('data-purpose') || '';
    const attendees = btn.getAttribute('data-attendees') || '';
    const capacityThreshold = parseInt(btn.getAttribute('data-capacity-threshold') || '0', 10);
    document.getElementById('edit_details_reservation_id').value = id;
    document.getElementById('edit_details_purpose').value = purpose;
    document.getElementById('edit_details_expected_attendees').value = attendees;
    document.getElementById('editDetailsForm').setAttribute('data-capacity-threshold', capacityThreshold);
    document.getElementById('edit_details_capacity_warning').style.display = 'none';
    document.getElementById('editDetailsModal').style.display = 'flex';
}
function closeEditDetailsModal() {
    document.getElementById('editDetailsModal').style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function () {
    const editAttendeesEl = document.getElementById('edit_details_expected_attendees');
    if (editAttendeesEl) {
        editAttendeesEl.addEventListener('input', function () {
            const threshold = parseInt(document.getElementById('editDetailsForm').getAttribute('data-capacity-threshold') || '0', 10);
            const val = parseInt(this.value, 10);
            const warn = document.getElementById('edit_details_capacity_warning');
            if (!warn) return;
            if (threshold > 0 && !isNaN(val) && val > threshold) {
                warn.textContent = 'Note: ' + val + ' attendees exceeds facility threshold (' + threshold + '). Re-approval will be required.';
                warn.style.display = 'block';
            } else {
                warn.style.display = 'none';
            }
        });
    }
    const editDetailsMo = document.getElementById('editDetailsModal');
    if (editDetailsMo) {
        editDetailsMo.addEventListener('click', function (e) {
            if (e.target === this) closeEditDetailsModal();
        });
    }
});

// Reschedule Modal - parse operating hours (same logic as book facility)
function parseOperatingHoursReschedule(operatingHours) {
    if (!operatingHours || operatingHours.trim() === '') return { start: 8, end: 21 };
    const hours = operatingHours.trim();
    const match24 = hours.match(/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/);
    if (match24) return { start: parseInt(match24[1]), end: parseInt(match24[3]) };
    const match12 = hours.match(/(\d{1,2}):(\d{2})\s*(AM|PM)\s*-\s*(\d{1,2}):(\d{2})\s*(AM|PM)/i);
    if (match12) {
        let sh = parseInt(match12[1]); const sp = match12[3].toUpperCase();
        let eh = parseInt(match12[4]); const ep = match12[6].toUpperCase();
        if (sp === 'PM' && sh !== 12) sh += 12; if (sp === 'AM' && sh === 12) sh = 0;
        if (ep === 'PM' && eh !== 12) eh += 12; if (ep === 'AM' && eh === 12) eh = 0;
        return { start: sh, end: eh };
    }
    return { start: 8, end: 21 };
}

function filterRescheduleTimeSlots(operatingHours) {
    const { start: openHour, end: closeHour } = parseOperatingHoursReschedule(operatingHours);
    const startSel = document.getElementById('reschedule_start_time');
    const endSel = document.getElementById('reschedule_end_time');
    const helpEl = document.getElementById('reschedule_start_help');
    [startSel, endSel].forEach((sel, idx) => {
        if (!sel) return;
        sel.querySelectorAll('option').forEach(opt => {
            if (opt.value === '') { opt.style.display = ''; opt.disabled = false; return; }
            const [hour, minute] = opt.value.split(':').map(Number);
            const isStart = idx === 0;
            const within = isStart ? (hour >= openHour && hour < closeHour) : (hour >= openHour && hour <= closeHour);
            opt.style.display = within ? '' : 'none';
            opt.disabled = !within;
        });
    });
    if (helpEl) {
        const fmt = h => { const p = h >= 12 ? 'PM' : 'AM'; const h12 = h > 12 ? h - 12 : (h === 0 ? 12 : h); return h12 + ':00 ' + p; };
        helpEl.textContent = 'Facility operating hours: ' + fmt(openHour) + ' - ' + fmt(closeHour);
    }
}

// Filter end time options to only allow times after start time (min 30 min duration) - same logic as book facility
function updateRescheduleEndTimeOptions() {
    const startSel = document.getElementById('reschedule_start_time');
    const endSel = document.getElementById('reschedule_end_time');
    if (!startSel || !endSel) return;
    const startTime = startSel.value;
    if (!startTime) {
        endSel.querySelectorAll('option').forEach(opt => { opt.disabled = false; });
        return;
    }
    const [startH, startM] = startTime.split(':').map(Number);
    const startMinutes = startH * 60 + startM;
    endSel.querySelectorAll('option').forEach(opt => {
        if (opt.value === '') { opt.disabled = false; return; }
        const [endH, endM] = opt.value.split(':').map(Number);
        const endMinutes = endH * 60 + endM;
        opt.disabled = endMinutes <= startMinutes;
    });
    const endVal = endSel.value;
    if (endVal) {
        const [endH, endM] = endVal.split(':').map(Number);
        if (endH * 60 + endM <= startMinutes) endSel.value = '';
    }
}

let rescheduleConflictCheckTimeout = null;

function openRescheduleModal(btn) {
    const modal = document.getElementById('rescheduleModal');
    if (modal && modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    const id = btn.getAttribute('data-id');
    const facilityId = btn.getAttribute('data-facility-id') || '';
    const date = btn.getAttribute('data-date');
    const time = btn.getAttribute('data-time');
    const facility = btn.getAttribute('data-facility');
    const operatingHours = btn.getAttribute('data-operating-hours') || '';
    document.getElementById('reschedule_reservation_id').value = id;
    document.getElementById('reschedule_facility_id').value = facilityId;
    document.getElementById('reschedule_current_date').value = date;
    document.getElementById('reschedule_current_time_slot').value = time;
    document.getElementById('reschedule_current_schedule').textContent = facility + ' on ' + date + ' (' + time + ')';
    document.getElementById('reschedule_new_date').value = date;
    document.getElementById('reschedule_start_time').value = '';
    document.getElementById('reschedule_end_time').value = '';
    document.getElementById('reschedule_new_time_slot').value = '';
    document.getElementById('reschedule_reason').value = '';
    filterRescheduleTimeSlots(operatingHours);
    const timeMatch = time.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
    if (timeMatch) {
        const startVal = timeMatch[1].padStart(2,'0') + ':' + timeMatch[2];
        const endVal = timeMatch[3].padStart(2,'0') + ':' + timeMatch[4];
        const startOpt = document.querySelector('#reschedule_start_time option[value="' + startVal + '"]');
        const endOpt = document.querySelector('#reschedule_end_time option[value="' + endVal + '"]');
        if (startOpt && !startOpt.disabled) document.getElementById('reschedule_start_time').value = startVal;
        updateRescheduleEndTimeOptions();
        if (endOpt && !endOpt.disabled) document.getElementById('reschedule_end_time').value = endVal;
    }
    document.getElementById('rescheduleModal').style.display = 'flex';
    // Trigger conflict check after modal opens (debounced)
    if (rescheduleConflictCheckTimeout) clearTimeout(rescheduleConflictCheckTimeout);
    rescheduleConflictCheckTimeout = setTimeout(checkRescheduleConflict, 300);
}

function closeRescheduleModal() {
    document.getElementById('rescheduleModal').style.display = 'none';
}

function openCancelReservationModal(reservationId, facilityName, dateStr, timeSlot) {
    const modal = document.getElementById('cancelReservationModal');
    if (modal && modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    document.getElementById('cancel_reservation_id').value = reservationId;
    document.getElementById('cancelReservationSummary').textContent =
        'You are about to cancel: ' + facilityName + ' on ' + dateStr + ' (' + timeSlot + ').';
    modal.style.display = 'flex';
}

function closeCancelReservationModal() {
    document.getElementById('cancelReservationModal').style.display = 'none';
}

document.getElementById('cancelReservationModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeCancelReservationModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const openActionModal = [
        ['cancelReservationModal', closeCancelReservationModal],
        ['editDetailsModal', closeEditDetailsModal],
        ['rescheduleModal', closeRescheduleModal],
    ].find(function(entry) {
        const el = document.getElementById(entry[0]);
        return el && el.style.display === 'flex';
    });
    if (openActionModal) {
        e.preventDefault();
        openActionModal[1]();
    }
});

function debounceRescheduleConflict() {
    if (rescheduleConflictCheckTimeout) clearTimeout(rescheduleConflictCheckTimeout);
    rescheduleConflictCheckTimeout = setTimeout(checkRescheduleConflict, 500);
}

async function checkRescheduleConflict() {
    rescheduleConflictCheckTimeout = null;
    const fid = document.getElementById('reschedule_facility_id')?.value;
    const date = document.getElementById('reschedule_new_date')?.value;
    const startTime = document.getElementById('reschedule_start_time')?.value;
    const endTime = document.getElementById('reschedule_end_time')?.value;
    const excludeId = document.getElementById('reschedule_reservation_id')?.value;
    const msgBox = document.getElementById('reschedule-conflict-warning');
    const msgText = document.getElementById('reschedule-conflict-message');
    const altWrap = document.getElementById('reschedule-conflict-alternatives');
    const altList = document.getElementById('reschedule-alternatives-list');
    const riskLine = document.getElementById('reschedule-conflict-risk');
    const conflictIcon = document.getElementById('reschedule-conflict-icon');
    const conflictTitle = document.getElementById('reschedule-conflict-title');

    if (!fid || !date || !startTime || !endTime) {
        if (msgBox) msgBox.style.display = 'none';
        return;
    }
    const [sh, sm] = startTime.split(':').map(Number);
    const [eh, em] = endTime.split(':').map(Number);
    if (eh * 60 + em <= sh * 60 + sm) {
        if (msgBox) msgBox.style.display = 'none';
        return;
    }
    const timeSlot = startTime + ' - ' + endTime;

    if (!msgBox) return;
    msgBox.style.display = 'block';
    msgBox.style.background = '#f0f4ff';
    msgBox.style.border = '2px solid #6366f1';
    if (msgText) msgText.style.color = '#4f46e5';
    if (msgText) msgText.textContent = 'Checking availability and conflicts...';
    if (conflictIcon) conflictIcon.textContent = '⏳';
    if (conflictTitle) { conflictTitle.textContent = 'Checking Availability...'; conflictTitle.style.color = '#4f46e5'; }
    if (altWrap) altWrap.style.display = 'none';
    if (riskLine) riskLine.style.display = 'none';

    const basePath = <?= isset($frsJsonForInlineScript) ? $frsJsonForInlineScript((string) base_path()) : json_encode(base_path()); ?>;
    try {
        let body = `facility_id=${encodeURIComponent(fid)}&date=${encodeURIComponent(date)}&time_slot=${encodeURIComponent(timeSlot)}`;
        if (excludeId) body += `&exclude_reservation_id=${encodeURIComponent(excludeId)}`;
        const resp = await fetch(basePath + '/dashboard/ai-conflict-check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        if (!resp.ok) { msgBox.style.display = 'none'; return; }
        const data = await resp.json();
        if (data.error) { msgBox.style.display = 'none'; return; }

        if (data.has_conflict) {
            msgBox.style.background = '#fdecee';
            msgBox.style.border = '2px solid #b23030';
            if (msgText) { msgText.style.color = '#b23030'; msgText.textContent = data.message || 'This slot is already booked (approved reservation). Please select an alternative time.'; }
            if (conflictIcon) conflictIcon.textContent = '✗';
            if (conflictTitle) { conflictTitle.textContent = 'Conflict Detected'; conflictTitle.style.color = '#b23030'; }
        } else if (data.soft_conflicts && data.soft_conflicts.length > 0) {
            const cnt = data.pending_count || data.soft_conflicts.length;
            msgBox.style.background = '#fff4e5';
            msgBox.style.border = '2px solid #ffc107';
            if (msgText) { msgText.style.color = '#856404'; msgText.textContent = 'Warning: ' + cnt + ' pending reservation(s) exist for this slot. You can still submit, but staff will approve only one based on priority.'; }
            if (conflictIcon) conflictIcon.textContent = '⚠️';
            if (conflictTitle) { conflictTitle.textContent = 'Warning - Pending Reservations'; conflictTitle.style.color = '#856404'; }
        } else {
            msgBox.style.background = '#e8f5e9';
            msgBox.style.border = '2px solid #0d7a43';
            if (msgText) { msgText.style.color = '#0d7a43'; msgText.textContent = '✓ This time slot is available for rescheduling!'; }
            if (conflictIcon) conflictIcon.textContent = '✓';
            if (conflictTitle) { conflictTitle.textContent = 'Available'; conflictTitle.style.color = '#0d7a43'; }
        }
        if (data.alternatives && data.alternatives.length && altWrap && altList) {
            altWrap.style.display = 'block';
            altList.innerHTML = data.alternatives.filter(a => a.available !== false)
                .map(a => '<li><strong>' + (a.display || a.time_slot || '') + '</strong> — ' + (a.recommendation || 'Available') + '</li>').join('');
        }
    } catch (e) {
        if (msgBox) {
            msgBox.style.background = '#fdecee';
            msgBox.style.border = '2px solid #b23030';
        }
        if (msgText) { msgText.style.color = '#b23030'; msgText.textContent = 'Error checking availability. Please try again.'; }
    }
}

document.getElementById('reschedule_new_date')?.addEventListener('change', debounceRescheduleConflict);
document.getElementById('reschedule_start_time')?.addEventListener('change', function () {
    updateRescheduleEndTimeOptions();
    debounceRescheduleConflict();
});
document.getElementById('reschedule_end_time')?.addEventListener('change', function () {
    this.setCustomValidity('');
    debounceRescheduleConflict();
});

document.getElementById('rescheduleForm')?.addEventListener('submit', function (e) {
    const startVal = document.getElementById('reschedule_start_time').value;
    const endVal = document.getElementById('reschedule_end_time').value;
    const newDate = document.getElementById('reschedule_new_date').value;
    const currentDate = document.getElementById('reschedule_current_date').value;
    const currentTimeSlot = document.getElementById('reschedule_current_time_slot').value;
    const endTimeEl = document.getElementById('reschedule_end_time');

    if (startVal && endVal && endTimeEl) {
        const [sh, sm] = startVal.split(':').map(Number);
        const [eh, em] = endVal.split(':').map(Number);
        const startM = sh * 60 + sm;
        const endM = eh * 60 + em;
        if (endM <= startM) {
            e.preventDefault();
            endTimeEl.setCustomValidity('End time must be after start time');
            endTimeEl.reportValidity();
            return false;
        }
        if (endM - startM < 30) {
            e.preventDefault();
            endTimeEl.setCustomValidity('Reservation must be at least 30 minutes');
            endTimeEl.reportValidity();
            return false;
        }
        const newTimeSlot = startVal + ' - ' + endVal;
        const slotHidden = document.getElementById('reschedule_new_time_slot');
        if (slotHidden) slotHidden.value = newTimeSlot;

        // Block if rescheduling to the exact same date and time
        if (newDate === currentDate && newTimeSlot === currentTimeSlot) {
            e.preventDefault();
            endTimeEl.setCustomValidity('');
            alert('You are already scheduled for this date and time. Please select a different date or time slot to reschedule.');
            return false;
        }
    }
});
</script>
