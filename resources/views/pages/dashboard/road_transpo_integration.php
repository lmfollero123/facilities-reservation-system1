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
$pageTitle = 'Road & Transportation Integration | LGU Facilities Reservation';

// Mock data for road closures (will be replaced with API integration)
$mockRoadClosures = [
    [
        'id' => 'CLOSURE-2025-001',
        'road_name' => 'Main Street (Zone 1)',
        'closure_type' => 'Full Closure',
        'start_date' => '2025-01-25',
        'end_date' => '2025-01-27',
        'start_time' => '06:00',
        'end_time' => '18:00',
        'reason' => 'Road repair and resurfacing',
        'affected_facilities' => ['Community Convention Hall', 'People\'s Park Amphitheater'],
        'traffic_impact' => 'High',
        'alternative_routes' => 'Use Zone 2 access road via Secondary Street',
        'status' => 'scheduled',
        'created_at' => '2025-01-20 09:00:00',
    ],
    [
        'id' => 'CLOSURE-2025-002',
        'road_name' => 'Sports Complex Access Road',
        'closure_type' => 'Partial Closure',
        'start_date' => '2025-01-28',
        'end_date' => '2025-01-28',
        'start_time' => '09:00',
        'end_time' => '15:00',
        'reason' => 'Drainage system maintenance',
        'affected_facilities' => ['Municipal Sports Complex'],
        'traffic_impact' => 'Medium',
        'alternative_routes' => 'Use main entrance via Highway 1',
        'status' => 'scheduled',
        'created_at' => '2025-01-21 11:30:00',
    ],
];

// Mock traffic alerts
$mockTrafficAlerts = [
    [
        'id' => 'TRAFFIC-2025-001',
        'location' => 'Zone 1 - Main Street',
        'alert_type' => 'Heavy Traffic',
        'severity' => 'Medium',
        'expected_duration' => '2 hours',
        'time' => '2025-01-22 17:00:00',
        'description' => 'Heavy traffic expected due to community event',
        'affected_facilities' => ['Community Convention Hall'],
    ],
    [
        'id' => 'TRAFFIC-2025-002',
        'location' => 'Zone 2 - Sports Complex Area',
        'alert_type' => 'Traffic Jam',
        'severity' => 'High',
        'expected_duration' => '1 hour',
        'time' => '2025-01-23 08:00:00',
        'description' => 'Traffic jam due to morning rush and school drop-off',
        'affected_facilities' => ['Municipal Sports Complex'],
    ],
];

// Get real facilities for reference
$facilities = [];
try {
    $facilitiesStmt = $pdo->query('SELECT id, name, location FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore error
}

// Integration status (mock - will be replaced with actual API health check)
$integrationStatus = [
    'connected' => true,
    'last_sync' => '2025-01-20 14:20:35',
    'sync_status' => 'success',
    'active_closures' => 2,
    'traffic_alerts' => 2,
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Road & Transportation</span>
    </div>
    <h1>Road & Transportation Infrastructure Monitoring</h1>
    <small>Monitor road closures and traffic alerts that may affect facility accessibility.</small>
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
                <?php if ($integrationStatus['active_closures'] > 0): ?>
                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        🚧 <?= $integrationStatus['active_closures']; ?> active closure(s)
                    </span>
                <?php endif; ?>
                <?php if ($integrationStatus['traffic_alerts'] > 0): ?>
                    <span style="background: #f8d7da; color: #721c24; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        ⚠️ <?= $integrationStatus['traffic_alerts']; ?> traffic alert(s)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn-outline" onclick="syncTrafficData()" style="padding: 0.5rem 1rem;">
            🔄 Sync Now
        </button>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>Note:</strong> This integration connects to the Road and Transportation Infrastructure Monitoring system. 
            Road closures and traffic alerts automatically notify users with affected reservations.
        </small>
    </div>
</div>

<div class="booking-wrapper">
    <!-- Road Closures -->
    <section class="booking-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>Road Closures</h2>
            <select id="filterClosureType" onchange="filterClosures()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                <option value="all">All Types</option>
                <option value="Full Closure">Full Closure</option>
                <option value="Partial Closure">Partial Closure</option>
            </select>
        </div>

        <?php if (empty($mockRoadClosures)): ?>
            <p style="color: #8b95b5; text-align: center; padding: 2rem;">No road closures scheduled.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Closure ID</th>
                            <th>Road Name</th>
                            <th>Type</th>
                            <th>Date & Time</th>
                            <th>Reason</th>
                            <th>Affected Facilities</th>
                            <th>Traffic Impact</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="closuresTableBody">
                        <?php foreach ($mockRoadClosures as $closure): 
                            $statusClass = $closure['status'] === 'active' ? 'offline' : 
                                         ($closure['status'] === 'scheduled' ? 'maintenance' : 'active');
                            $statusDisplay = ucfirst($closure['status']);
                            $impactClass = $closure['traffic_impact'] === 'High' ? 'offline' : 
                                         ($closure['traffic_impact'] === 'Medium' ? 'maintenance' : 'active');
                        ?>
                            <tr data-type="<?= $closure['closure_type']; ?>">
                                <td><strong><?= htmlspecialchars($closure['id']); ?></strong></td>
                                <td><?= htmlspecialchars($closure['road_name']); ?></td>
                                <td>
                                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                        <?= htmlspecialchars($closure['closure_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('M d', strtotime($closure['start_date'])); ?> - 
                                    <?= date('M d', strtotime($closure['end_date'])); ?><br>
                                    <small style="color: #8b95b5;">
                                        <?= $closure['start_time']; ?> - <?= $closure['end_time']; ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($closure['reason']); ?></td>
                                <td>
                                    <?php foreach ($closure['affected_facilities'] as $facility): ?>
                                        <div style="font-size: 0.85rem; margin-bottom: 0.25rem;">
                                            <?= htmlspecialchars($facility); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $impactClass; ?>">
                                        <?= htmlspecialchars($closure['traffic_impact']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $statusClass; ?>">
                                        <?= $statusDisplay; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn-outline" onclick="viewClosureDetails('<?= htmlspecialchars($closure['id']); ?>')" style="padding: 0.35rem 0.6rem; font-size: 0.85rem;">
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

    <!-- Traffic Alerts -->
    <aside class="booking-card">
        <h2>Traffic Alerts</h2>
        <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
            Real-time traffic alerts affecting facility access.
        </p>
        
        <?php if (empty($mockTrafficAlerts)): ?>
            <p style="color: #8b95b5; text-align: center; padding: 2rem;">No active traffic alerts.</p>
        <?php else: ?>
            <?php foreach ($mockTrafficAlerts as $alert): 
                $severityClass = $alert['severity'] === 'High' ? 'offline' : 
                               ($alert['severity'] === 'Medium' ? 'maintenance' : 'active');
            ?>
                <div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #ffc107;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <div>
                            <strong style="font-size: 0.95rem;"><?= htmlspecialchars($alert['location']); ?></strong>
                            <br><small style="color: #8b95b5;"><?= htmlspecialchars($alert['alert_type']); ?></small>
                        </div>
                        <span class="status-badge <?= $severityClass; ?>" style="font-size: 0.8rem;">
                            <?= htmlspecialchars($alert['severity']); ?>
                        </span>
                    </div>
                    <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">
                        <strong>Time:</strong> <?= date('M d, Y H:i', strtotime($alert['time'])); ?><br>
                        <strong>Duration:</strong> <?= htmlspecialchars($alert['expected_duration']); ?>
                    </div>
                    <div style="font-size: 0.85rem; margin-bottom: 0.5rem;">
                        <?= htmlspecialchars($alert['description']); ?>
                    </div>
                    <div style="font-size: 0.85rem; color: #8b95b5;">
                        <strong>Affected:</strong> <?= implode(', ', $alert['affected_facilities']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </aside>
</div>

<!-- Parking Availability Section -->
<section class="booking-card" style="margin-top: 1.5rem;">
    <h2>Parking Availability</h2>
    <p style="color: #8b95b5; margin-bottom: 1rem;">
        Real-time parking availability information for facilities (if parking management is integrated).
    </p>
    
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Facility</th>
                    <th>Location</th>
                    <th>Parking Capacity</th>
                    <th>Current Availability</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facilities as $facility): ?>
                    <tr>
                        <td><?= htmlspecialchars($facility['name']); ?></td>
                        <td><?= htmlspecialchars($facility['location'] ?? 'N/A'); ?></td>
                        <td>50 spaces</td>
                        <td>35 available</td>
                        <td>
                            <span class="status-badge active">Available</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>Note:</strong> Parking availability data will be displayed here when parking management system integration is available.
        </small>
    </div>
</section>

<!-- Closure Details Modal -->
<div id="closureModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 id="modalTitle">Road Closure Details</h3>
            <button onclick="closeClosureModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <div id="modalContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<script>
function syncTrafficData() {
    // Placeholder for API call
    alert('Sync functionality will be implemented when API integration is available.');
}

function filterClosures() {
    const typeFilter = document.getElementById('filterClosureType').value;
    const rows = document.querySelectorAll('#closuresTableBody tr');
    
    rows.forEach(row => {
        const rowType = row.dataset.type;
        const typeMatch = typeFilter === 'all' || rowType === typeFilter;
        row.style.display = typeMatch ? '' : 'none';
    });
}

function viewClosureDetails(closureId) {
    // Mock data - will be replaced with API call
    const modal = document.getElementById('closureModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = `Road Closure: ${closureId}`;
    
    // Mock content
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Road Name:</strong> Main Street (Zone 1)<br>
            <strong>Type:</strong> Full Closure<br>
            <strong>Date:</strong> January 25 - 27, 2025<br>
            <strong>Time:</strong> 06:00 - 18:00<br>
            <strong>Reason:</strong> Road repair and resurfacing<br>
            <strong>Traffic Impact:</strong> High<br>
            <strong>Status:</strong> Scheduled
        </div>
        <div style="margin-bottom: 1rem;">
            <strong>Affected Facilities:</strong><br>
            <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                <li>Community Convention Hall</li>
                <li>People's Park Amphitheater</li>
            </ul>
        </div>
        <div style="margin-bottom: 1rem;">
            <strong>Alternative Routes:</strong><br>
            <p style="color: #8b95b5; margin-top: 0.5rem;">Use Zone 2 access road via Secondary Street</p>
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <small style="color: #8b95b5;">
                <strong>Note:</strong> Users with reservations at affected facilities will be automatically notified 
                about the road closure and provided with alternative route information.
            </small>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function closeClosureModal() {
    document.getElementById('closureModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('closureModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeClosureModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

