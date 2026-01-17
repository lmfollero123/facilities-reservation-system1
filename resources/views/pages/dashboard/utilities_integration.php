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
$pdo = db();
$pageTitle = 'Utilities Integration | LGU Facilities Reservation';

// Mock data for utility outages (will be replaced with API integration)
$mockUtilityOutages = [
    [
        'id' => 'OUTAGE-2025-001',
        'utility_type' => 'Water',
        'facility_id' => 1,
        'facility_name' => 'Community Convention Hall',
        'scheduled_start' => '2025-01-22 08:00:00',
        'scheduled_end' => '2025-01-22 14:00:00',
        'status' => 'scheduled',
        'reason' => 'Water main repair in Zone 1',
        'affected_reservations' => 1,
        'created_at' => '2025-01-20 10:00:00',
    ],
    [
        'id' => 'OUTAGE-2025-002',
        'utility_type' => 'Electricity',
        'facility_id' => 2,
        'facility_name' => 'Municipal Sports Complex',
        'scheduled_start' => '2025-01-25 09:00:00',
        'scheduled_end' => '2025-01-25 12:00:00',
        'status' => 'scheduled',
        'reason' => 'Electrical system maintenance',
        'affected_reservations' => 2,
        'created_at' => '2025-01-21 14:30:00',
    ],
];

// Mock utility cost tracking data
$mockUtilityCosts = [
    [
        'facility_name' => 'Community Convention Hall',
        'month' => 'December 2024',
        'electricity_cost' => 12500,
        'water_cost' => 3200,
        'total_cost' => 15700,
        'usage_hours' => 180,
        'cost_per_hour' => 87.22,
        'reservations_count' => 45,
    ],
    [
        'facility_name' => 'Municipal Sports Complex',
        'month' => 'December 2024',
        'electricity_cost' => 9800,
        'water_cost' => 2100,
        'total_cost' => 11900,
        'usage_hours' => 240,
        'cost_per_hour' => 49.58,
        'reservations_count' => 38,
    ],
    [
        'facility_name' => 'People\'s Park Amphitheater',
        'month' => 'December 2024',
        'electricity_cost' => 6500,
        'water_cost' => 1500,
        'total_cost' => 8000,
        'usage_hours' => 120,
        'cost_per_hour' => 66.67,
        'reservations_count' => 32,
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
    'last_sync' => '2025-01-20 13:45:20',
    'sync_status' => 'success',
    'active_outages' => 2,
    'pending_alerts' => 0,
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Utilities Integration</span>
    </div>
    <h1>Utilities Billing & Management Integration</h1>
    <small>Monitor utility outages and track facility utility costs and consumption.</small>
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
                <?php if ($integrationStatus['active_outages'] > 0): ?>
                    <span style="background: #f8d7da; color: #721c24; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        ⚠️ <?= $integrationStatus['active_outages']; ?> active outage(s)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn-outline" onclick="syncUtilityData()" style="padding: 0.5rem 1rem;">
            🔄 Sync Now
        </button>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>Note:</strong> This integration connects to the Utilities Billing & Management system. 
            Utility outages automatically block facilities, and usage data is shared for billing reconciliation.
        </small>
    </div>
</div>

<div class="booking-wrapper">
    <!-- Utility Outage Alerts -->
    <section class="booking-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>Utility Outage Alerts</h2>
            <select id="filterOutageType" onchange="filterOutages()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                <option value="all">All Types</option>
                <option value="Water">Water</option>
                <option value="Electricity">Electricity</option>
                <option value="Internet">Internet</option>
            </select>
        </div>

        <?php if (empty($mockUtilityOutages)): ?>
            <p style="color: #8b95b5; text-align: center; padding: 2rem;">No utility outages scheduled.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Outage ID</th>
                            <th>Utility Type</th>
                            <th>Facility</th>
                            <th>Scheduled Time</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Affected</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="outagesTableBody">
                        <?php foreach ($mockUtilityOutages as $outage): 
                            $statusClass = $outage['status'] === 'active' ? 'offline' : 
                                         ($outage['status'] === 'scheduled' ? 'maintenance' : 'active');
                            $statusDisplay = ucfirst($outage['status']);
                            $utilityIcon = $outage['utility_type'] === 'Water' ? '💧' : 
                                         ($outage['utility_type'] === 'Electricity' ? '⚡' : '📡');
                        ?>
                            <tr data-type="<?= $outage['utility_type']; ?>">
                                <td><strong><?= htmlspecialchars($outage['id']); ?></strong></td>
                                <td>
                                    <span style="display: flex; align-items: center; gap: 0.5rem;">
                                        <?= $utilityIcon; ?>
                                        <?= htmlspecialchars($outage['utility_type']); ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($outage['facility_name']); ?></td>
                                <td>
                                    <?= date('M d, Y', strtotime($outage['scheduled_start'])); ?><br>
                                    <small style="color: #8b95b5;">
                                        <?= date('H:i', strtotime($outage['scheduled_start'])); ?> - 
                                        <?= date('H:i', strtotime($outage['scheduled_end'])); ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($outage['reason']); ?></td>
                                <td>
                                    <span class="status-badge <?= $statusClass; ?>">
                                        <?= $statusDisplay; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($outage['affected_reservations'] > 0): ?>
                                        <span style="color: #dc3545; font-weight: 600;">
                                            <?= $outage['affected_reservations']; ?> reservation(s)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #8b95b5;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-outline" onclick="viewOutageDetails('<?= htmlspecialchars($outage['id']); ?>')" style="padding: 0.35rem 0.6rem; font-size: 0.85rem;">
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

    <!-- Utility Cost Tracking -->
    <aside class="booking-card">
        <h2>Utility Cost Tracking</h2>
        <div style="margin-bottom: 1rem;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Select Month</label>
            <select id="costMonth" onchange="updateCostTracking()" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                <option value="2024-12">December 2024</option>
                <option value="2024-11">November 2024</option>
                <option value="2024-10">October 2024</option>
            </select>
        </div>
        
        <div style="margin-top: 1rem;">
            <?php 
            $totalCost = array_sum(array_column($mockUtilityCosts, 'total_cost'));
            $totalHours = array_sum(array_column($mockUtilityCosts, 'usage_hours'));
            ?>
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px; margin-bottom: 1rem;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Total Utility Cost</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #0066cc;">
                    ₱<?= number_format($totalCost, 2); ?>
                </div>
                <div style="font-size: 0.85rem; color: #8b95b5; margin-top: 0.5rem;">
                    <?= $totalHours; ?> total usage hours
                </div>
            </div>
            
            <?php foreach ($mockUtilityCosts as $cost): ?>
                <div style="margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #0066cc;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <strong style="font-size: 0.95rem;"><?= htmlspecialchars($cost['facility_name']); ?></strong>
                        <span style="font-size: 1rem; font-weight: 600; color: #0066cc;">
                            ₱<?= number_format($cost['total_cost'], 2); ?>
                        </span>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; font-size: 0.85rem; margin-top: 0.75rem;">
                        <div>
                            <span style="color: #8b95b5;">Electricity:</span>
                            <strong>₱<?= number_format($cost['electricity_cost'], 2); ?></strong>
                        </div>
                        <div>
                            <span style="color: #8b95b5;">Water:</span>
                            <strong>₱<?= number_format($cost['water_cost'], 2); ?></strong>
                        </div>
                        <div>
                            <span style="color: #8b95b5;">Usage Hours:</span>
                            <strong><?= $cost['usage_hours']; ?> hrs</strong>
                        </div>
                        <div>
                            <span style="color: #8b95b5;">Cost/Hour:</span>
                            <strong>₱<?= number_format($cost['cost_per_hour'], 2); ?></strong>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <span style="color: #8b95b5;">Reservations:</span>
                            <strong><?= $cost['reservations_count']; ?> bookings</strong>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <button class="btn-outline" onclick="exportCostData()" style="width: 100%; padding: 0.5rem;">
                📊 Export Cost Data
            </button>
        </div>
    </aside>
</div>

<!-- Energy Usage Reporting Section -->
<section class="booking-card" style="margin-top: 1.5rem;">
    <h2>Energy Usage Reporting</h2>
    <p style="color: #8b95b5; margin-bottom: 1rem;">
        Track facility energy consumption per reservation for billing reconciliation.
    </p>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Total Energy Consumption</div>
            <div style="font-size: 1.25rem; font-weight: 600; color: #0066cc;">
                45,230 kWh
            </div>
            <small style="color: #8b95b5;">Last 30 days</small>
        </div>
        <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Average per Reservation</div>
            <div style="font-size: 1.25rem; font-weight: 600; color: #28a745;">
                36.5 kWh
            </div>
            <small style="color: #8b95b5;">Per booking</small>
        </div>
        <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Peak Usage Time</div>
            <div style="font-size: 1rem; font-weight: 600; color: #dc3545;">
                18:00 - 22:00
            </div>
            <small style="color: #8b95b5;">Evening hours</small>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Facility</th>
                    <th>Reservation Date</th>
                    <th>Time Slot</th>
                    <th>Energy (kWh)</th>
                    <th>Cost</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="6" style="text-align: center; color: #8b95b5; padding: 2rem;">
                        Energy usage data will be displayed here when available.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- Outage Details Modal -->
<div id="outageModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 id="modalTitle">Outage Details</h3>
            <button onclick="closeOutageModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <div id="modalContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<script>
function syncUtilityData() {
    // Placeholder for API call
    alert('Sync functionality will be implemented when API integration is available.');
}

function filterOutages() {
    const typeFilter = document.getElementById('filterOutageType').value;
    const rows = document.querySelectorAll('#outagesTableBody tr');
    
    rows.forEach(row => {
        const rowType = row.dataset.type;
        const typeMatch = typeFilter === 'all' || rowType === typeFilter;
        row.style.display = typeMatch ? '' : 'none';
    });
}

function updateCostTracking() {
    // Placeholder for cost tracking update
    const month = document.getElementById('costMonth').value;
    console.log('Updating cost tracking for month:', month);
    // Will fetch cost data for selected month when API is integrated
}

function exportCostData() {
    // Placeholder for data export
    alert('Data export functionality will be implemented when API integration is available.');
}

function viewOutageDetails(outageId) {
    // Mock data - will be replaced with API call
    const modal = document.getElementById('outageModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = `Outage: ${outageId}`;
    
    // Mock content
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Utility Type:</strong> Water<br>
            <strong>Facility:</strong> Community Convention Hall<br>
            <strong>Scheduled:</strong> January 22, 2025 08:00 - 14:00<br>
            <strong>Status:</strong> Scheduled<br>
            <strong>Reason:</strong> Water main repair in Zone 1<br>
            <strong>Affected Reservations:</strong> 1
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <small style="color: #8b95b5;">
                <strong>Note:</strong> This facility will be automatically blocked during the outage period. 
                Affected reservations will be notified and alternative facilities will be suggested.
            </small>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function closeOutageModal() {
    document.getElementById('outageModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('outageModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeOutageModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

