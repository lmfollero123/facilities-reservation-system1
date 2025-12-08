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
require_once __DIR__ . '/../../../../config/notifications.php';

$pdo = db();
$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'Resident';
$pageTitle = 'Dashboard | LGU Facilities Reservation';

$today = date('Y-m-d');
$weekFromNow = date('Y-m-d', strtotime('+7 days'));

// Get upcoming reservations for this user
$upcomingStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.user_id = :user_id
     AND r.reservation_date >= :today
     AND r.status IN ("approved", "pending")
     ORDER BY r.reservation_date ASC
     LIMIT 5'
);
$upcomingStmt->execute([
    'user_id' => $userId,
    'today' => $today,
]);
$upcomingReservations = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
$upcomingCount = count($upcomingReservations);

// Get pending approvals (Admin/Staff only)
$pendingCount = 0;
$pendingReservations = [];
if (in_array($userRole, ['Admin', 'Staff'])) {
    $pendingStmt = $pdo->query(
        'SELECT COUNT(*) FROM reservations WHERE status = "pending"'
    );
    $pendingCount = (int)$pendingStmt->fetchColumn();
    
    $pendingListStmt = $pdo->query(
        'SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name, u.name AS requester_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         JOIN users u ON r.user_id = u.id
         WHERE r.status = "pending"
         ORDER BY r.created_at ASC
         LIMIT 5'
    );
    $pendingReservations = $pendingListStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get user's total reservations
$totalResStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM reservations WHERE user_id = :user_id'
);
$totalResStmt->execute(['user_id' => $userId]);
$totalReservations = (int)$totalResStmt->fetchColumn();

// Get user's approved reservations count
$approvedResStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM reservations WHERE user_id = :user_id AND status = "approved"'
);
$approvedResStmt->execute(['user_id' => $userId]);
$approvedReservations = (int)$approvedResStmt->fetchColumn();

// Get unread notifications
$unreadNotifications = getUnreadNotificationCount($userId);

// Get recent activity with pagination
$perPage = 10;
$recentPage = max(1, (int)($_GET['recent_page'] ?? 1));
$recentOffset = ($recentPage - 1) * $perPage;

$recentCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.user_id = :user_id'
);
$recentCountStmt->execute(['user_id' => $userId]);
$recentTotal = (int)$recentCountStmt->fetchColumn();
$recentTotalPages = max(1, (int)ceil($recentTotal / $perPage));

$recentStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name, r.created_at
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.user_id = :user_id
     ORDER BY r.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$recentStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$recentStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$recentStmt->bindValue(':offset', $recentOffset, PDO::PARAM_INT);
$recentStmt->execute();
$recentReservations = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Chart data: Monthly reservations (last 6 months)
$monthlyLabels = [];
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $monthLabel = date('M Y', strtotime("-$i months"));
    
    $monthlyLabels[] = $monthLabel;
    
    if (in_array($userRole, ['Admin', 'Staff'])) {
        $monthStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end'
        );
        $monthStmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
    } else {
        $monthStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reservations WHERE user_id = :user_id AND reservation_date >= :start AND reservation_date <= :end'
        );
        $monthStmt->execute(['user_id' => $userId, 'start' => $monthStart, 'end' => $monthEnd]);
    }
    $monthlyData[] = (int)$monthStmt->fetchColumn();
}

// Chart data: Status breakdown
if (in_array($userRole, ['Admin', 'Staff'])) {
    $statusStmt = $pdo->query(
        'SELECT status, COUNT(*) as count FROM reservations GROUP BY status'
    );
} else {
    $statusStmt = $pdo->prepare(
        'SELECT status, COUNT(*) as count FROM reservations WHERE user_id = :user_id GROUP BY status'
    );
    $statusStmt->execute(['user_id' => $userId]);
}
$statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

$statusMap = ['approved' => 0, 'pending' => 0, 'denied' => 0, 'cancelled' => 0];
$statusLabels = [];
$statusCounts = [];
$statusColors = ['#28a745', '#ff9800', '#e53935', '#6c757d'];

foreach ($statusData as $row) {
    $status = strtolower($row['status']);
    if (isset($statusMap[$status])) {
        $statusMap[$status] = (int)$row['count'];
    }
}

$statusLabels = ['Approved', 'Pending', 'Denied', 'Cancelled'];
$statusCounts = [$statusMap['approved'], $statusMap['pending'], $statusMap['denied'], $statusMap['cancelled']];

// Chart data: Facility utilization (Admin/Staff only or user's top facilities)
if (in_array($userRole, ['Admin', 'Staff'])) {
    $facilityStmt = $pdo->query(
        'SELECT f.name, COUNT(r.id) as booking_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id AND r.status = "approved"
         GROUP BY f.id, f.name
         ORDER BY booking_count DESC
         LIMIT 5'
    );
} else {
    $facilityStmt = $pdo->prepare(
        'SELECT f.name, COUNT(r.id) as booking_count
         FROM facilities f
         JOIN reservations r ON f.id = r.facility_id
         WHERE r.user_id = :user_id
         GROUP BY f.id, f.name
         ORDER BY booking_count DESC
         LIMIT 5'
    );
    $facilityStmt->execute(['user_id' => $userId]);
}
$facilityData = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);

$facilityLabels = [];
$facilityCounts = [];
foreach ($facilityData as $fac) {
    $facilityLabels[] = $fac['name'];
    $facilityCounts[] = (int)$fac['booking_count'];
}

ob_start();
?>
<div class="page-header">
    <h1>Dashboard Overview</h1>
    <small>Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>!</small>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <h3>My Upcoming Reservations</h3>
        <p style="font-size: 2rem; font-weight: 600; color: var(--gov-blue); margin: 0.5rem 0;">
            <?= $upcomingCount; ?>
        </p>
        <small style="color: #8b95b5;">
            <?= $upcomingCount === 1 ? 'reservation' : 'reservations'; ?> in the next 7 days
        </small>
    </div>
    
    <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
    <div class="stat-card">
        <h3>Pending Approvals</h3>
        <p style="font-size: 2rem; font-weight: 600; color: #ff9800; margin: 0.5rem 0;">
            <?= $pendingCount; ?>
        </p>
        <small style="color: #8b95b5;">
            <?= $pendingCount === 1 ? 'request' : 'requests'; ?> awaiting review
        </small>
    </div>
    <?php endif; ?>
    
    <div class="stat-card">
        <h3>Total Reservations</h3>
        <p style="font-size: 2rem; font-weight: 600; color: var(--gov-blue-dark); margin: 0.5rem 0;">
            <?= $totalReservations; ?>
        </p>
        <small style="color: #8b95b5;">
            <?= $approvedReservations; ?> approved
        </small>
    </div>
    
    <div class="stat-card">
        <h3>Notifications</h3>
        <p style="font-size: 2rem; font-weight: 600; color: #ff4b5c; margin: 0.5rem 0;">
            <?= $unreadNotifications; ?>
        </p>
        <small style="color: #8b95b5;">
            <?= $unreadNotifications === 1 ? 'unread notification' : 'unread notifications'; ?>
        </small>
    </div>
</div>

<div class="booking-wrapper" style="margin-top: 2rem;">
    <section class="booking-card collapsible-card">
        <button class="collapsible-header" data-collapse-target="upcoming-reservations">
            <span>Upcoming Reservations</span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="upcoming-reservations">
        <div class="table-responsive">
        <?php if (empty($upcomingReservations)): ?>
            <p style="color: #8b95b5; padding: 1rem 0;">No upcoming reservations. <a href="<?= base_path(); ?>/resources/views/pages/dashboard/book_facility.php" style="color: var(--gov-blue);">Book a facility</a> to get started.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Facility</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcomingReservations as $res): ?>
                        <tr>
                            <td><?= htmlspecialchars($res['facility_name']); ?></td>
                            <td><?= date('M j, Y', strtotime($res['reservation_date'])); ?></td>
                            <td><?= htmlspecialchars($res['time_slot']); ?></td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($res['status']); ?>">
                                    <?= ucfirst($res['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/my_reservations.php" class="btn-outline" style="padding: 0.4rem 0.75rem; text-decoration: none; font-size: 0.85rem;">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 1rem;">
                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/my_reservations.php" class="btn-primary" style="text-decoration: none; display: inline-block;">View All Reservations</a>
            </div>
        <?php endif; ?>
        </div>
        </div>
    </section>
    
    <?php if (in_array($userRole, ['Admin', 'Staff']) && !empty($pendingReservations)): ?>
    <aside class="booking-card collapsible-card">
        <button class="collapsible-header" data-collapse-target="pending-requests">
            <span>Recent Pending Requests</span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="pending-requests">
        <div style="display: flex; flex-direction: column; gap: 0.75rem;" class="table-responsive" aria-label="Pending requests">
            <?php foreach ($pendingReservations as $pending): ?>
                <div style="padding: 0.75rem; background: #f9fafc; border-radius: 8px; border: 1px solid #e8ecf4;">
                    <strong style="display: block; margin-bottom: 0.25rem; color: var(--gov-blue-dark);">
                        <?= htmlspecialchars($pending['facility_name']); ?>
                    </strong>
                    <small style="color: #8b95b5; display: block; margin-bottom: 0.25rem;">
                        <?= htmlspecialchars($pending['requester_name']); ?>
                    </small>
                    <small style="color: #5b6888;">
                        <?= date('M j, Y', strtotime($pending['reservation_date'])); ?> - <?= htmlspecialchars($pending['time_slot']); ?>
                    </small>
                    <div style="margin-top: 0.5rem;">
                        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php" class="btn-outline" style="padding: 0.35rem 0.65rem; text-decoration: none; font-size: 0.8rem;">Review</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($pendingCount > 5): ?>
            <div style="margin-top: 1rem; text-align: center;">
                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php" class="btn-primary" style="text-decoration: none; display: inline-block; padding: 0.5rem 1rem;">View All (<?= $pendingCount; ?>)</a>
            </div>
        <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>
</div>

<div class="reports-grid" style="margin-top: 2rem;">
    <section class="booking-card">
        <h2>Reservation Trends</h2>
        <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
            <?= in_array($userRole, ['Admin', 'Staff']) ? 'Total reservations over the last 6 months' : 'Your reservations over the last 6 months'; ?>
        </p>
        <canvas id="monthlyChart" style="max-height: 300px;"></canvas>
    </section>
    
    <section class="booking-card">
        <h2>Status Breakdown</h2>
        <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
            Distribution of reservation statuses
        </p>
        <canvas id="statusChart" style="max-height: 300px;"></canvas>
    </section>
</div>

<?php if (!empty($facilityLabels)): ?>
<div class="booking-card" style="margin-top: 2rem;">
    <h2><?= in_array($userRole, ['Admin', 'Staff']) ? 'Top Facilities by Bookings' : 'Your Most Booked Facilities'; ?></h2>
    <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
        Facilities with the highest number of <?= in_array($userRole, ['Admin', 'Staff']) ? 'approved reservations' : 'your reservations'; ?>
    </p>
    <canvas id="facilityChart" style="max-height: 300px;"></canvas>
</div>
<?php endif; ?>

<div class="booking-card" style="margin-top: 2rem;">
    <h2>Quick Actions</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/book_facility.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📅 Book Facility</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">Submit a new reservation request</p>
        </a>
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/my_reservations.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📋 My Reservations</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View your booking history</p>
        </a>
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/calendar.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">🗓️ Calendar</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View availability calendar</p>
        </a>
        <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reports.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📊 Reports</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View analytics & insights</p>
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
// Collapsible helper with localStorage persistence (dashboard)
(function() {
    const STORAGE_KEY = 'collapse-state-dashboard';
    let state = {};
    try { state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { state = {}; }

    function save() { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }

    function init() {
        document.querySelectorAll('.collapsible-header').forEach(header => {
            const targetId = header.getAttribute('data-collapse-target');
            const body = document.getElementById(targetId);
            if (!body) return;
            const chevron = header.querySelector('.chevron');
            if (state[targetId]) {
                body.classList.add('is-collapsed');
                if (chevron) chevron.style.transform = 'rotate(-90deg)';
            }
            header.addEventListener('click', () => {
                const isCollapsed = body.classList.toggle('is-collapsed');
                if (chevron) chevron.style.transform = isCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
                state[targetId] = isCollapsed;
                save();
            });
        });
    }
    document.addEventListener('DOMContentLoaded', init);
})();

document.addEventListener('DOMContentLoaded', function() {
    // Monthly Trends Chart
    const monthlyCtx = document.getElementById('monthlyChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($monthlyLabels); ?>,
                datasets: [{
                    label: 'Reservations',
                    data: <?= json_encode($monthlyData); ?>,
                    borderColor: '#0047ab',
                    backgroundColor: 'rgba(0, 71, 171, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2,
                    pointBackgroundColor: '#0047ab',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Status Breakdown Chart
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($statusLabels); ?>,
                datasets: [{
                    data: <?= json_encode($statusCounts); ?>,
                    backgroundColor: <?= json_encode($statusColors); ?>,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
    }

    // Facility Chart
    const facilityCtx = document.getElementById('facilityChart');
    if (facilityCtx) {
        new Chart(facilityCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($facilityLabels); ?>,
                datasets: [{
                    label: 'Bookings',
                    data: <?= json_encode($facilityCounts); ?>,
                    backgroundColor: '#0047ab',
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';



