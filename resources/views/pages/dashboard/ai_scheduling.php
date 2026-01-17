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
$slotStmt = $pdo->prepare(
    'SELECT f.id AS facility_id, f.name AS facility, DAYNAME(r.reservation_date) AS day_name, r.time_slot, COUNT(*) AS booking_count
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.reservation_date BETWEEN :start AND :end
       AND r.status = "approved"
     GROUP BY f.id, facility_id, facility, day_name, r.time_slot
     ORDER BY booking_count ASC, facility ASC'
);
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

    $slots[] = [
        'facility' => $row['facility'],
        'facility_id' => $row['facility_id'],
        'day' => $row['day_name'],
        'time' => $row['time_slot'],
        'reason' => $reason,
        'score' => $score,
        'count' => $count,
    ];
}

// Limit to top 10 recommended slots
$slots = array_slice($slots, 0, 10);

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
    <h1>Smart Scheduler</h1>
    <small>AI-powered recommendations for optimal booking times based on historical reservation data and demand patterns.</small>
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
            <p class="resource-meta" style="margin: 0; color: #6c757d;">
                Click to view AI-powered insights about booking patterns, peak times, and demand forecasts.
            </p>
        </div>
        
        <!-- Recommended Time Slots -->
        <div class="report-card">
            <h2>Recommended Time Slots</h2>
            <p style="color:#6c757d; font-size:0.9rem; margin-bottom:1rem; line-height:1.5;">
                <strong>How recommendations work:</strong> The system analyzes historical booking patterns over the last 6 months. 
                <strong>High recommendation</strong> means very few past bookings (1 or less) for that day/time combination, indicating lower conflict risk. 
                <strong>Medium</strong> has moderate usage (2-3 bookings), while <strong>Low</strong> has frequent bookings (4+) and higher competition. 
                These recommendations help you find the best times to book with minimal conflicts.
            </p>
            <?php if (empty($slots)): ?>
                <p style="color:#8b95b5; padding:1rem 0;">Not enough historical data yet to generate recommendations. Once more approved reservations are recorded, suggested slots will appear here.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Facility</th>
                        <th>Day</th>
                        <th>Time Window</th>
                        <th>Recommendation</th>
                        <th>Past Bookings</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($slots as $slot): ?>
                        <tr>
                            <td><?= htmlspecialchars($slot['facility']); ?></td>
                            <td><?= htmlspecialchars($slot['day']); ?></td>
                            <td><?= htmlspecialchars($slot['time']); ?></td>
                            <td>
                                <span class="status-badge <?= $slot['score'] === 'High' ? 'status-approved' : ($slot['score'] === 'Medium' ? 'status-pending' : 'status-cancelled'); ?>">
                                    <?= htmlspecialchars($slot['score']); ?>
                                </span>
                            </td>
                            <td><?= (int)$slot['count']; ?></td>
                            <td>
                                <a class="btn-outline" style="padding:0.35rem 0.6rem; font-size:0.85rem; text-decoration:none;"
                                   href="<?= base_path(); ?>/dashboard/book-facility?facility_id=<?= urlencode($slot['facility_id']); ?>&time_slot=<?= urlencode($slot['time']); ?>">
                                    Book this slot
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="reports-grid" style="margin-top:1.5rem;">
    <section class="report-card">
        <h2>Upcoming Holidays & Local Events (Next 120 Days)</h2>
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
        <p style="color:#8b95b5; font-size:0.9rem; margin-top:0.75rem;">
            Risk is elevated on holidays and Barangay Culiat events (e.g., Fiesta, Founding Day). Prefer alternate dates or earlier lead times.
        </p>
    </section>
</div>
<!-- AI Demand Forecast Modal -->
<div id="aiForecastModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e5e7eb;">
            <div>
                <div class="ai-chip" style="margin-bottom: 0.5rem;">
                    <span>AI</span> <span>Demand Forecast</span>
                </div>
                <h2 style="margin: 0; color: #1e3a5f;">Insights Overview</h2>
            </div>
            <button type="button" onclick="closeAIModal()" style="background: none; border: none; font-size: 1.5rem; color: #6c757d; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: all 0.2s ease;" onmouseover="this.style.background='#f0f0f0'; this.style.color='#333';" onmouseout="this.style.background='none'; this.style.color='#6c757d';">&times;</button>
        </div>
        <div class="modal-body">
            <ul style="line-height:1.8; margin: 0; padding-left: 1.25rem; color: #4a5568;">
                <?php if ($peakDay): ?>
                    <li style="margin-bottom: 1rem;"><strong>Peak day:</strong> Most approved reservations fall on <strong><?= htmlspecialchars($peakDay['day_name']); ?>s</strong>.</li>
                <?php endif; ?>
                <?php if ($peakTime): ?>
                    <li style="margin-bottom: 1rem;"><strong>Busy time window:</strong> The <strong><?= htmlspecialchars($peakTime['time_slot']); ?></strong> slot sees the highest activity.</li>
                <?php endif; ?>
                <li style="margin-bottom: 1rem;"><strong>Recommendation Scores:</strong>
                    <ul style="margin-top:0.5rem; padding-left:1.5rem; list-style-type:disc;">
                        <li><strong>High</strong> = 0-1 past bookings (low conflict risk, best choice)</li>
                        <li><strong>Medium</strong> = 2-3 past bookings (moderate usage)</li>
                        <li><strong>Low</strong> = 4+ past bookings (high competition, more conflicts)</li>
                    </ul>
                </li>
                <li style="margin-bottom: 1rem;">Consider scheduling official LGU events on days and time windows with fewer past bookings to reduce conflicts.</li>
                <li style="margin-bottom: 0;">These insights are based on approved reservations from the last 6 months and tagged PH holidays + Barangay Culiat events.</li>
            </ul>
        </div>
        <div class="modal-footer" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; text-align: right;">
            <button type="button" onclick="closeAIModal()" class="btn-primary" style="padding: 0.5rem 1.5rem; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">Close</button>
        </div>
    </div>
</div>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.modal-content {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    padding: 2rem;
    width: 100%;
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-overlay.show {
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




