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
$pageTitle = 'Calendar & Schedule | LGU Facilities Reservation';

// Get date parameters
$view = $_GET['view'] ?? 'month';
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));
$day = (int)($_GET['day'] ?? date('d'));

// Handle navigation
if (isset($_GET['nav'])) {
    if ($_GET['nav'] === 'prev') {
        if ($view === 'month') {
            $month--;
            if ($month < 1) {
                $month = 12;
                $year--;
            }
        } elseif ($view === 'week') {
            $day -= 7;
            if ($day < 1) {
                $month--;
                if ($month < 1) {
                    $month = 12;
                    $year--;
                }
                $day = date('t', mktime(0, 0, 0, $month, 1, $year)) + $day;
            }
        } elseif ($view === 'day') {
            $day--;
            if ($day < 1) {
                $month--;
                if ($month < 1) {
                    $month = 12;
                    $year--;
                }
                $day = date('t', mktime(0, 0, 0, $month, 1, $year));
            }
        }
    } elseif ($_GET['nav'] === 'next') {
        if ($view === 'month') {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        } elseif ($view === 'week') {
            $day += 7;
            $daysInMonth = date('t', mktime(0, 0, 0, $month, 1, $year));
            if ($day > $daysInMonth) {
                $day -= $daysInMonth;
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
            }
        } elseif ($view === 'day') {
            $day++;
            $daysInMonth = date('t', mktime(0, 0, 0, $month, 1, $year));
            if ($day > $daysInMonth) {
                $day = 1;
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
            }
        }
    } elseif ($_GET['nav'] === 'today') {
        $year = (int)date('Y');
        $month = (int)date('m');
        $day = (int)date('d');
    }
}

// Calculate month view dates
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDay);
$dayOfWeek = date('w', $firstDay); // 0 = Sunday
$monthLabel = date('F Y', $firstDay);

// Fetch reservations for the month
$startDate = date('Y-m-01', $firstDay);
$endDate = date('Y-m-t', $firstDay);

$reservationsStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, 
            f.name AS facility_name, f.status AS facility_status,
            u.name AS requester_name
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.reservation_date >= :start_date AND r.reservation_date <= :end_date
     ORDER BY r.reservation_date, r.time_slot'
);
$reservationsStmt->execute([
    'start_date' => $startDate,
    'end_date' => $endDate,
]);
$reservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Group reservations by date
$reservationsByDate = [];
foreach ($reservations as $reservation) {
    $date = $reservation['reservation_date'];
    if (!isset($reservationsByDate[$date])) {
        $reservationsByDate[$date] = [];
    }
    $reservationsByDate[$date][] = $reservation;
}

// Fetch facilities with maintenance status
$facilitiesStmt = $pdo->query('SELECT id, name, status FROM facilities');
$facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate week view dates
$weekStart = mktime(0, 0, 0, $month, $day, $year);
$weekDay = date('w', $weekStart);
$weekStart = mktime(0, 0, 0, $month, $day - $weekDay, $year);
$weekDates = [];
for ($i = 0; $i < 7; $i++) {
    $weekDates[] = date('Y-m-d', strtotime("+$i days", $weekStart));
}

// Fetch reservations for week view
$weekReservationsStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status,
            f.name AS facility_name, f.status AS facility_status,
            u.name AS requester_name
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.reservation_date >= :start_date AND r.reservation_date <= :end_date
     ORDER BY r.reservation_date, r.time_slot'
);
$weekReservationsStmt->execute([
    'start_date' => $weekDates[0],
    'end_date' => $weekDates[6],
]);
$weekReservations = $weekReservationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Group week reservations by date and time
$weekReservationsByDate = [];
foreach ($weekReservations as $reservation) {
    $date = $reservation['reservation_date'];
    if (!isset($weekReservationsByDate[$date])) {
        $weekReservationsByDate[$date] = [];
    }
    $weekReservationsByDate[$date][] = $reservation;
}

// Fetch day view reservations
$dayDate = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
$dayReservationsStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.purpose,
            f.name AS facility_name, f.status AS facility_status,
            u.name AS requester_name
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.reservation_date = :date
     ORDER BY r.time_slot'
);
$dayReservationsStmt->execute(['date' => $dayDate]);
$dayReservations = $dayReservationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Time slots for week/day views
$timeSlots = [
    'Morning (8AM - 12PM)',
    'Afternoon (1PM - 5PM)',
    'Evening (5PM - 9PM)',
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Scheduling</span><span class="sep">/</span><span>Calendar</span>
    </div>
    <h1>Calendar & Scheduling</h1>
    <small>Visual overview of reservations, maintenance blocks, and availability.</small>
    <div class="calendar-tabs">
        <a href="?view=month&year=<?= $year; ?>&month=<?= $month; ?>" class="<?= $view === 'month' ? 'active' : ''; ?>" data-calendar-view="month">Month</a>
        <a href="?view=week&year=<?= $year; ?>&month=<?= $month; ?>&day=<?= $day; ?>" class="<?= $view === 'week' ? 'active' : ''; ?>" data-calendar-view="week">Week</a>
        <a href="?view=day&year=<?= $year; ?>&month=<?= $month; ?>&day=<?= $day; ?>" class="<?= $view === 'day' ? 'active' : ''; ?>" data-calendar-view="day">Day</a>
    </div>
    <div class="calendar-legend">
        <span><span class="swatch" style="background:#e3f8ef"></span> Approved</span>
        <span><span class="swatch" style="background:#fff4e5"></span> Pending</span>
        <span><span class="swatch" style="background:#fdecee"></span> Maintenance / Blocked</span>
    </div>
</div>

<div class="calendar-shell">
    <div class="calendar-header">
        <h2>
            <?php if ($view === 'month'): ?>
                <?= $monthLabel; ?>
            <?php elseif ($view === 'week'): ?>
                Week of <?= date('M d', strtotime($weekDates[0])); ?> - <?= date('M d, Y', strtotime($weekDates[6])); ?>
            <?php else: ?>
                <?= date('F d, Y', mktime(0, 0, 0, $month, $day, $year)); ?>
            <?php endif; ?>
        </h2>
        <div class="calendar-controls">
            <a href="?view=<?= $view; ?>&year=<?= $year; ?>&month=<?= $month; ?>&day=<?= $day; ?>&nav=prev" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem;">&larr; Prev</a>
            <a href="?view=<?= $view; ?>&nav=today" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem;">Today</a>
            <a href="?view=<?= $view; ?>&year=<?= $year; ?>&month=<?= $month; ?>&day=<?= $day; ?>&nav=next" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem;">Next &rarr;</a>
        </div>
    </div>

    <?php if ($view === 'month'): ?>
        <div class="calendar-view active" data-calendar-container="month">
            <div class="calendar-grid-month">
                <?php
                $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                foreach ($dayNames as $name): ?>
                    <div class="day-name"><?= $name; ?></div>
                <?php endforeach; ?>

                <?php
                // Empty cells before first day
                for ($i = 0; $i < $dayOfWeek; $i++): ?>
                    <div class="day-cell"></div>
                <?php endfor; ?>

                <?php
                // Days of the month
                for ($dayNum = 1; $dayNum <= $daysInMonth; $dayNum++):
                    $currentDate = date('Y-m-d', mktime(0, 0, 0, $month, $dayNum, $year));
                    $dayReservations = $reservationsByDate[$currentDate] ?? [];
                    $hasMaintenance = false;
                    
                    // Check if any reservation on THIS date is for a facility in maintenance
                    foreach ($dayReservations as $reservation) {
                        if ($reservation['facility_status'] === 'maintenance' || $reservation['facility_status'] === 'offline') {
                            $hasMaintenance = true;
                            break;
                        }
                    }
                    
                    $statusClass = 'available';
                    $statusLabel = '';
                    $eventCount = count($dayReservations);
                    
                    if ($hasMaintenance && $eventCount > 0) {
                        $statusClass = 'blocked';
                        $statusLabel = $eventCount . ' booking' . ($eventCount > 1 ? 's' : '') . ' + Maintenance';
                    } elseif ($hasMaintenance) {
                        $statusClass = 'blocked';
                        $statusLabel = 'Maintenance';
                    } elseif ($eventCount > 0) {
                        $approvedCount = 0;
                        $pendingCount = 0;
                        foreach ($dayReservations as $res) {
                            if ($res['status'] === 'approved') {
                                $approvedCount++;
                            } elseif ($res['status'] === 'pending') {
                                $pendingCount++;
                            }
                        }
                        if ($approvedCount > 0 && $pendingCount > 0) {
                            $statusClass = 'request';
                            $statusLabel = $approvedCount . ' approved, ' . $pendingCount . ' pending';
                        } elseif ($approvedCount > 0) {
                            $statusClass = 'available';
                            $statusLabel = $approvedCount . ' booking' . ($approvedCount > 1 ? 's' : '');
                        } else {
                            $statusClass = 'request';
                            $statusLabel = $pendingCount . ' pending';
                        }
                    }
                    ?>
                    <div class="day-cell">
                        <span class="day-number"><?= $dayNum; ?></span>
                        <?php if ($statusLabel): ?>
                            <span class="pill-event <?= $statusClass; ?>" title="<?= htmlspecialchars($statusLabel); ?>">
                                <?= htmlspecialchars($statusLabel); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($dayReservations)): ?>
                            <div style="margin-top:0.35rem; display:flex; flex-direction:column; gap:0.25rem;">
                                <?php foreach ($dayReservations as $res): ?>
                                    <?php
                                        $pillClass = $res['status'] === 'approved' ? 'available' :
                                                     ($res['status'] === 'pending' ? 'request' : 'blocked');
                                        $detailUrl = base_path() . '/resources/views/pages/dashboard/reservation_detail.php?id=' . $res['id'];
                                    ?>
                                    <a href="<?= $detailUrl; ?>" class="pill-event <?= $pillClass; ?>" style="display:block; text-decoration:none;" title="View reservation details">
                                        <?= htmlspecialchars($res['facility_name']); ?> — <?= htmlspecialchars(ucfirst($res['status'])); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($view === 'week'): ?>
        <div class="calendar-view <?= $view === 'week' ? 'active' : ''; ?>" data-calendar-container="week">
            <div class="calendar-grid-week table-responsive">
                <table>
                    <thead>
                    <tr>
                        <th>Time</th>
                        <?php foreach ($weekDates as $date): ?>
                            <th><?= date('D M d', strtotime($date)); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($timeSlots as $timeSlot): ?>
                        <tr>
                            <td><?= htmlspecialchars($timeSlot); ?></td>
                            <?php foreach ($weekDates as $date): ?>
                                <td>
                                    <?php
                                    $dayReservations = $weekReservationsByDate[$date] ?? [];
                                    $slotReservations = array_filter($dayReservations, function($r) use ($timeSlot) {
                                        return $r['time_slot'] === $timeSlot;
                                    });
                                    
                                    foreach ($slotReservations as $reservation):
                                        $statusClass = $reservation['status'] === 'approved' ? 'available' : 
                                                      ($reservation['status'] === 'pending' ? 'request' : 'blocked');
                                        $detailUrl = base_path() . '/resources/views/pages/dashboard/reservation_detail.php?id=' . $reservation['id'];
                                        ?>
                                        <a href="<?= $detailUrl; ?>" class="pill-event <?= $statusClass; ?>" style="display:block; margin-bottom:0.25rem; text-decoration:none;" title="View reservation details">
                                            <?= htmlspecialchars($reservation['facility_name']); ?>
                                            <?php if ($reservation['facility_status'] === 'maintenance'): ?>
                                                (Maintenance)
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($slotReservations)): ?>
                                        <span style="color:#8b95b5; font-size:0.85rem;">Available</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($view === 'day'): ?>
        <div class="calendar-view <?= $view === 'day' ? 'active' : ''; ?>" data-calendar-container="day">
            <div class="calendar-grid-day table-responsive">
                <table>
                    <thead>
                    <tr>
                        <th>Time Slot</th>
                        <th>Facility</th>
                        <th>Requester</th>
                        <th>Purpose</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dayReservations)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:2rem; color:#8b95b5;">
                                No reservations scheduled for this day.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dayReservations as $reservation): ?>
                            <?php $detailUrl = base_path() . '/resources/views/pages/dashboard/reservation_detail.php?id=' . $reservation['id']; ?>
                            <tr style="cursor:pointer;" onclick="window.location.href='<?= $detailUrl; ?>';">
                                <td><?= htmlspecialchars($reservation['time_slot']); ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($reservation['facility_name']); ?></strong>
                                    <?php if ($reservation['facility_status'] === 'maintenance'): ?>
                                        <span class="status-badge maintenance" style="margin-left:0.5rem;">Maintenance</span>
                                    <?php elseif ($reservation['facility_status'] === 'offline'): ?>
                                        <span class="status-badge offline" style="margin-left:0.5rem;">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($reservation['requester_name']); ?></td>
                                <td><?= htmlspecialchars($reservation['purpose']); ?></td>
                                <td>
                                    <span class="status-badge <?= $reservation['status']; ?>">
                                        <?= ucfirst($reservation['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
