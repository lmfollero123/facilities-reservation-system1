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
$pageTitle = 'Urban Planning Integration | LGU Facilities Reservation';

// Mock data for planning recommendations (will be replaced with API integration)
$mockPlanningRecommendations = [
    [
        'id' => 'PLAN-2025-001',
        'recommendation_type' => 'New Development',
        'title' => 'New Community Center in Barangay Zone 3',
        'description' => 'Proposed construction of a new multi-purpose community center to serve the growing population in Zone 3.',
        'status' => 'under_review',
        'priority' => 'high',
        'proposed_location' => 'Zone 3, Barangay Culiat',
        'estimated_capacity' => 300,
        'estimated_cost' => 5000000,
        'proposed_timeline' => '2025-06-01 to 2025-12-31',
        'created_at' => '2025-01-15 10:00:00',
    ],
    [
        'id' => 'PLAN-2025-002',
        'recommendation_type' => 'Zoning Update',
        'title' => 'Zoning Regulation Update for Event Facilities',
        'description' => 'Update zoning regulations to allow extended operating hours for community event facilities.',
        'status' => 'approved',
        'priority' => 'medium',
        'proposed_location' => 'All Event Facilities',
        'estimated_capacity' => null,
        'estimated_cost' => null,
        'proposed_timeline' => '2025-02-01 to 2025-02-28',
        'created_at' => '2025-01-10 14:30:00',
    ],
    [
        'id' => 'PLAN-2025-003',
        'recommendation_type' => 'Capacity Expansion',
        'title' => 'Expand Convention Hall Capacity',
        'description' => 'Recommendation to expand the Convention Hall capacity to accommodate larger community events.',
        'status' => 'pending',
        'priority' => 'low',
        'proposed_location' => 'Community Convention Hall',
        'estimated_capacity' => '+150',
        'estimated_cost' => 1500000,
        'proposed_timeline' => '2025-04-01 to 2025-06-30',
        'created_at' => '2025-01-18 09:15:00',
    ],
];

// Mock usage analytics data
$mockUsageAnalytics = [
    'total_reservations' => 1247,
    'peak_month' => 'December 2024',
    'peak_facility' => 'Community Convention Hall',
    'average_utilization' => 68,
    'growth_trend' => '+15%',
    'demand_forecast' => [
        'next_month' => 145,
        'next_quarter' => 420,
        'next_year' => 1680,
    ],
];

// Mock location analytics
$mockLocationAnalytics = [
    [
        'facility_name' => 'Community Convention Hall',
        'location' => 'Zone 1, Barangay Culiat',
        'reservations_count' => 456,
        'utilization_rate' => 78,
        'peak_hours' => '18:00-22:00',
        'popular_purpose' => 'Community Events',
    ],
    [
        'facility_name' => 'Municipal Sports Complex',
        'location' => 'Zone 2, Barangay Culiat',
        'reservations_count' => 389,
        'utilization_rate' => 65,
        'peak_hours' => '06:00-10:00, 16:00-20:00',
        'popular_purpose' => 'Sports Activities',
    ],
    [
        'facility_name' => 'People\'s Park Amphitheater',
        'location' => 'Zone 1, Barangay Culiat',
        'reservations_count' => 402,
        'utilization_rate' => 61,
        'peak_hours' => '17:00-21:00',
        'popular_purpose' => 'Cultural Events',
    ],
];

// Integration status (mock - will be replaced with actual API health check)
$integrationStatus = [
    'connected' => true,
    'last_sync' => '2025-01-20 12:15:42',
    'sync_status' => 'success',
    'pending_recommendations' => 3,
    'data_shared' => true,
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Urban Planning</span>
    </div>
    <h1>Urban Planning & Development Integration</h1>
    <small>Share facility usage data and receive planning recommendations for infrastructure development.</small>
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
            <button class="btn-outline" onclick="syncPlanningData()" style="padding: 0.5rem 1rem;">
                🔄 Sync Now
            </button>
        </div>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>Note:</strong> This integration shares reservation trends and facility usage statistics with the Urban Planning & Development system 
            to support data-driven planning decisions.
        </small>
    </div>
</div>

<div class="booking-wrapper">
    <!-- Usage Analytics -->
    <section class="booking-card">
        <h2>Usage Analytics (Shared Data)</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Total Reservations</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #0066cc;">
                    <?= number_format($mockUsageAnalytics['total_reservations']); ?>
                </div>
            </div>
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Average Utilization</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #28a745;">
                    <?= $mockUsageAnalytics['average_utilization']; ?>%
                </div>
            </div>
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Growth Trend</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #28a745;">
                    <?= $mockUsageAnalytics['growth_trend']; ?>
                </div>
            </div>
            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Peak Month</div>
                <div style="font-size: 1rem; font-weight: 600; color: #0066cc;">
                    <?= htmlspecialchars($mockUsageAnalytics['peak_month']); ?>
                </div>
            </div>
        </div>

        <h3 style="font-size: 1rem; margin-bottom: 1rem;">Demand Forecast</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
            <div style="padding: 1rem; background: #e7f3ff; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Next Month</div>
                <div style="font-size: 1.25rem; font-weight: 600; color: #0066cc;">
                    ~<?= $mockUsageAnalytics['demand_forecast']['next_month']; ?>
                </div>
            </div>
            <div style="padding: 1rem; background: #e7f3ff; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Next Quarter</div>
                <div style="font-size: 1.25rem; font-weight: 600; color: #0066cc;">
                    ~<?= $mockUsageAnalytics['demand_forecast']['next_quarter']; ?>
                </div>
            </div>
            <div style="padding: 1rem; background: #e7f3ff; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">Next Year</div>
                <div style="font-size: 1.25rem; font-weight: 600; color: #0066cc;">
                    ~<?= $mockUsageAnalytics['demand_forecast']['next_year']; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Location Analytics -->
    <aside class="booking-card">
        <h2>Location Analytics</h2>
        <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
            Facility usage data by location for planning decisions.
        </p>
        
        <?php foreach ($mockLocationAnalytics as $location): ?>
            <div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #0066cc;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                    <div>
                        <strong style="font-size: 0.95rem;"><?= htmlspecialchars($location['facility_name']); ?></strong>
                        <br><small style="color: #8b95b5;"><?= htmlspecialchars($location['location']); ?></small>
                    </div>
                    <span style="background: #d4edda; color: #155724; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                        <?= $location['utilization_rate']; ?>% utilized
                    </span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; margin-top: 0.75rem; font-size: 0.85rem;">
                    <div>
                        <span style="color: #8b95b5;">Reservations:</span>
                        <strong style="color: #0066cc;"><?= $location['reservations_count']; ?></strong>
                    </div>
                    <div>
                        <span style="color: #8b95b5;">Peak Hours:</span>
                        <strong><?= htmlspecialchars($location['peak_hours']); ?></strong>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <span style="color: #8b95b5;">Popular Purpose:</span>
                        <strong><?= htmlspecialchars($location['popular_purpose']); ?></strong>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </aside>
</div>

<!-- Planning Recommendations -->
<section class="booking-card" style="margin-top: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2>Planning Recommendations</h2>
        <select id="filterRecommendations" onchange="filterRecommendations()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
            <option value="all">All Status</option>
            <option value="pending">Pending</option>
            <option value="under_review">Under Review</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>

    <?php if (empty($mockPlanningRecommendations)): ?>
        <p style="color: #8b95b5; text-align: center; padding: 2rem;">No planning recommendations available.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Recommendation ID</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Location</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="recommendationsTableBody">
                    <?php foreach ($mockPlanningRecommendations as $rec): 
                        $statusClass = $rec['status'] === 'approved' ? 'active' : 
                                     ($rec['status'] === 'rejected' ? 'offline' : 'maintenance');
                        $statusDisplay = ucfirst(str_replace('_', ' ', $rec['status']));
                        $priorityClass = $rec['priority'] === 'high' ? 'offline' : ($rec['priority'] === 'medium' ? 'maintenance' : 'active');
                    ?>
                        <tr data-status="<?= $rec['status']; ?>">
                            <td><strong><?= htmlspecialchars($rec['id']); ?></strong></td>
                            <td>
                                <span style="background: #e7f3ff; color: #0066cc; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                    <?= htmlspecialchars($rec['recommendation_type']); ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($rec['title']); ?></td>
                            <td><?= htmlspecialchars($rec['proposed_location']); ?></td>
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
function syncPlanningData() {
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
            <strong>Type:</strong> New Development<br>
            <strong>Title:</strong> New Community Center in Barangay Zone 3<br>
            <strong>Status:</strong> Under Review<br>
            <strong>Priority:</strong> High<br>
            <strong>Location:</strong> Zone 3, Barangay Culiat<br>
            <strong>Estimated Capacity:</strong> 300<br>
            <strong>Estimated Cost:</strong> ₱5,000,000<br>
            <strong>Proposed Timeline:</strong> June 1 - December 31, 2025
        </div>
        <div style="margin-bottom: 1rem;">
            <strong>Description:</strong><br>
            <p style="color: #8b95b5; margin-top: 0.5rem;">Proposed construction of a new multi-purpose community center to serve the growing population in Zone 3.</p>
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <small style="color: #8b95b5;">
                <strong>Note:</strong> This recommendation is based on usage analytics and demand forecasting. 
                Upon approval, the new facility will be automatically integrated into the reservation system.
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

