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
$pageTitle = 'Calendar & Schedule | LGU Facilities Reservation';
$calendarRole = (string)($_SESSION['role'] ?? 'Resident');
$calendarUserId = (int)($_SESSION['user_id'] ?? 0);
$calendarStaffView = in_array($calendarRole, ['Admin', 'Staff'], true);

// Month view only (week/day used legacy Morning/Afternoon slots incompatible with HH:MM ranges).
$view = 'month';
if (isset($_GET['view']) && $_GET['view'] !== 'month') {
    $redirectQs = $_GET;
    unset($redirectQs['view']);
    $redirectQs['view'] = 'month';
    header('Location: ' . base_path() . '/dashboard/calendar?' . http_build_query($redirectQs), true, 302);
    exit;
}

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

// Handle navigation
if (isset($_GET['nav'])) {
    if ($_GET['nav'] === 'prev') {
        $month--;
        if ($month < 1) {
            $month = 12;
            $year--;
        }
    } elseif ($_GET['nav'] === 'next') {
        $month++;
        if ($month > 12) {
            $month = 1;
            $year++;
        }
    } elseif ($_GET['nav'] === 'today') {
        $year = (int)date('Y');
        $month = (int)date('m');
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

$reservationsSql = 'SELECT r.id, r.reservation_date, r.time_slot, r.status, 
            f.name AS facility_name, f.status AS facility_status,
            u.name AS requester_name
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.reservation_date >= :start_date AND r.reservation_date <= :end_date';
$reservationsParams = ['start_date' => $startDate, 'end_date' => $endDate];
if (!$calendarStaffView) {
    $reservationsSql .= ' AND r.user_id = :uid';
    $reservationsParams['uid'] = $calendarUserId;
}
$reservationsSql .= ' ORDER BY r.reservation_date, r.time_slot';
$reservationsStmt = $pdo->prepare($reservationsSql);
$reservationsStmt->execute($reservationsParams);
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

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Scheduling</span><span class="sep">/</span><span>Calendar</span>
    </div>
    <?= frs_page_title('Calendar & Scheduling', 'Green = approved, amber = pending, red = maintenance/blocked. Export adds events to Google Calendar or Outlook (.ics).'); ?>
    <p style="margin:0.75rem 0 0;">
        <a class="btn-outline" href="<?= htmlspecialchars(base_path() . '/dashboard/calendar-export?from=' . urlencode($startDate) . '&to=' . urlencode($endDate) . (in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true) ? '&scope=all' : ''), ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-block;text-decoration:none;padding:0.45rem 0.9rem;font-size:0.88rem;">
            Export to calendar (.ics)
        </a>
    </p>
    <div class="calendar-legend">
        <span><span class="swatch" style="background:#e3f8ef"></span> Approved</span>
        <span><span class="swatch" style="background:#fff4e5"></span> Pending</span>
        <span><span class="swatch" style="background:#fdecee"></span> Maintenance / Blocked</span>
    </div>
</div>

<div class="calendar-shell">
    <div class="calendar-header">
        <h2><?= $monthLabel; ?></h2>
        <div class="calendar-controls">
            <a href="?view=month&year=<?= $year; ?>&month=<?= $month; ?>&nav=prev" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem;">&larr; Prev</a>
            <a href="?view=month&nav=today" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem;">Today</a>
            <a href="?view=month&year=<?= $year; ?>&month=<?= $month; ?>&nav=next" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem;">Next &rarr;</a>
        </div>
    </div>

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
                                        $detailUrl = base_path() . '/dashboard/reservation-detail?id=' . $res['id'];
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
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
