<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !in_array($role, ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../services/cimm_api.php';

$pdo = db();
$pageTitle = 'Maintenance Integration | LGU Facilities Reservation';

// Fetch maintenance schedules from CIMM API
$apiResult = fetchCIMMMaintenanceSchedules();
$rawSchedules = $apiResult['data'] ?? [];
$apiError = $apiResult['error'] ?? null;
$maintenanceSchedules = mapCIMMToCPRF($rawSchedules);

// Separate completed schedules for history
$mockMaintenanceHistory = [];
$upcomingSchedules = [];
foreach ($maintenanceSchedules as $schedule) {
    if (strtolower($schedule['status']) === 'completed') {
        $mockMaintenanceHistory[] = [
            'id' => $schedule['id'],
            'facility_name' => $schedule['facility_name'],
            'maintenance_type' => $schedule['maintenance_type'],
            'completed_at' => $schedule['scheduled_end'],
            'status' => 'completed',
            'duration' => $schedule['estimated_duration'],
            'technician' => $schedule['assigned_team'],
            'notes' => $schedule['description'],
        ];
    } else {
        $upcomingSchedules[] = $schedule;
    }
}

// Use upcoming schedules for the main list
$mockMaintenanceSchedules = $upcomingSchedules;

// Get real facilities for dropdown
$facilities = [];
try {
    $facilitiesStmt = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore error
}

// Integration status (real check)
$integrationStatus = [
    'connected' => !empty($rawSchedules) && empty($apiError),
    'last_sync' => date('Y-m-d H:i:s'),
    'sync_status' => empty($apiError) && !empty($rawSchedules) ? 'success' : (empty($apiError) ? 'no_data' : 'failed'),
    'pending_updates' => 0,
    'error' => $apiError,
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
            <?php if (!empty($integrationStatus['error'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #fee2e2; border-left: 4px solid #dc2626; border-radius: 4px;">
                    <strong style="color: #dc2626; display: block; margin-bottom: 0.25rem;">Connection Error:</strong>
                    <small style="color: #991b1b;"><?= htmlspecialchars($integrationStatus['error']); ?></small>
                    <div style="margin-top: 0.5rem;">
                        <small style="color: #991b1b;">
                            <strong>Solution:</strong> Ensure CIMM has set up their API endpoint at 
                            <code style="background: rgba(0,0,0,0.1); padding: 2px 4px; border-radius: 2px;">https://cimm.infragovservices.com/api/maintenance-schedules.php</code>
                            <br>See <code>docs/CIMM_API_INTEGRATION.md</code> for setup instructions.
                        </small>
                    </div>
                </div>
            <?php elseif (empty($rawSchedules) && empty($integrationStatus['error'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;">
                    <small style="color: #92400e;">
                        <strong>Note:</strong> Connected successfully but no maintenance schedules found. 
                        CIMM may not have any scheduled maintenance yet.
                    </small>
                </div>
            <?php endif; ?>
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

    <!-- Maintenance Calendar (New Design) -->
    <aside class="booking-card maintenance-calendar-wrapper">
        <h2>Maintenance Calendar</h2>
        
        <!-- Mobile Controls (Mobile Only) -->
        <div class="mobile-controls" id="mobileListControls" style="display:none;">
            <input id="mobileScheduleSearch" type="text" placeholder="Search schedules...">
            <button id="mobileToCalendarBtn" class="mobile-calendar-btn">📅</button>
        </div>
        <div class="mobile-controls" id="mobileCalendarControls" style="display:none;">
            <button id="mobilePrevMonth" class="mobile-toggle-btn">&#8592;</button>
            <span id="mobileMonthLabel" title="Click to jump date"></span>
            <button id="mobileToListBtn" class="mobile-schedule-btn">📋</button>
            <button id="mobileNextMonth" class="mobile-toggle-btn">&#8594;</button>
        </div>

        <!-- Calendar View -->
        <div id="calendarView">
            <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel" title="Click to jump date"></span>
                <div style="display:flex; gap:8px;">
                    <button id="toListBtn" class="schedule-btn" title="Schedule List">📋</button>
                    <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">&#8594;</button>
                </div>
            </div>
            <div class="calendar-weekdays">
                <div>Sunday</div>
                <div>Monday</div>
                <div>Tuesday</div>
                <div>Wednesday</div>
                <div>Thursday</div>
                <div>Friday</div>
                <div>Saturday</div>
            </div>
            <div class="calendar-grid" id="calendarGrid"></div>
            <div class="calendar-details-card">
                <div class="calendar-details" id="calendarDetails">
                    Select a date to view schedule.
                </div>
                <div class="scroll-indicator">⌄</div>
            </div>
        </div>
        
        <!-- List View -->
        <div id="scheduleView" class="hidden">
            <div style="display:flex; gap:10px; align-items:center;">
                <input id="scheduleSearch" type="text" placeholder="Search by task, location, category, status, or date..." style="flex:1;">
                <button id="toCalendarBtn" class="calendar-btn" title="Calendar View">📅</button>
            </div>
            <div id="scheduleListHolder">
                <?php if (empty($mockMaintenanceSchedules)): ?>
                    <p id="noScheduleMsg">No scheduled maintenance.</p>
                <?php else: 
                    foreach ($mockMaintenanceSchedules as $row): 
                        $scheduleDate = date('Y-m-d', strtotime($row['scheduled_start'] ?? 'now'));
                ?>
                    <div class="schedule-item"
                        data-task="<?= htmlspecialchars(strtolower($row['maintenance_type'] ?? $row['task'] ?? '')) ?>"
                        data-location="<?= htmlspecialchars(strtolower($row['facility_name'] ?? $row['location'] ?? '')) ?>"
                        data-category="<?= htmlspecialchars(strtolower($row['category'] ?? '')) ?>"
                        data-status="<?= htmlspecialchars(strtolower($row['status_label'] ?? $row['status'] ?? '')) ?>"
                        data-priority="<?= htmlspecialchars(strtolower($row['priority'] ?? '')) ?>"
                        data-date="<?= htmlspecialchars(strtolower(date("F d, Y", strtotime($scheduleDate)) . '|' . $scheduleDate)) ?>">
                        <div>
                            <strong><?= htmlspecialchars($row['maintenance_type'] ?? $row['task'] ?? 'Maintenance') ?></strong><br>
                            <?= htmlspecialchars($row['facility_name'] ?? $row['location'] ?? '') ?><br>
                            <?php if (!empty($row['category'])): ?>
                                <span class="badge badge-category"><?= htmlspecialchars($row['category']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="schedule-date">
                            <?= date("F d, Y", strtotime($scheduleDate)) ?><br>
                            <?php
                                $priorityClass = 'badge-priority-low';
                                $priorityLower = strtolower($row['priority'] ?? '');
                                if ($priorityLower === 'medium') {
                                    $priorityClass = 'badge-priority-medium';
                                } elseif ($priorityLower === 'high') {
                                    $priorityClass = 'badge-priority-high';
                                } elseif ($priorityLower === 'critical') {
                                    $priorityClass = 'badge-priority-critical';
                                }

                                $statusClass = 'badge-status-planned';
                                $statusLower = strtolower($row['status_label'] ?? $row['status'] ?? '');
                                if ($statusLower === 'completed') {
                                    $statusClass = 'badge-status-completed';
                                } elseif ($statusLower === 'in progress' || $statusLower === 'in_progress') {
                                    $statusClass = 'badge-status-in-progress';
                                } elseif ($statusLower === 'delayed') {
                                    $statusClass = 'badge-status-delayed';
                                } elseif ($statusLower === 'scheduled') {
                                    $statusClass = 'badge-status-scheduled';
                                }
                            ?>
                            <?php if (!empty($row['status_label'])): ?>
                                <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($row['status_label']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['priority'])): ?>
                                <span class="badge <?= $priorityClass ?>"><?= htmlspecialchars($row['priority']) ?> priority</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                    <p id="noResultMsg" style="display:none;">No matching data or result.</p>
                <?php endif; ?>
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
    const btn = event.target;
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '🔄 Syncing...';
    
    // Reload page to fetch fresh data from API
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Test CIMM connection (for debugging)
function testCIMMConnection() {
    alert('To test CIMM connection, run: php test_cimm_connection.php\n\nOr check the error message displayed in the Integration Status card.');
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
    
    const modal = document.getElementById('maintenanceModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    // Find the schedule from window.scheduleData
    let schedule = null;
    if (maintenanceId) {
        const idMatch = maintenanceId.match(/CIMM-(\d+)/);
        if (idMatch) {
            schedule = window.scheduleData.find(s => s.sched_id == idMatch[1]);
        }
    } else if (date) {
        schedule = window.scheduleData.find(s => s.schedule_date === date);
    }
    
    if (!schedule) {
        modalTitle.textContent = maintenanceId ? `Maintenance: ${maintenanceId}` : `Maintenance on ${date}`;
        modalContent.innerHTML = '<p>Schedule details not found.</p>';
        modal.style.display = 'flex';
        return;
    }
    
    modalTitle.textContent = `Maintenance: ${schedule.task || 'Maintenance'}`;
    
    const startDate = schedule.starting_date ? new Date(schedule.starting_date).toLocaleString() : 'N/A';
    const endDate = schedule.estimated_completion_date ? new Date(schedule.estimated_completion_date).toLocaleString() : 'N/A';
    
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Facility:</strong> ${schedule.location || 'N/A'}<br>
            <strong>Type:</strong> ${schedule.task || 'N/A'}<br>
            <strong>Scheduled:</strong> ${startDate} - ${endDate}<br>
            <strong>Priority:</strong> ${schedule.priority || 'N/A'}<br>
            <strong>Status:</strong> ${schedule.status_label || schedule.status || 'N/A'}<br>
            <strong>Team:</strong> ${schedule.assigned_team || 'N/A'}<br>
            <strong>Category:</strong> ${schedule.category || 'General Maintenance'}
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

// =============== SCHEDULE DATA FOR CALENDAR ===============
window.scheduleData = <?= json_encode(array_map(function($schedule) {
    return [
        'sched_id' => $schedule['sched_id'] ?? '',
        'task' => $schedule['maintenance_type'] ?? $schedule['task'] ?? '',
        'location' => $schedule['facility_name'] ?? $schedule['location'] ?? '',
        'category' => $schedule['category'] ?? 'General Maintenance',
        'priority' => ucfirst($schedule['priority'] ?? 'Low'),
        'status' => $schedule['status_label'] ?? $schedule['status'] ?? 'Scheduled',
        'status_label' => $schedule['status_label'] ?? $schedule['status'] ?? 'Scheduled',
        'assigned_team' => $schedule['assigned_team'] ?? '',
        'starting_date' => $schedule['scheduled_start'] ?? '',
        'estimated_completion_date' => $schedule['scheduled_end'] ?? '',
        'schedule_date' => date('Y-m-d', strtotime($schedule['scheduled_start'] ?? 'now'))
    ];
}, $maintenanceSchedules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// ============ NEW CALENDAR FUNCTIONALITY ============
(function() {
    'use strict';
    
    function isMobileView() {
        return window.innerWidth <= 768;
    }
    
    const calendarGrid = document.getElementById('calendarGrid');
    const calendarDetails = document.getElementById('calendarDetails');
    const monthLabel = document.getElementById('monthLabel');
    const mobileMonthLabel = document.getElementById('mobileMonthLabel');
    const calendarView = document.getElementById('calendarView');
    const scheduleView = document.getElementById('scheduleView');
    const scheduleSearch = document.getElementById('scheduleSearch');
    const scheduleListHolder = document.getElementById('scheduleListHolder');
    const noResultMsg = document.getElementById('noResultMsg');
    const toCalendarBtn = document.getElementById('toCalendarBtn');
    const toListBtn = document.getElementById('toListBtn');
    const mobileListControls = document.getElementById('mobileListControls');
    const mobileCalendarControls = document.getElementById('mobileCalendarControls');
    const mobileToCalendarBtn = document.getElementById('mobileToCalendarBtn');
    const mobileToListBtn = document.getElementById('mobileToListBtn');
    const mobilePrevMonth = document.getElementById('mobilePrevMonth');
    const mobileNextMonth = document.getElementById('mobileNextMonth');
    const mobileScheduleSearch = document.getElementById('mobileScheduleSearch');
    const prevMonthBtn = document.getElementById('prevMonth');
    const nextMonthBtn = document.getElementById('nextMonth');
    
    if (!calendarGrid || !calendarDetails) return;
    
    let currentDate = new Date();
    let showingCalendar = true;
    
    function getStatusKey(statusLabel) {
        const s = (statusLabel || '').toLowerCase();
        if (!s) return 'upcoming';
        if (s.indexOf('delay') !== -1) return 'delayed';
        if (s.indexOf('progress') !== -1 || s.indexOf('on-going') !== -1 || s.indexOf('ongoing') !== -1) return 'ongoing';
        if (s.indexOf('completed') !== -1) return 'completed';
        return 'upcoming';
    }
    
    function renderCalendar() {
        if (!calendarGrid || !calendarDetails) return;
        calendarGrid.innerHTML = '';
        calendarDetails.innerHTML = 'Select a date to view schedule.';
        
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const monthText = currentDate.toLocaleString('default', {month: 'long', year: 'numeric'});
        if (monthLabel) monthLabel.textContent = monthText;
        if (mobileMonthLabel) mobileMonthLabel.textContent = monthText;
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        for (let i = 0; i < firstDay; i++) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'calendar-day';
            calendarGrid.appendChild(emptyDiv);
        }
        
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const events = Array.isArray(window.scheduleData) && window.scheduleData.length
                ? window.scheduleData.filter(e => e.schedule_date === dateStr)
                : [];
            
            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');
            dayDiv.setAttribute('data-date', dateStr);
            
            const dayNumDiv = document.createElement('div');
            dayNumDiv.textContent = d;
            dayDiv.appendChild(dayNumDiv);
            
            if (events.length) {
                const tasksDiv = document.createElement('div');
                tasksDiv.className = 'day-tasks';
                
                if (events.length === 1) {
                    const e = events[0];
                    const btn = document.createElement('button');
                    btn.className = 'task-btn';
                    btn.textContent = isMobileView() ? '1' : (e.task || 'Maintenance');
                    btn.title = `${e.task || 'Maintenance'} (${e.status_label || ''})`;
                    const key = getStatusKey(e.status_label);
                    if (key) btn.classList.add('status-' + key + '-bg');
                    btn.onclick = function(ev) {
                        ev.stopPropagation();
                        viewMaintenanceDetails(e.sched_id ? 'CIMM-' + e.sched_id : '', dateStr);
                    };
                    tasksDiv.appendChild(btn);
                } else if (events.length > 1) {
                    const first = events[0];
                    const firstBtn = document.createElement('button');
                    firstBtn.className = 'task-btn';
                    firstBtn.textContent = isMobileView() ? '1' : (first.task || 'Maintenance');
                    firstBtn.title = `${first.task || 'Maintenance'} (${first.status_label || ''})`;
                    const firstKey = getStatusKey(first.status_label);
                    if (firstKey) firstBtn.classList.add('status-' + firstKey + '-bg');
                    firstBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        viewMaintenanceDetails(first.sched_id ? 'CIMM-' + first.sched_id : '', dateStr);
                    };
                    tasksDiv.appendChild(firstBtn);
                    
                    const moreWrap = document.createElement('div');
                    moreWrap.className = 'more-tasks-wrap';
                    const arrowBtn = document.createElement('button');
                    arrowBtn.className = 'more-tasks-btn';
                    arrowBtn.innerHTML = '▾';
                    arrowBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        const tasks = events.map(e => ({
                            sched_id: e.sched_id,
                            task: e.task,
                            location: e.location,
                            category: e.category,
                            priority: e.priority,
                            status_label: e.status_label,
                            assigned_team: e.assigned_team,
                            schedule_date: dateStr
                        }));
                        openTaskChooser(dateStr, tasks);
                    };
                    moreWrap.appendChild(arrowBtn);
                    if (!isMobileView()) {
                        const counter = document.createElement('span');
                        counter.className = 'task-counter';
                        counter.textContent = `+${events.length - 1}`;
                        moreWrap.appendChild(counter);
                    }
                    tasksDiv.appendChild(moreWrap);
                }
                dayDiv.appendChild(tasksDiv);
            }
            
            dayDiv.addEventListener('click', function() {
                if (events.length) {
                    let detailsHtml = `<strong>${dateStr}</strong><br>`;
                    detailsHtml += events.map(e => `• ${e.task || 'Maintenance'} – ${e.location || ''}`).join('<br>');
                    calendarDetails.innerHTML = detailsHtml;
                } else {
                    calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>No scheduled maintenance.`;
                }
            });
            
            calendarGrid.appendChild(dayDiv);
        }
    }
    
    function openTaskChooser(date, tasks) {
        const modal = document.getElementById('maintenanceModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalContent = document.getElementById('modalContent');
        
        modalTitle.textContent = `Select a Task - ${date}`;
        modalContent.innerHTML = '';
        
        tasks.forEach(t => {
            const btn = document.createElement('button');
            btn.className = 'btn-outline';
            btn.style.cssText = 'width: 100%; margin: 0.5rem 0; padding: 0.75rem; text-align: left;';
            btn.textContent = `${t.task || 'Maintenance'} – ${t.location || ''}`;
            btn.onclick = () => {
                modal.style.display = 'none';
                viewMaintenanceDetails(t.sched_id ? 'CIMM-' + t.sched_id : '', date);
            };
            modalContent.appendChild(btn);
        });
        
        modal.style.display = 'flex';
    }
    
    function showCalendarView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.remove('hidden');
        scheduleView.classList.add('hidden');
        showingCalendar = true;
        updateMobileControls();
    }
    
    function showListView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.add('hidden');
        scheduleView.classList.remove('hidden');
        showingCalendar = false;
        updateMobileControls();
    }
    
    function updateMobileControls() {
        if (!mobileListControls || !mobileCalendarControls) return;
        if (!isMobileView()) {
            mobileListControls.style.display = 'none';
            mobileCalendarControls.style.display = 'none';
            return;
        }
        if (showingCalendar) {
            mobileCalendarControls.style.display = '';
            mobileListControls.style.display = 'none';
            if (mobileMonthLabel && monthLabel) {
                mobileMonthLabel.textContent = monthLabel.textContent;
            }
        } else {
            mobileListControls.style.display = '';
            mobileCalendarControls.style.display = 'none';
        }
    }
    
    if (prevMonthBtn) prevMonthBtn.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    };
    if (nextMonthBtn) nextMonthBtn.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    };
    if (toCalendarBtn) toCalendarBtn.onclick = showCalendarView;
    if (toListBtn) toListBtn.onclick = showListView;
    if (mobileToCalendarBtn) mobileToCalendarBtn.onclick = showCalendarView;
    if (mobileToListBtn) mobileToListBtn.onclick = showListView;
    if (mobilePrevMonth) mobilePrevMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
        updateMobileControls();
    };
    if (mobileNextMonth) mobileNextMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
        updateMobileControls();
    };
    
    if (scheduleSearch && scheduleListHolder) {
        scheduleSearch.addEventListener('input', function() {
            const searchVal = this.value.trim().toLowerCase();
            const items = scheduleListHolder.querySelectorAll('.schedule-item');
            let shownCount = 0;
            if (!searchVal.length) {
                items.forEach(i => i.style.display = '');
                if (noResultMsg) noResultMsg.style.display = 'none';
                return;
            }
            items.forEach(item => {
                const task = item.getAttribute('data-task') || '';
                const loc = item.getAttribute('data-location') || '';
                const date = item.getAttribute('data-date') || '';
                const cat = item.getAttribute('data-category') || '';
                const stat = item.getAttribute('data-status') || '';
                const prio = item.getAttribute('data-priority') || '';
                if (task.includes(searchVal) || loc.includes(searchVal) || date.includes(searchVal) || 
                    cat.includes(searchVal) || stat.includes(searchVal) || prio.includes(searchVal)) {
                    item.style.display = '';
                    shownCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            if (noResultMsg) {
                noResultMsg.style.display = shownCount === 0 ? '' : 'none';
            }
        });
    }
    
    if (mobileScheduleSearch && scheduleSearch) {
        mobileScheduleSearch.addEventListener('input', e => {
            scheduleSearch.value = e.target.value;
            scheduleSearch.dispatchEvent(new Event('input'));
        });
    }
    
    window.addEventListener('resize', updateMobileControls);
    renderCalendar();
    updateMobileControls();
    
    function updateWeekdayLabels() {
        const desktopDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const shortDays = ['S','M','T','W','T','F','S'];
        const weekdayDivs = document.querySelectorAll('.calendar-weekdays div');
        if (!weekdayDivs.length) return;
        if (window.innerWidth <= 768) {
            weekdayDivs.forEach((el, i) => el.textContent = shortDays[i]);
        } else {
            weekdayDivs.forEach((el, i) => el.textContent = desktopDays[i]);
        }
    }
    
    window.addEventListener('load', updateWeekdayLabels);
    window.addEventListener('resize', updateWeekdayLabels);
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

