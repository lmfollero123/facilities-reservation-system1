<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
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
$topSlotsPerFacility = 5;
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

// Top N recommendations per facility (sort within each facility, then take best 5)
$slotsByFacility = [];
foreach ($slots as $slot) {
    $fid = (int)$slot['facility_id'];
    if (!isset($slotsByFacility[$fid])) {
        $slotsByFacility[$fid] = [
            'facility_id' => $fid,
            'facility' => (string)$slot['facility'],
            'slots' => [],
            'high_count' => 0,
        ];
    }
    $slotsByFacility[$fid]['slots'][] = $slot;
}
foreach ($slotsByFacility as $fid => $group) {
    usort(
        $group['slots'],
        static function ($a, $b) use ($scoreRank): int {
            $sa = $scoreRank($a['score']);
            $sb = $scoreRank($b['score']);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            if ((int)$a['count'] !== (int)$b['count']) {
                return (int)$a['count'] <=> (int)$b['count'];
            }
            return strcmp((string)$a['day'], (string)$b['day']);
        }
    );
    $group['slots'] = array_slice($group['slots'], 0, $topSlotsPerFacility);
    $group['high_count'] = count(array_filter(
        $group['slots'],
        static fn(array $s): bool => ($s['score'] ?? '') === 'High'
    ));
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
<div class="page-header">
    <div class="breadcrumb">
        <span>Analytics</span><span class="sep">/</span><span>Smart Scheduler</span>
    </div>
    <?= frs_page_title('Smart Scheduler', 'Suggests low-conflict day/time patterns from the last 6 months of approved bookings, plus local holidays.'); ?>
</div>

<div class="reports-grid">
    <section>
        <!-- AI Demand Forecast Card - Clickable -->
        <div class="report-card" style="cursor: pointer; margin-bottom: 1.5rem;" onclick="openAIModal()">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                <div class="ai-chip">
                    <span>AI</span> <span>Demand Forecast</span>
                </div>
                <span style="color: #285ccd; font-size: 0.9rem; font-weight: 500;">View Insights →</span>
            </div>
        </div>
        
        <!-- Recommended Time Slots -->
        <div class="report-card">
            <?= frs_heading_with_tip(
                'Recommended Time Slots',
                'Up to ' . (int)$topSlotsPerFacility . ' best day/time windows per facility (fewest past conflicts). High = 0–1 past bookings, Medium = 2–3, Low = 4+. Filter by facility to focus on one venue.'
            ); ?>
            <form method="get" action="" style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-end; margin-bottom:1.25rem;">
                <label style="display:flex; flex-direction:column; gap:0.35rem; font-size:0.9rem; color:#3d4f6f;">
                    <span style="font-weight:600;">Facility</span>
                    <select name="facility_id" style="min-width:220px; padding:0.45rem 0.65rem; border-radius:8px; border:1px solid #d8dee9;">
                        <option value="0"<?= $filterFacilityId === 0 ? ' selected' : ''; ?>>All facilities</option>
                        <?php foreach ($schedulerFacilities as $sf): ?>
                            <option value="<?= (int)$sf['id']; ?>"<?= $filterFacilityId === (int)$sf['id'] ? ' selected' : ''; ?>>
                                <?= htmlspecialchars((string)$sf['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn-primary" style="padding:0.5rem 1rem; border-radius:8px;">Apply filter</button>
                <?php if ($slotsTotalShown > 0): ?>
                    <span style="color:#8b95b5; font-size:0.88rem; margin-left:auto;">
                        <?= (int)$slotsTotalShown; ?> top slot<?= $slotsTotalShown === 1 ? '' : 's'; ?>
                        across <?= (int)$facilitiesWithRecs; ?> facilit<?= $facilitiesWithRecs === 1 ? 'y' : 'ies'; ?>
                        (max <?= (int)$topSlotsPerFacility; ?> per facility)
                    </span>
                <?php endif; ?>
            </form>
            <?php if (empty($slotsByFacility)): ?>
                <p style="color:#8b95b5; padding:1rem 0;">Not enough historical data yet to generate recommendations for this scope. Try “All facilities” or check back once more approved reservations are recorded.</p>
            <?php else: ?>
                <?php foreach ($slotsByFacility as $group): ?>
                    <div class="scheduler-facility-block" style="margin-bottom:1.5rem;">
                        <h3 style="margin:0 0 0.75rem; font-size:1.05rem; color:var(--gov-blue-dark, #1e3a5f); display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <?= htmlspecialchars($group['facility']); ?>
                            <span style="font-size:0.8rem; font-weight:500; color:#8b95b5;">Top <?= count($group['slots']); ?> recommended</span>
                        </h3>
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Day</th>
                                <th>Historical time window</th>
                                <th>Suggested tier</th>
                                <th>Pattern insight</th>
                                <th>Past bookings</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['slots'] as $rank => $slot): ?>
                                <?php
                                    $patternLabel = ($slot['score'] === 'High')
                                        ? 'Lower historical demand'
                                        : (($slot['score'] === 'Medium') ? 'Moderate demand' : 'Frequent bookings in this pattern');
                                    ?>
                                <tr>
                                    <td><strong>#<?= (int)$rank + 1; ?></strong></td>
                                    <td><?= htmlspecialchars($slot['day']); ?></td>
                                    <td><?= htmlspecialchars($slot['time']); ?></td>
                                    <td>
                                        <span class="status-badge <?= $slot['score'] === 'High' ? 'status-approved' : ($slot['score'] === 'Medium' ? 'status-pending' : 'status-cancelled'); ?>">
                                            <?= htmlspecialchars($slot['score']); ?>
                                        </span>
                                    </td>
                                    <td style="max-width:220px; color:#55607a; font-size:0.88rem;">
                                        <?= htmlspecialchars($patternLabel); ?> · <?= htmlspecialchars((string)$slot['reason']); ?>
                                    </td>
                                    <td><?= (int)$slot['count']; ?></td>
                                    <td>
                                        <a class="btn-outline" style="padding:0.35rem 0.6rem; font-size:0.85rem; text-decoration:none;"
                                           href="<?= base_path(); ?>/dashboard/book-facility?facility_id=<?= urlencode((string)$slot['facility_id']); ?>&reservation_date=<?= urlencode((string)$slot['reservation_date']); ?>&time_slot=<?= urlencode((string)$slot['time']); ?>">
                                            Book this slot
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
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
                        <p style="color:#8b95b5; font-size:0.88rem; margin:0;">
                            Showing the <?= (int)$facilitiesWithRecs; ?> facilities with the strongest recommendations.
                            <?= (int)$hiddenFacilityCount; ?> more <?= $hiddenFacilityCount === 1 ? 'has' : 'have'; ?> data — pick a facility above to see its top <?= (int)$topSlotsPerFacility; ?>.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="reports-grid" style="margin-top:1.5rem;">
    <section class="report-card">
        <?= frs_heading_with_tip(
            'Upcoming Holidays & Local Events (Next 120 Days)',
            'PH national holidays and Barangay Culiat events (e.g. Fiesta). Booking demand is often higher around these dates.'
        ); ?>
        <?php if (empty($eventRisk)): ?>
            <p style="color:#8b95b5;">No tagged holidays/events in the next 120 days.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Event</th>
                        <th>Risk</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eventRisk as $evt): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($evt['date'])); ?></td>
                            <td><?= htmlspecialchars($evt['dow']); ?></td>
                            <td><?= htmlspecialchars($evt['label']); ?></td>
                            <td><span class="status-badge status-pending">High</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
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
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

#aiForecastModal.modal-overlay.show {
    display: flex !important;
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




