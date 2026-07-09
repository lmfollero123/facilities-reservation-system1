<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'ai_tools')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';

$pdo = db();
$userRole = $_SESSION['role'] ?? 'Resident';
$pageTitle = 'Smart Scheduler | LGU Facilities Reservation';

/** @var array<int, array{id:int,name:string}> */
$schedulerFacilities = [];
try {
    $schedulerFacilities = $pdo->query(
        "SELECT id, name FROM facilities WHERE status != 'deleted' ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $schedulerFacilities = [];
}

$filterFacilityId = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$topSlotsPerDay = 1; // Show top 1 slot per day of week
$maxFacilitySections = 12; // when viewing all facilities, cap how many facility blocks appear

// Holiday / local event calendar (Philippines + Barangay Culiat) for current and next year
$yearNow = (int)date('Y');
$years = [$yearNow, $yearNow + 1];
$holidayList = [];

foreach ($years as $yr) {
    $holidayList["$yr-01-01"] = 'New Year\'s Day';
    $holidayList["$yr-02-25"] = 'EDSA People Power Anniversary';
    $holidayList["$yr-04-09"] = 'Araw ng Kagitingan';
    $holidayList[date('Y-m-d', strtotime("second sunday of May $yr"))] = 'Mother\'s Day';
    $holidayList[date('Y-m-d', strtotime("second sunday of June $yr"))] = 'Father\'s Day';
    $holidayList["$yr-06-12"] = 'Independence Day';
    $holidayList["$yr-08-21"] = 'Ninoy Aquino Day';
    $holidayList["$yr-08-26"] = 'National Heroes Day';
    $holidayList["$yr-11-01"] = 'All Saints\' Day';
    $holidayList["$yr-11-02"] = 'All Souls\' Day';
    $holidayList["$yr-11-30"] = 'Bonifacio Day';
    $holidayList["$yr-12-25"] = 'Christmas Day';
    $holidayList["$yr-12-30"] = 'Rizal Day';

    // Barangay Culiat local events (sample dates; adjust as needed)
    $holidayList["$yr-09-08"] = 'Barangay Culiat Fiesta';
    $holidayList["$yr-02-11"] = 'Barangay Culiat Founding Day';
}

// Look ahead window for tags
$lookaheadDays = 120;
$today = new DateTimeImmutable('today');
$eventTags = [];
for ($i = 0; $i <= $lookaheadDays; $i++) {
    $d = $today->modify("+$i days")->format('Y-m-d');
    if (isset($holidayList[$d])) {
        $eventTags[] = [
            'date' => $d,
            'label' => $holidayList[$d],
            'dow' => date('D', strtotime($d))
        ];
    }
}

// Look back over the last 6 months for patterns
$windowStart = date('Y-m-01', strtotime('-5 months'));
$windowEnd = date('Y-m-t');

// Aggregate reservations by facility, day of week, and time slot
$facilityAggClause = '';
if ($filterFacilityId > 0) {
    $facilityAggClause = ' AND f.id = ' . $filterFacilityId;
}
$slotSql =
    'SELECT f.id AS facility_id, f.name AS facility, DAYNAME(r.reservation_date) AS day_name, r.time_slot, COUNT(*) AS booking_count
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.reservation_date BETWEEN :start AND :end
       AND r.status = "approved"'
    . $facilityAggClause .
    ' GROUP BY f.id, facility_id, facility, day_name, r.time_slot
     ORDER BY booking_count ASC, facility ASC';
$slotStmt = $pdo->prepare($slotSql);
$slotStmt->execute([
    'start' => $windowStart,
    'end' => $windowEnd,
]);
$rawSlots = $slotStmt->fetchAll(PDO::FETCH_ASSOC);

// Build recommended slots: prefer lower booking_count (less conflict)
$slots = [];
foreach ($rawSlots as $row) {
    $count = (int)$row['booking_count'];
    $score = 'Medium';
    $reason = 'Moderate historical usage with room for new events.';

    if ($count <= 1) {
        $score = 'High';
        $reason = 'Very few past bookings; low likelihood of conflict.';
    } elseif ($count >= 4) {
        $score = 'Low';
        $reason = 'Frequent past bookings; expect higher competition for this window.';
    }

    $todayForSlot = new DateTimeImmutable('today');
    $dayName = (string)$row['day_name'];
    $reservationDate = strcasecmp($todayForSlot->format('l'), $dayName) === 0
        ? $todayForSlot->format('Y-m-d')
        : $todayForSlot->modify('next ' . $dayName)->format('Y-m-d');

    $slots[] = [
        'facility' => $row['facility'],
        'facility_id' => $row['facility_id'],
        'day' => $dayName,
        'time' => $row['time_slot'],
        'reservation_date' => $reservationDate,
        'reason' => $reason,
        'score' => $score,
        'count' => $count,
    ];
}

$scoreRank = static function ($score): int {
    return $score === 'High' ? 0 : ($score === 'Medium' ? 1 : 2);
};
usort(
    $slots,
    static function ($a, $b) use ($scoreRank): int {
        $sa = $scoreRank($a['score']);
        $sb = $scoreRank($b['score']);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        if ((int)$a['count'] !== (int)$b['count']) {
            return (int)$a['count'] <=> (int)$b['count'];
        }
        return strcmp((string)$a['facility'], (string)$b['facility']);
    }
);

// Top 1 recommendation per day of week for each facility
$slotsByFacility = [];
foreach ($slots as $slot) {
    $fid = (int)$slot['facility_id'];
    $day = (string)$slot['day'];
    if (!isset($slotsByFacility[$fid])) {
        $slotsByFacility[$fid] = [
            'facility_id' => $fid,
            'facility' => (string)$slot['facility'],
            'slots_by_day' => [],
            'high_count' => 0,
        ];
    }
    if (!isset($slotsByFacility[$fid]['slots_by_day'][$day])) {
        $slotsByFacility[$fid]['slots_by_day'][$day] = $slot;
    } else {
        // Keep the best slot for this day (higher score first, then lower count)
        $existing = $slotsByFacility[$fid]['slots_by_day'][$day];
        $sa = $scoreRank($existing['score']);
        $sb = $scoreRank($slot['score']);
        if ($sb < $sa || ($sb === $sa && (int)$slot['count'] < (int)$existing['count'])) {
            $slotsByFacility[$fid]['slots_by_day'][$day] = $slot;
        }
    }
}

// Flatten slots_by_day into slots array and sort by day order (Monday to Sunday)
$dayOrder = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6];
foreach ($slotsByFacility as $fid => $group) {
    $group['slots'] = array_values($group['slots_by_day']);
    // Sort by day of week
    usort($group['slots'], static function ($a, $b) use ($dayOrder): int {
        $da = $dayOrder[$a['day']] ?? 7;
        $db = $dayOrder[$b['day']] ?? 7;
        return $da <=> $db;
    });
    // Limit to 7 days max
    $group['slots'] = array_slice($group['slots'], 0, 7);
    $group['high_count'] = count(array_filter(
        $group['slots'],
        static fn(array $s): bool => ($s['score'] ?? '') === 'High'
    ));
    unset($group['slots_by_day']);
    $slotsByFacility[$fid] = $group;
}

// Sort facility blocks: more High-tier slots first, then fewer past bookings, then name
uasort(
    $slotsByFacility,
    static function (array $a, array $b): int {
        if ($a['high_count'] !== $b['high_count']) {
            return $b['high_count'] <=> $a['high_count'];
        }
        $aMin = empty($a['slots']) ? PHP_INT_MAX : min(array_column($a['slots'], 'count'));
        $bMin = empty($b['slots']) ? PHP_INT_MAX : min(array_column($b['slots'], 'count'));
        if ($aMin !== $bMin) {
            return $aMin <=> $bMin;
        }
        return strcmp($a['facility'], $b['facility']);
    }
);

if ($filterFacilityId === 0 && count($slotsByFacility) > $maxFacilitySections) {
    $slotsByFacility = array_slice($slotsByFacility, 0, $maxFacilitySections, true);
}

$slotsTotalShown = 0;
foreach ($slotsByFacility as $group) {
    $slotsTotalShown += count($group['slots']);
}
$facilitiesWithRecs = count($slotsByFacility);

// Compute simple insights: peak day and peak time slot across all facilities
$peakDayStmt = $pdo->prepare(
    'SELECT DAYNAME(reservation_date) AS day_name, COUNT(*) AS count
     FROM reservations
     WHERE reservation_date BETWEEN :start AND :end
       AND status = "approved"
     GROUP BY DAYNAME(reservation_date)
     ORDER BY count DESC
     LIMIT 1'
);
$peakDayStmt->execute(['start' => $windowStart, 'end' => $windowEnd]);
$peakDay = $peakDayStmt->fetch(PDO::FETCH_ASSOC);

$peakTimeStmt = $pdo->prepare(
    'SELECT time_slot, COUNT(*) AS count
     FROM reservations
     WHERE reservation_date BETWEEN :start AND :end
       AND status = "approved"
     GROUP BY time_slot
     ORDER BY count DESC
     LIMIT 1'
);
$peakTimeStmt->execute(['start' => $windowStart, 'end' => $windowEnd]);
$peakTime = $peakTimeStmt->fetch(PDO::FETCH_ASSOC);

// Tag upcoming dates with holidays/events to elevate risk
$eventRisk = [];
foreach ($eventTags as $evt) {
    $eventRisk[] = [
        'date' => $evt['date'],
        'label' => $evt['label'],
        'dow' => $evt['dow'],
        'risk' => 'High'
    ];
}

ob_start();
?>
<style>
.ai-scheduler-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0;
}

.ai-page-header {
    margin-bottom: 2rem;
}

.ai-page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 0.5rem;
}

.ai-page-description {
    color: #64748b;
    font-size: 0.95rem;
    margin: 0;
}

.ai-filters-bar {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.ai-filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.ai-filter-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #475569;
}

.ai-filter-select {
    min-width: 250px;
    padding: 0.6rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.9rem;
    background: white;
    color: #1e293b;
    transition: all 0.2s ease;
}

.ai-filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.ai-filter-btn {
    padding: 0.6rem 1.25rem;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ai-filter-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.ai-stats-bar {
    margin-left: auto;
    color: #64748b;
    font-size: 0.85rem;
}

.ai-facility-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    transition: all 0.2s ease;
}

.ai-facility-card:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
}

.ai-facility-header {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ai-facility-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ai-facility-badge {
    background: #dbeafe;
    color: #1e40af;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.ai-slots-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    padding: 1.5rem;
}

.ai-slot-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    transition: all 0.2s ease;
}

.ai-slot-card:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.ai-slot-day {
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.ai-slot-time {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.75rem;
}

.ai-slot-tier {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
}

.ai-slot-tier.high {
    background: #dcfce7;
    color: #166534;
}

.ai-slot-tier.medium {
    background: #fef3c7;
    color: #92400e;
}

.ai-slot-tier.low {
    background: #fee2e2;
    color: #991b1b;
}

.ai-slot-insight {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 1rem;
    line-height: 1.5;
}

.ai-slot-book-btn {
    width: 100%;
    padding: 0.6rem 1rem;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.ai-slot-book-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.ai-empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #64748b;
}

.ai-empty-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #cbd5e1;
}

.ai-holidays-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.ai-holidays-header {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-bottom: 1px solid #fcd34d;
}

.ai-holidays-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #92400e;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ai-holidays-table {
    width: 100%;
    border-collapse: collapse;
}

.ai-holidays-table th {
    text-align: left;
    padding: 1rem 1.5rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.ai-holidays-table td {
    padding: 1rem 1.5rem;
    font-size: 0.9rem;
    color: #1e293b;
    border-bottom: 1px solid #e2e8f0;
}

.ai-holidays-table tr:last-child td {
    border-bottom: none;
}

/* Dark mode */
html[data-theme="dark"] .ai-page-title {
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-page-description {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-filters-bar {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-filter-label {
    color: #cbd5e1;
}

html[data-theme="dark"] .ai-filter-select {
    background: #0f172a;
    border-color: #334155;
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-filter-select:focus {
    border-color: #3b82f6;
}

html[data-theme="dark"] .ai-stats-bar {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-facility-card {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-facility-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-color: #334155;
}

html[data-theme="dark"] .ai-facility-title {
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-facility-badge {
    background: #1e40af;
    color: #dbeafe;
}

html[data-theme="dark"] .ai-slot-card {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-slot-card:hover {
    border-color: #3b82f6;
}

html[data-theme="dark"] .ai-slot-day {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-slot-time {
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-slot-insight {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-holidays-card {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-holidays-header {
    background: linear-gradient(135deg, #78350f 0%, #451a03 100%);
    border-color: #7c2d12;
}

html[data-theme="dark"] .ai-holidays-header h3 {
    color: #fde68a;
}

html[data-theme="dark"] .ai-holidays-table th {
    background: #0f172a;
    color: #94a3b8;
    border-color: #334155;
}

html[data-theme="dark"] .ai-holidays-table td {
    color: #f1f5f9;
    border-color: #334155;
}

html[data-theme="dark"] .ai-empty-icon {
    color: #475569;
}
</style>

<div class="ai-scheduler-container">
    <div class="ai-page-header">
        <h1 class="ai-page-title">Smart Scheduler</h1>
        <p class="ai-page-description">AI-powered recommendations based on historical booking patterns and local events</p>
    </div>

    <!-- Filters -->
    <form method="get" action="" class="ai-filters-bar">
        <div class="ai-filter-group">
            <label class="ai-filter-label">Facility</label>
            <select name="facility_id" class="ai-filter-select">
                <option value="0"<?= $filterFacilityId === 0 ? ' selected' : ''; ?>>All facilities</option>
                <?php foreach ($schedulerFacilities as $sf): ?>
                    <option value="<?= (int)$sf['id']; ?>"<?= $filterFacilityId === (int)$sf['id'] ? ' selected' : ''; ?>>
                        <?= htmlspecialchars((string)$sf['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="ai-filter-btn">Apply Filter</button>
        <?php if ($slotsTotalShown > 0): ?>
            <div class="ai-stats-bar">
                <?= (int)$slotsTotalShown; ?> top slot<?= $slotsTotalShown === 1 ? '' : 's'; ?>
                across <?= (int)$facilitiesWithRecs; ?> facilit<?= $facilitiesWithRecs === 1 ? 'y' : 'ies'; ?>
                (top 1 per day, max 7 days per facility)
            </div>
        <?php endif; ?>
    </form>

    <!-- AI Demand Forecast Card -->
    <div class="ai-facility-card" style="cursor: pointer; margin-bottom: 1.5rem;" onclick="openAIModal()">
        <div class="ai-facility-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-bottom: none;">
            <h3 class="ai-facility-title" style="color: white;">
                <i class="bi bi-lightning-charge-fill"></i>
                AI Demand Forecast
            </h3>
            <span style="color: rgba(255,255,255,0.9); font-size: 0.9rem; font-weight: 500;">View Insights →</span>
        </div>
    </div>

    <!-- Recommended Time Slots -->
    <?php if (empty($slotsByFacility)): ?>
        <div class="ai-empty-state">
            <div class="ai-empty-icon"><i class="bi bi-inbox"></i></div>
            <h3 style="margin: 0 0 0.5rem; font-size: 1.1rem; color: #1e293b;">No recommendations yet</h3>
            <p style="margin: 0;">Not enough historical data to generate recommendations. Try "All facilities" or check back once more approved reservations are recorded.</p>
        </div>
    <?php else: ?>
        <?php foreach ($slotsByFacility as $group): ?>
            <div class="ai-facility-card">
                <div class="ai-facility-header">
                    <h3 class="ai-facility-title">
                        <i class="bi bi-building"></i>
                        <?= htmlspecialchars($group['facility']); ?>
                    </h3>
                    <span class="ai-facility-badge"><?= count($group['slots']); ?> days</span>
                </div>
                <div class="ai-slots-grid">
                    <?php foreach ($group['slots'] as $slot): ?>
                        <div class="ai-slot-card">
                            <div class="ai-slot-day"><?= htmlspecialchars($slot['day']); ?></div>
                            <div class="ai-slot-time"><?= htmlspecialchars($slot['time']); ?></div>
                            <span class="ai-slot-tier <?= strtolower($slot['score']); ?>">
                                <i class="bi bi-<?= $slot['score'] === 'High' ? 'check-circle' : ($slot['score'] === 'Medium' ? 'dash-circle' : 'x-circle'); ?>"></i>
                                <?= htmlspecialchars($slot['score']); ?> Priority
                            </span>
                            <div class="ai-slot-insight">
                                <?= (int)$slot['count']; ?> past booking<?= (int)$slot['count'] === 1 ? '' : 's'; ?>
                            </div>
                            <a class="ai-slot-book-btn" href="<?= base_path(); ?>/dashboard/book-facility?facility_id=<?= urlencode((string)$slot['facility_id']); ?>&reservation_date=<?= urlencode((string)$slot['reservation_date']); ?>&time_slot=<?= urlencode((string)$slot['time']); ?>">
                                <i class="bi bi-calendar-plus"></i> Book This Slot
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($filterFacilityId === 0 && count($slots) > 0): ?>
            <?php
            $allFacilityIdsWithData = [];
            foreach ($slots as $s) {
                $allFacilityIdsWithData[(int)$s['facility_id']] = true;
            }
            $hiddenFacilityCount = count($allFacilityIdsWithData) - $facilitiesWithRecs;
            ?>
            <?php if ($hiddenFacilityCount > 0): ?>
                <p style="color: #64748b; font-size: 0.85rem; text-align: center; margin-top: 1rem;">
                    Showing the <?= (int)$facilitiesWithRecs; ?> facilities with the strongest recommendations.
                    <?= (int)$hiddenFacilityCount; ?> more <?= $hiddenFacilityCount === 1 ? 'has' : 'have'; ?> data — pick a facility above to see its top 7 days.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Upcoming Holidays -->
    <div class="ai-holidays-card" style="margin-top: 2rem;">
        <div class="ai-holidays-header">
            <h3>
                <i class="bi bi-calendar-event"></i>
                Upcoming Holidays & Local Events (Next 120 Days)
            </h3>
        </div>
        <?php if (empty($eventRisk)): ?>
            <div style="padding: 2rem; text-align: center; color: #64748b;">
                No tagged holidays/events in the next 120 days.
            </div>
        <?php else: ?>
            <table class="ai-holidays-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Event</th>
                        <th>Risk Level</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eventRisk as $evt): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($evt['date'])); ?></td>
                            <td><?= htmlspecialchars($evt['dow']); ?></td>
                            <td><?= htmlspecialchars($evt['label']); ?></td>
                            <td><span class="ai-slot-tier high">High Demand</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<!-- AI Demand Forecast Modal -->
<div id="aiForecastModal" class="modal-overlay" style="display: none;" role="dialog" aria-labelledby="aiForecastTitle" aria-modal="true">
    <div class="modal-content ai-forecast-dialog">
        <div class="ai-forecast-header">
            <div>
                <div class="ai-chip" style="margin-bottom: 0.5rem;">
                    <span>AI</span> <span>Demand Forecast</span>
                </div>
                <h2 id="aiForecastTitle">Insights Overview</h2>
            </div>
            <button type="button" class="ai-forecast-close" onclick="closeAIModal()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body ai-forecast-body">
            <ul>
                <?php if ($peakDay): ?>
                    <li><strong>Peak day:</strong> Most approved reservations fall on <strong><?= htmlspecialchars($peakDay['day_name']); ?>s</strong>.</li>
                <?php endif; ?>
                <?php if ($peakTime): ?>
                    <li><strong>Busy time window:</strong> The <strong><?= htmlspecialchars($peakTime['time_slot']); ?></strong> slot sees the highest activity.</li>
                <?php endif; ?>
                <li><strong>Recommendation Scores:</strong>
                    <ul style="margin-top:0.5rem; padding-left:1.5rem; list-style-type:disc;">
                        <li><strong>High</strong> = 0-1 past bookings (low conflict risk, best choice)</li>
                        <li><strong>Medium</strong> = 2-3 past bookings (moderate usage)</li>
                        <li><strong>Low</strong> = 4+ past bookings (high competition, more conflicts)</li>
                    </ul>
                </li>
                <li>Consider scheduling official LGU events on days and time windows with fewer past bookings to reduce conflicts.</li>
                <li>These insights are based on approved reservations from the last 6 months and tagged PH holidays + Barangay Culiat events.</li>
            </ul>
        </div>
        <div class="modal-footer ai-forecast-footer">
            <button type="button" onclick="closeAIModal()" class="btn-primary">Close</button>
        </div>
    </div>
</div>

<style>
#aiForecastModal.modal-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 100000 !important;
    background: rgba(15, 23, 42, 0.55) !important;
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

#aiForecastModal.modal-overlay.show {
    display: flex !important;
}

#aiForecastModal .ai-forecast-dialog,
#aiForecastModal .modal-content.ai-forecast-dialog {
    background: #ffffff !important;
    color: #1e293b !important;
    opacity: 1 !important;
    border: 1px solid #e5e7eb;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
}

#aiForecastModal .ai-forecast-body,
#aiForecastModal .ai-forecast-header,
#aiForecastModal .ai-forecast-footer,
#aiForecastModal .modal-body {
    background: #ffffff !important;
    color: #334155 !important;
}

#aiForecastModal .ai-forecast-body strong {
    color: #1e3a5f !important;
}

/* Dark mode for AI Forecast Modal */
html[data-theme="dark"] #aiForecastModal .ai-forecast-dialog,
html[data-theme="dark"] #aiForecastModal .modal-content.ai-forecast-dialog {
    background: #1e293b !important;
    color: #f1f5f9 !important;
    border-color: #334155;
}

html[data-theme="dark"] #aiForecastModal .ai-forecast-body,
html[data-theme="dark"] #aiForecastModal .ai-forecast-header,
html[data-theme="dark"] #aiForecastModal .ai-forecast-footer,
html[data-theme="dark"] #aiForecastModal .modal-body {
    background: #1e293b !important;
    color: #cbd5e1 !important;
}

html[data-theme="dark"] #aiForecastModal .ai-forecast-body strong {
    color: #f1f5f9 !important;
}

html[data-theme="dark"] #aiForecastModal .ai-forecast-header h2 {
    color: #f1f5f9 !important;
}

html[data-theme="dark"] #aiForecastModal .btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
    color: white !important;
}

.ai-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #ffffff;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
}

.ai-chip span:first-child {
    background: rgba(255, 255, 255, 0.2);
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-weight: 700;
}
</style>

<script>
function openAIModal() {
    const modal = document.getElementById('aiForecastModal');
    if (modal) {
        if (modal.parentNode !== document.body) document.body.appendChild(modal);
        modal.classList.add('show');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Prevent body scroll
    }
}

function closeAIModal() {
    const modal = document.getElementById('aiForecastModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore body scroll
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('aiForecastModal');
    if (modal && e.target === modal) {
        closeAIModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('aiForecastModal');
        if (modal && modal.style.display === 'flex') {
            closeAIModal();
        }
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';




