<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';

$pdo = db();
$userRole = $_SESSION['role'] ?? 'Resident';
$pageTitle = 'AI Predictive Scheduling | LGU Facilities Reservation';

// Look back over the last 6 months for patterns
$windowStart = date('Y-m-01', strtotime('-5 months'));
$windowEnd = date('Y-m-t');

// Aggregate reservations by facility, day of week, and time slot
$slotStmt = $pdo->prepare(
    'SELECT f.name AS facility, DAYNAME(r.reservation_date) AS day_name, r.time_slot, COUNT(*) AS booking_count
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.reservation_date BETWEEN :start AND :end
       AND r.status = "approved"
     GROUP BY f.id, facility, day_name, r.time_slot
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

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Analytics</span><span class="sep">/</span><span>AI Predictive Scheduling</span>
    </div>
    <h1>AI Predictive Scheduling</h1>
    <small>Suggested time slots and peak patterns based on historical reservation data.</small>
</div>

<div class="reports-grid">
    <section>
        <div class="report-card">
            <h2>Recommended Time Slots</h2>
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
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <aside>
        <div class="ai-panel">
            <div class="ai-chip">
                <span>AI</span> <span>Demand Forecast</span>
            </div>
            <h3>Insights Overview</h3>
            <ul>
                <?php if ($peakDay): ?>
                    <li><strong>Peak day:</strong> Most approved reservations fall on <strong><?= htmlspecialchars($peakDay['day_name']); ?>s</strong>.</li>
                <?php endif; ?>
                <?php if ($peakTime): ?>
                    <li><strong>Busy time window:</strong> The <strong><?= htmlspecialchars($peakTime['time_slot']); ?></strong> slot sees the highest activity.</li>
                <?php endif; ?>
                <li>Consider scheduling official LGU events on days and time windows with fewer past bookings to reduce conflicts.</li>
                <li>These insights are based on approved reservations from the last 6 months.</li>
            </ul>
        </div>
        <div class="report-card" style="margin-top: 1.5rem;">
            <h2>Integration Notes</h2>
            <p class="resource-meta">
                This page currently uses simple historical patterns (no ML model yet). It can later be wired to a
                dedicated AI service that factors in seasonality, event types, and payment data.
            </p>
        </div>
    </aside>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';




