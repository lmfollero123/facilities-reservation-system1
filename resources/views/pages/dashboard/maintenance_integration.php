<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !in_array($role, ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/resources/views/pages/dashboard/index.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
$pdo = db();
$pageTitle = 'Maintenance Integration | LGU Facilities Reservation';

// Mock data for maintenance schedules (will be replaced with API integration)
$mockMaintenanceSchedules = [
    [
        'id' => 'MAINT-2025-001',
        'facility_id' => 1,
        'facility_name' => 'Community Convention Hall',
        'maintenance_type' => 'Routine Inspection',
        'scheduled_start' => '2025-01-15 08:00:00',
        'scheduled_end' => '2025-01-15 12:00:00',
        'status' => 'scheduled',
        'priority' => 'medium',
        'description' => 'Monthly routine inspection of electrical systems and HVAC units.',
        'assigned_team' => 'Maintenance Team A',
        'estimated_duration' => '4 hours',
        'affected_reservations' => 2,
        'created_at' => '2025-01-10 10:30:00',
    ],
    [
        'id' => 'MAINT-2025-002',
        'facility_id' => 2,
        'facility_name' => 'Municipal Sports Complex',
        'maintenance_type' => 'Emergency Repair',
        'scheduled_start' => '2025-01-20 09:00:00',
        'scheduled_end' => '2025-01-22 17:00:00',
        'status' => 'in_progress',
        'priority' => 'high',
        'description' => 'Repair of damaged court flooring and lighting system replacement.',
        'assigned_team' => 'Maintenance Team B',
        'estimated_duration' => '3 days',
        'affected_reservations' => 5,
        'created_at' => '2025-01-18 14:20:00',
    ],
    [
        'id' => 'MAINT-2025-003',
        'facility_id' => 3,
        'facility_name' => 'People\'s Park Amphitheater',
        'maintenance_type' => 'Preventive Maintenance',
        'scheduled_start' => '2025-01-25 07:00:00',
        'scheduled_end' => '2025-01-25 15:00:00',
        'status' => 'scheduled',
        'priority' => 'low',
        'description' => 'Cleaning, stage equipment check, and sound system testing.',
        'assigned_team' => 'Maintenance Team C',
        'estimated_duration' => '8 hours',
        'affected_reservations' => 0,
        'created_at' => '2025-01-12 09:15:00',
    ],
];

// Mock maintenance history
$mockMaintenanceHistory = [
    [
        'id' => 'MAINT-2024-045',
        'facility_name' => 'Community Convention Hall',
        'maintenance_type' => 'Routine Inspection',
        'completed_at' => '2024-12-15 12:30:00',
        'status' => 'completed',
        'duration' => '4 hours',
        'technician' => 'John Doe',
        'notes' => 'All systems operational. No issues found.',
    ],
    [
        'id' => 'MAINT-2024-044',
        'facility_name' => 'Municipal Sports Complex',
        'maintenance_type' => 'Equipment Replacement',
        'completed_at' => '2024-12-10 16:00:00',
        'status' => 'completed',
        'duration' => '6 hours',
        'technician' => 'Jane Smith',
        'notes' => 'Replaced faulty lighting fixtures. System fully functional.',
    ],
];

// Get real facilities for dropdown
$facilities = [];
try {
    $facilitiesStmt = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore error
}

// Integration status (mock - will be replaced with actual API health check)
$integrationStatus = [
    'connected' => true,
    'last_sync' => '2025-01-20 10:45:23',
    'sync_status' => 'success',
    'pending_updates' => 2,
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Maintenance Integration</span>
    </div>
    <h1>Maintenance Integration</h1>
    <small>View and manage maintenance schedules from Community Infrastructure Maintenance Management system.</small>
</div>

<!-- Integration Status Card -->
<div class="booking-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="margin: 0 0 0.5rem 0; font-size: 1.1rem;">Integration Status</h2>
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span class="status-badge <?= $integrationStatus['connected'] ? 'active' : 'offline'; ?>" style="font-size: 0.9rem;">
                    <?= $integrationStatus['connected'] ? '✓ Connected' : '✗ Disconnected'; ?>
                </span>
                <small style="color: #8b95b5;">
                    Last sync: <?= date('M d, Y H:i', strtotime($integrationStatus['last_sync'])); ?>
                </small>
                <?php if ($integrationStatus['pending_updates'] > 0): ?>
                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        <?= $integrationStatus['pending_updates']; ?> pending update(s)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn-outline" onclick="syncMaintenanceData()" style="padding: 0.5rem 1rem;">
            🔄 Sync Now
        </button>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>Note:</strong> This integration connects to the Community Infrastructure Maintenance Management system. 
            Maintenance schedules automatically update facility status and block booking dates.
        </small>
    </div>
</div>

<div class="booking-wrapper">
    <!-- Upcoming Maintenance Schedules -->
    <section class="booking-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>Upcoming Maintenance Schedules</h2>
            <div style="display: flex; gap: 0.5rem;">
                <select id="filterStatus" onchange="filterMaintenance()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                    <option value="all">All Status</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select id="filterPriority" onchange="filterMaintenance()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                    <option value="all">All Priorities</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
        </div>

        <?php if (empty($mockMaintenanceSchedules)): ?>
            <p style="color: #8b95b5; text-align: center; padding: 2rem;">No upcoming maintenance schedules.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Maintenance ID</th>
                            <th>Facility</th>
                            <th>Type</th>
                            <th>Scheduled Date</th>
                            <th>Duration</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Affected</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="maintenanceTableBody">
                        <?php foreach ($mockMaintenanceSchedules as $schedule): 
                            $priorityClass = $schedule['priority'] === 'high' ? 'offline' : ($schedule['priority'] === 'medium' ? 'maintenance' : 'active');
                            $statusClass = $schedule['status'] === 'in_progress' ? 'maintenance' : ($schedule['status'] === 'completed' ? 'active' : 'offline');
                            $statusDisplay = ucfirst(str_replace('_', ' ', $schedule['status']));
                        ?>
                            <tr data-status="<?= $schedule['status']; ?>" data-priority="<?= $schedule['priority']; ?>">
                                <td><strong><?= htmlspecialchars($schedule['id']); ?></strong></td>
                                <td><?= htmlspecialchars($schedule['facility_name']); ?></td>
                                <td><?= htmlspecialchars($schedule['maintenance_type']); ?></td>
                                <td>
                                    <?= date('M d, Y', strtotime($schedule['scheduled_start'])); ?><br>
                                    <small style="color: #8b95b5;">
                                        <?= date('H:i', strtotime($schedule['scheduled_start'])); ?> - 
                                        <?= date('H:i', strtotime($schedule['scheduled_end'])); ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($schedule['estimated_duration']); ?></td>
                                <td>
                                    <span class="status-badge <?= $priorityClass; ?>" style="text-transform: capitalize;">
                                        <?= htmlspecialchars($schedule['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $statusClass; ?>">
                                        <?= $statusDisplay; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($schedule['affected_reservations'] > 0): ?>
                                        <span style="color: #dc3545; font-weight: 600;">
                                            <?= $schedule['affected_reservations']; ?> reservation(s)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #8b95b5;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-outline" onclick="viewMaintenanceDetails('<?= htmlspecialchars($schedule['id']); ?>')" style="padding: 0.35rem 0.6rem; font-size: 0.85rem;">
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

    <!-- Maintenance Calendar -->
    <aside class="booking-card">
        <h2>Maintenance Calendar</h2>
        <div style="margin-bottom: 1rem;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Select Facility</label>
            <select id="calendarFacility" onchange="updateMaintenanceCalendar()" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                <option value="all">All Facilities</option>
                <?php foreach ($facilities as $facility): ?>
                    <option value="<?= $facility['id']; ?>"><?= htmlspecialchars($facility['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div id="maintenanceCalendar" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.5rem; margin-top: 1rem;">
            <!-- Calendar will be populated by JavaScript -->
            <?php
            // Generate calendar for current month
            $currentMonth = date('Y-m');
            $firstDay = date('w', strtotime($currentMonth . '-01'));
            $daysInMonth = date('t', strtotime($currentMonth . '-01'));
            
            // Calendar header
            $weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            foreach ($weekDays as $day): ?>
                <div style="text-align: center; font-weight: 600; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                    <?= $day; ?>
                </div>
            <?php endforeach;
            
            // Empty cells for days before month starts
            for ($i = 0; $i < $firstDay; $i++): ?>
                <div style="aspect-ratio: 1; border: 1px solid #e0e6ed; border-radius: 4px;"></div>
            <?php endfor;
            
            // Calendar days
            for ($day = 1; $day <= $daysInMonth; $day++):
                $dateStr = $currentMonth . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                $hasMaintenance = false;
                $maintenanceInfo = null;
                
                // Check if this date has maintenance
                foreach ($mockMaintenanceSchedules as $schedule) {
                    $startDate = date('Y-m-d', strtotime($schedule['scheduled_start']));
                    $endDate = date('Y-m-d', strtotime($schedule['scheduled_end']));
                    if ($dateStr >= $startDate && $dateStr <= $endDate) {
                        $hasMaintenance = true;
                        $maintenanceInfo = $schedule;
                        break;
                    }
                }
                
                $dayClass = $hasMaintenance ? 'maintenance' : '';
                $dayStyle = $hasMaintenance ? 'background: #fff3cd; border: 2px solid #ffc107; font-weight: 600;' : '';
            ?>
                <div class="calendar-day <?= $dayClass; ?>" 
                     data-date="<?= $dateStr; ?>"
                     style="aspect-ratio: 1; border: 1px solid #e0e6ed; border-radius: 4px; padding: 0.5rem; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; <?= $dayStyle; ?>"
                     onclick="viewMaintenanceDetails('<?= $maintenanceInfo ? htmlspecialchars($maintenanceInfo['id']) : ''; ?>', '<?= $dateStr; ?>')">
                    <span style="font-size: 0.9rem;"><?= $day; ?></span>
                    <?php if ($hasMaintenance): ?>
                        <span style="font-size: 0.7rem; color: #856404; margin-top: 0.25rem;">🔧</span>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
        
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <div style="width: 20px; height: 20px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 4px;"></div>
                    <small style="color: #8b95b5;">Maintenance Scheduled</small>
                </div>
            </div>
        </div>
    </aside>
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
                            <td><strong><?= htmlspecialchars($history['id']); ?></strong></td>
                            <td><?= htmlspecialchars($history['facility_name']); ?></td>
                            <td><?= htmlspecialchars($history['maintenance_type']); ?></td>
                            <td><?= date('M d, Y H:i', strtotime($history['completed_at'])); ?></td>
                            <td><?= htmlspecialchars($history['duration']); ?></td>
                            <td><?= htmlspecialchars($history['technician']); ?></td>
                            <td>
                                <span class="status-badge active">Completed</span>
                            </td>
                            <td>
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

<!-- Maintenance Details Modal (will be implemented with JavaScript) -->
<div id="maintenanceModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 id="modalTitle">Maintenance Details</h3>
            <button onclick="closeMaintenanceModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <div id="modalContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<script>
function syncMaintenanceData() {
    // Placeholder for API call
    alert('Sync functionality will be implemented when API integration is available.');
}

function filterMaintenance() {
    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;
    const rows = document.querySelectorAll('#maintenanceTableBody tr');
    
    rows.forEach(row => {
        const rowStatus = row.dataset.status;
        const rowPriority = row.dataset.priority;
        
        const statusMatch = statusFilter === 'all' || rowStatus === statusFilter;
        const priorityMatch = priorityFilter === 'all' || rowPriority === priorityFilter;
        
        row.style.display = (statusMatch && priorityMatch) ? '' : 'none';
    });
}

function updateMaintenanceCalendar() {
    // Placeholder for calendar update
    const facilityId = document.getElementById('calendarFacility').value;
    console.log('Updating calendar for facility:', facilityId);
    // Will filter maintenance schedules by facility when API is integrated
}

function viewMaintenanceDetails(maintenanceId, date = null) {
    if (!maintenanceId && !date) return;
    
    // Mock data - will be replaced with API call
    const modal = document.getElementById('maintenanceModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = maintenanceId ? `Maintenance: ${maintenanceId}` : `Maintenance on ${date}`;
    
    // Mock content
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Facility:</strong> Community Convention Hall<br>
            <strong>Type:</strong> Routine Inspection<br>
            <strong>Scheduled:</strong> January 15, 2025 08:00 - 12:00<br>
            <strong>Priority:</strong> Medium<br>
            <strong>Status:</strong> Scheduled<br>
            <strong>Team:</strong> Maintenance Team A<br>
            <strong>Affected Reservations:</strong> 2
        </div>
        <div style="margin-bottom: 1rem;">
            <strong>Description:</strong><br>
            <p style="color: #8b95b5; margin-top: 0.5rem;">Monthly routine inspection of electrical systems and HVAC units.</p>
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <small style="color: #8b95b5;">
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
            <p style="color: #8b95b5; margin-top: 0.5rem;">All systems operational. No issues found.</p>
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
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

