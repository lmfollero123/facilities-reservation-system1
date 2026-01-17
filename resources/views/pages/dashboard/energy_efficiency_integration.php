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
$pageTitle = 'Energy Efficiency Integration | LGU Facilities Reservation';

// Mock data for efficiency recommendations (will be replaced with API integration)
$mockEfficiencyRecommendations = [
    [
        'id' => 'EFF-2025-001',
        'recommendation_type' => 'Scheduling Optimization',
        'title' => 'Optimize Evening Bookings',
        'description' => 'Consolidate evening bookings to reduce HVAC cycling and improve energy efficiency.',
        'facility_id' => 1,
        'facility_name' => 'Community Convention Hall',
        'priority' => 'high',
        'estimated_savings' => 15,
        'estimated_cost_reduction' => 1875,
        'implementation_difficulty' => 'Low',
        'status' => 'pending',
        'created_at' => '2025-01-15 10:00:00',
    ],
    [
        'id' => 'EFF-2025-002',
        'recommendation_type' => 'Equipment Upgrade',
        'title' => 'LED Lighting Retrofit',
        'description' => 'Replace existing lighting with LED fixtures to reduce energy consumption by 40%.',
        'facility_id' => 2,
        'facility_name' => 'Municipal Sports Complex',
        'priority' => 'medium',
        'estimated_savings' => 40,
        'estimated_cost_reduction' => 3920,
        'implementation_difficulty' => 'Medium',
        'status' => 'under_review',
        'created_at' => '2025-01-12 14:30:00',
    ],
    [
        'id' => 'EFF-2025-003',
        'recommendation_type' => 'Peak Usage Management',
        'title' => 'Shift Peak Hours',
        'description' => 'Encourage bookings during off-peak hours to reduce demand charges.',
        'facility_id' => 3,
        'facility_name' => 'People\'s Park Amphitheater',
        'priority' => 'low',
        'estimated_savings' => 10,
        'estimated_cost_reduction' => 800,
        'implementation_difficulty' => 'Low',
        'status' => 'pending',
        'created_at' => '2025-01-18 09:15:00',
    ],
];

// Mock peak usage data
$mockPeakUsage = [
    [
        'facility_name' => 'Community Convention Hall',
        'peak_hours' => '18:00-22:00',
        'peak_consumption' => 125,
        'average_consumption' => 85,
        'peak_days' => ['Friday', 'Saturday', 'Sunday'],
        'utilization_during_peak' => 85,
    ],
    [
        'facility_name' => 'Municipal Sports Complex',
        'peak_hours' => '06:00-10:00, 16:00-20:00',
        'peak_consumption' => 95,
        'average_consumption' => 60,
        'peak_days' => ['Monday', 'Wednesday', 'Friday'],
        'utilization_during_peak' => 75,
    ],
    [
        'facility_name' => 'People\'s Park Amphitheater',
        'peak_hours' => '17:00-21:00',
        'peak_consumption' => 80,
        'average_consumption' => 50,
        'peak_days' => ['Saturday', 'Sunday'],
        'utilization_during_peak' => 70,
    ],
];

// Mock usage analytics
$mockUsageAnalytics = [
    'total_energy_consumption' => 45230,
    'total_cost' => 271380,
    'average_per_reservation' => 36.5,
    'peak_month' => 'December 2024',
    'efficiency_score' => 72,
    'savings_potential' => 15,
];

// Get real facilities for reference
$facilities = [];
try {
    $facilitiesStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Ignore error
}

// Integration status (mock - will be replaced with actual API health check)
$integrationStatus = [
    'connected' => true,
    'last_sync' => '2025-01-20 15:10:50',
    'sync_status' => 'success',
    'pending_recommendations' => 3,
    'data_shared' => true,
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Energy Efficiency</span>
    </div>
    <h1>Energy Efficiency Management Integration</h1>
    <small>Share facility usage data and receive energy efficiency recommendations for optimization.</small>
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
                <span style="background: #d1ecf1; color: #0c5460; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                    <?= $integrationStatus['pending_recommendations']; ?> recommendation(s)
                </span>
                <?php if ($integrationStatus['data_shared']): ?>
                    <span style="background: #d4edda; color: #155724; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        ✓ Data Sharing Active
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button class="btn-outline" onclick="exportUsageData()" style="padding: 0.5rem 1rem;">
                📊 Export Data
            </button>
            <button class="btn-outline" onclick="syncEfficiencyData()" style="padding: 0.5rem 1rem;">
                🔄 Sync Now
            </button>
        </div>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>Note:</strong> This integration shares facility usage statistics and booking patterns with the Energy Efficiency Management system 
            to generate optimization recommendations and identify peak usage times.
        </small>
    </div>
</div>

<div class="booking-wrapper">
    <!-- Usage Analytics -->
    <section class="booking-card">
        <h2>Usage Analytics (Shared Data)</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Total Energy Consumption</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #0066cc;">
                    <?= number_format($mockUsageAnalytics['total_energy_consumption']); ?> kWh
                </div>
                <small style="color: #8b95b5;">Last 30 days</small>
            </div>
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Total Cost</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #dc3545;">
                    ₱<?= number_format($mockUsageAnalytics['total_cost'], 2); ?>
                </div>
                <small style="color: #8b95b5;">Last 30 days</small>
            </div>
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Efficiency Score</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #28a745;">
                    <?= $mockUsageAnalytics['efficiency_score']; ?>/100
                </div>
                <small style="color: #8b95b5;">Overall rating</small>
            </div>
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Savings Potential</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #28a745;">
                    <?= $mockUsageAnalytics['savings_potential']; ?>%
                </div>
                <small style="color: #8b95b5;">With recommendations</small>
            </div>
        </div>

        <h3 style="font-size: 1rem; margin-bottom: 1rem;">Average Consumption per Reservation</h3>
        <div style="padding: 1rem; background: #e7f3ff; border-radius: 6px; text-align: center;">
            <div style="font-size: 2rem; font-weight: 600; color: #0066cc;">
                <?= $mockUsageAnalytics['average_per_reservation']; ?> kWh
            </div>
            <small style="color: #8b95b5;">Per booking</small>
        </div>
    </section>

    <!-- Peak Usage Tracking -->
    <aside class="booking-card">
        <h2>Peak Usage Tracking</h2>
        <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
            Identify peak usage times for energy optimization.
        </p>
        
        <?php foreach ($mockPeakUsage as $usage): ?>
            <div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #ffc107;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                    <div>
                        <strong style="font-size: 0.95rem;"><?= htmlspecialchars($usage['facility_name']); ?></strong>
                        <br><small style="color: #8b95b5;">Peak Hours: <?= htmlspecialchars($usage['peak_hours']); ?></small>
                    </div>
                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                        <?= $usage['utilization_during_peak']; ?>% utilized
                    </span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; margin-top: 0.75rem; font-size: 0.85rem;">
                    <div>
                        <span style="color: #8b95b5;">Peak Consumption:</span>
                        <strong style="color: #dc3545;"><?= $usage['peak_consumption']; ?> kWh</strong>
                    </div>
                    <div>
                        <span style="color: #8b95b5;">Average:</span>
                        <strong><?= $usage['average_consumption']; ?> kWh</strong>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <span style="color: #8b95b5;">Peak Days:</span>
                        <strong><?= implode(', ', $usage['peak_days']); ?></strong>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </aside>
</div>

<!-- Efficiency Recommendations -->
<section class="booking-card" style="margin-top: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2>Energy Efficiency Recommendations</h2>
        <select id="filterRecommendations" onchange="filterRecommendations()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
            <option value="all">All Status</option>
            <option value="pending">Pending</option>
            <option value="under_review">Under Review</option>
            <option value="approved">Approved</option>
            <option value="implemented">Implemented</option>
        </select>
    </div>

    <?php if (empty($mockEfficiencyRecommendations)): ?>
        <p style="color: #8b95b5; text-align: center; padding: 2rem;">No efficiency recommendations available.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Recommendation ID</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Facility</th>
                        <th>Savings</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="recommendationsTableBody">
                    <?php foreach ($mockEfficiencyRecommendations as $rec): 
                        $statusClass = $rec['status'] === 'approved' ? 'active' : 
                                     ($rec['status'] === 'implemented' ? 'active' : 
                                     ($rec['status'] === 'rejected' ? 'offline' : 'maintenance'));
                        $statusDisplay = ucfirst(str_replace('_', ' ', $rec['status']));
                        $priorityClass = $rec['priority'] === 'high' ? 'offline' : ($rec['priority'] === 'medium' ? 'maintenance' : 'active');
                        $difficultyClass = $rec['implementation_difficulty'] === 'Low' ? 'active' : 
                                         ($rec['implementation_difficulty'] === 'Medium' ? 'maintenance' : 'offline');
                    ?>
                        <tr data-status="<?= $rec['status']; ?>">
                            <td><strong><?= htmlspecialchars($rec['id']); ?></strong></td>
                            <td>
                                <span style="background: #e7f3ff; color: #0066cc; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                    <?= htmlspecialchars($rec['recommendation_type']); ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($rec['title']); ?></td>
                            <td><?= htmlspecialchars($rec['facility_name']); ?></td>
                            <td>
                                <span style="color: #28a745; font-weight: 600;">
                                    <?= $rec['estimated_savings']; ?>% (₱<?= number_format($rec['estimated_cost_reduction']); ?>)
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= $priorityClass; ?>" style="text-transform: capitalize;">
                                    <?= htmlspecialchars($rec['priority']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass; ?>">
                                    <?= $statusDisplay; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-outline" onclick="viewRecommendationDetails('<?= htmlspecialchars($rec['id']); ?>')" style="padding: 0.35rem 0.6rem; font-size: 0.85rem;">
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

<!-- Recommendation Details Modal -->
<div id="recommendationModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 id="modalTitle">Recommendation Details</h3>
            <button onclick="closeRecommendationModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <div id="modalContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<script>
function syncEfficiencyData() {
    // Placeholder for API call
    alert('Sync functionality will be implemented when API integration is available.');
}

function exportUsageData() {
    // Placeholder for data export
    alert('Data export functionality will be implemented when API integration is available.');
}

function filterRecommendations() {
    const statusFilter = document.getElementById('filterRecommendations').value;
    const rows = document.querySelectorAll('#recommendationsTableBody tr');
    
    rows.forEach(row => {
        const rowStatus = row.dataset.status;
        const statusMatch = statusFilter === 'all' || rowStatus === statusFilter;
        row.style.display = statusMatch ? '' : 'none';
    });
}

function viewRecommendationDetails(recommendationId) {
    // Mock data - will be replaced with API call
    const modal = document.getElementById('recommendationModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = `Recommendation: ${recommendationId}`;
    
    // Mock content
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Type:</strong> Scheduling Optimization<br>
            <strong>Title:</strong> Optimize Evening Bookings<br>
            <strong>Facility:</strong> Community Convention Hall<br>
            <strong>Priority:</strong> High<br>
            <strong>Status:</strong> Pending<br>
            <strong>Estimated Savings:</strong> 15% (₱1,875/month)<br>
            <strong>Implementation Difficulty:</strong> Low
        </div>
        <div style="margin-bottom: 1rem;">
            <strong>Description:</strong><br>
            <p style="color: #8b95b5; margin-top: 0.5rem;">Consolidate evening bookings to reduce HVAC cycling and improve energy efficiency.</p>
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <small style="color: #8b95b5;">
                <strong>Note:</strong> This recommendation is based on usage analytics and energy consumption patterns. 
                Implementation can be scheduled during low-activity periods.
            </small>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function closeRecommendationModal() {
    document.getElementById('recommendationModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('recommendationModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRecommendationModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

