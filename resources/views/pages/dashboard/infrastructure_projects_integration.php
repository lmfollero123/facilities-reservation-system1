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
$pageTitle = 'Infrastructure Projects Integration | LGU Facilities Reservation';

// Mock data for infrastructure projects (will be replaced with API integration)
$mockProjects = [
    [
        'id' => 'PROJ-2025-001',
        'project_name' => 'Convention Hall Renovation',
        'project_type' => 'Renovation',
        'facility_id' => 1,
        'facility_name' => 'Community Convention Hall',
        'start_date' => '2025-02-01',
        'end_date' => '2025-03-15',
        'status' => 'planned',
        'phase' => 'Planning',
        'progress' => 15,
        'budget' => 2500000,
        'description' => 'Complete renovation of the convention hall including new flooring, lighting, and AV equipment installation.',
        'affected_reservations' => 8,
        'capacity_change' => null,
        'created_at' => '2024-12-10 09:00:00',
    ],
    [
        'id' => 'PROJ-2025-002',
        'project_name' => 'Sports Complex Expansion',
        'project_type' => 'Expansion',
        'facility_id' => 2,
        'facility_name' => 'Municipal Sports Complex',
        'start_date' => '2025-01-25',
        'end_date' => '2025-04-30',
        'status' => 'in_progress',
        'phase' => 'Construction',
        'progress' => 45,
        'budget' => 5000000,
        'description' => 'Expansion project to add two additional courts and improve parking facilities.',
        'affected_reservations' => 12,
        'capacity_change' => '+200',
        'created_at' => '2024-11-15 14:30:00',
    ],
    [
        'id' => 'PROJ-2025-003',
        'project_name' => 'New Community Center',
        'project_type' => 'New Construction',
        'facility_id' => null,
        'facility_name' => 'New Facility (Upon Completion)',
        'start_date' => '2025-03-01',
        'end_date' => '2025-08-31',
        'status' => 'planned',
        'phase' => 'Design',
        'progress' => 5,
        'budget' => 8000000,
        'description' => 'Construction of a new multi-purpose community center with modern amenities.',
        'affected_reservations' => 0,
        'capacity_change' => 'New Facility',
        'created_at' => '2024-12-20 10:15:00',
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
    'last_sync' => '2025-01-20 11:30:15',
    'sync_status' => 'success',
    'active_projects' => 3,
    'pending_notifications' => 1,
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Infrastructure Projects</span>
    </div>
    <h1>Infrastructure Projects Integration</h1>
    <small>View and manage infrastructure projects that affect facility availability and capacity.</small>
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
                    <?= $integrationStatus['active_projects']; ?> active project(s)
                </span>
                <?php if ($integrationStatus['pending_notifications'] > 0): ?>
                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                        <?= $integrationStatus['pending_notifications']; ?> notification(s)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn-outline" onclick="syncProjectData()" style="padding: 0.5rem 1rem;">
            🔄 Sync Now
        </button>
    </div>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
        <small style="color: #8b95b5;">
            <strong>Note:</strong> This integration connects to the Infrastructure Project Management system. 
            Projects automatically block facilities during construction and add new facilities upon completion.
        </small>
    </div>
</div>

<div class="booking-wrapper">
    <!-- Active Projects -->
    <section class="booking-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>Active Projects</h2>
            <div style="display: flex; gap: 0.5rem;">
                <select id="filterStatus" onchange="filterProjects()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                    <option value="all">All Status</option>
                    <option value="planned">Planned</option>
                    <option value="in_progress">In Progress</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                </select>
                <select id="filterType" onchange="filterProjects()" style="padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                    <option value="all">All Types</option>
                    <option value="Renovation">Renovation</option>
                    <option value="Expansion">Expansion</option>
                    <option value="New Construction">New Construction</option>
                </select>
            </div>
        </div>

        <?php if (empty($mockProjects)): ?>
            <p style="color: #8b95b5; text-align: center; padding: 2rem;">No active projects.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project ID</th>
                            <th>Project Name</th>
                            <th>Type</th>
                            <th>Facility</th>
                            <th>Timeline</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Affected</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="projectsTableBody">
                        <?php foreach ($mockProjects as $project): 
                            $statusClass = $project['status'] === 'in_progress' ? 'maintenance' : 
                                         ($project['status'] === 'completed' ? 'active' : 'offline');
                            $statusDisplay = ucfirst(str_replace('_', ' ', $project['status']));
                            $daysRemaining = (strtotime($project['end_date']) - time()) / 86400;
                        ?>
                            <tr data-status="<?= $project['status']; ?>" data-type="<?= $project['project_type']; ?>">
                                <td><strong><?= htmlspecialchars($project['id']); ?></strong></td>
                                <td><?= htmlspecialchars($project['project_name']); ?></td>
                                <td>
                                    <span style="background: #e7f3ff; color: #0066cc; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                        <?= htmlspecialchars($project['project_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($project['facility_id']): ?>
                                        <?= htmlspecialchars($project['facility_name']); ?>
                                    <?php else: ?>
                                        <em style="color: #8b95b5;">New Facility</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= date('M d', strtotime($project['start_date'])); ?> - 
                                    <?= date('M d, Y', strtotime($project['end_date'])); ?><br>
                                    <small style="color: <?= $daysRemaining < 30 ? '#dc3545' : '#8b95b5'; ?>;">
                                        <?= $daysRemaining > 0 ? round($daysRemaining) . ' days remaining' : 'Overdue'; ?>
                                    </small>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div style="flex: 1; background: #e0e6ed; border-radius: 4px; height: 8px; overflow: hidden;">
                                            <div style="background: #0066cc; height: 100%; width: <?= $project['progress']; ?>%; transition: width 0.3s;"></div>
                                        </div>
                                        <span style="font-size: 0.85rem; color: #8b95b5; min-width: 40px;">
                                            <?= $project['progress']; ?>%
                                        </span>
                                    </div>
                                    <small style="color: #8b95b5; font-size: 0.8rem;"><?= htmlspecialchars($project['phase']); ?></small>
                                </td>
                                <td>
                                    <span class="status-badge <?= $statusClass; ?>">
                                        <?= $statusDisplay; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($project['affected_reservations'] > 0): ?>
                                        <span style="color: #dc3545; font-weight: 600;">
                                            <?= $project['affected_reservations']; ?> reservation(s)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #8b95b5;">None</span>
                                    <?php endif; ?>
                                    <?php if ($project['capacity_change']): ?>
                                        <br><small style="color: #28a745;">Capacity: <?= htmlspecialchars($project['capacity_change']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-outline" onclick="viewProjectDetails('<?= htmlspecialchars($project['id']); ?>')" style="padding: 0.35rem 0.6rem; font-size: 0.85rem;">
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

    <!-- Project Timeline -->
    <aside class="booking-card">
        <h2>Project Timeline</h2>
        <div style="margin-bottom: 1rem;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Filter by Facility</label>
            <select id="timelineFacility" onchange="updateProjectTimeline()" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                <option value="all">All Facilities</option>
                <?php foreach ($facilities as $facility): ?>
                    <option value="<?= $facility['id']; ?>"><?= htmlspecialchars($facility['name']); ?></option>
                <?php endforeach; ?>
                <option value="new">New Facilities</option>
            </select>
        </div>
        
        <div id="projectTimeline" style="margin-top: 1rem;">
            <?php foreach ($mockProjects as $project): 
                $startDate = strtotime($project['start_date']);
                $endDate = strtotime($project['end_date']);
                $totalDays = ($endDate - $startDate) / 86400;
                $daysElapsed = (time() - $startDate) / 86400;
                $progressPercent = min(100, max(0, ($daysElapsed / $totalDays) * 100));
            ?>
                <div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #0066cc;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <div>
                            <strong style="font-size: 0.95rem;"><?= htmlspecialchars($project['project_name']); ?></strong>
                            <br><small style="color: #8b95b5;"><?= htmlspecialchars($project['project_type']); ?></small>
                        </div>
                        <span class="status-badge <?= $project['status'] === 'in_progress' ? 'maintenance' : 'offline'; ?>" style="font-size: 0.8rem;">
                            <?= ucfirst(str_replace('_', ' ', $project['status'])); ?>
                        </span>
                    </div>
                    <div style="margin-top: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #8b95b5; margin-bottom: 0.5rem;">
                            <span><?= date('M d, Y', $startDate); ?></span>
                            <span><?= date('M d, Y', $endDate); ?></span>
                        </div>
                        <div style="background: #e0e6ed; border-radius: 4px; height: 6px; overflow: hidden;">
                            <div style="background: #0066cc; height: 100%; width: <?= $progressPercent; ?>%; transition: width 0.3s;"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </aside>
</div>

<!-- New Facility Integration Section -->
<section class="booking-card" style="margin-top: 1.5rem;">
    <h2>New Facility Integration</h2>
    <p style="color: #8b95b5; margin-bottom: 1rem;">
        Facilities from completed infrastructure projects will automatically appear here for review and activation.
    </p>
    
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Project ID</th>
                    <th>Facility Name</th>
                    <th>Project Type</th>
                    <th>Completion Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="6" style="text-align: center; color: #8b95b5; padding: 2rem;">
                        No new facilities pending integration.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- Project Details Modal -->
<div id="projectModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 id="modalTitle">Project Details</h3>
            <button onclick="closeProjectModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <div id="modalContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<script>
function syncProjectData() {
    // Placeholder for API call
    alert('Sync functionality will be implemented when API integration is available.');
}

function filterProjects() {
    const statusFilter = document.getElementById('filterStatus').value;
    const typeFilter = document.getElementById('filterType').value;
    const rows = document.querySelectorAll('#projectsTableBody tr');
    
    rows.forEach(row => {
        const rowStatus = row.dataset.status;
        const rowType = row.dataset.type;
        
        const statusMatch = statusFilter === 'all' || rowStatus === statusFilter;
        const typeMatch = typeFilter === 'all' || rowType === typeFilter;
        
        row.style.display = (statusMatch && typeMatch) ? '' : 'none';
    });
}

function updateProjectTimeline() {
    // Placeholder for timeline update
    const facilityId = document.getElementById('timelineFacility').value;
    console.log('Updating timeline for facility:', facilityId);
    // Will filter projects by facility when API is integrated
}

function viewProjectDetails(projectId) {
    // Mock data - will be replaced with API call
    const modal = document.getElementById('projectModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = `Project: ${projectId}`;
    
    // Mock content
    modalContent.innerHTML = `
        <div style="margin-bottom: 1rem;">
            <strong>Project Name:</strong> Convention Hall Renovation<br>
            <strong>Type:</strong> Renovation<br>
            <strong>Facility:</strong> Community Convention Hall<br>
            <strong>Timeline:</strong> February 1 - March 15, 2025<br>
            <strong>Status:</strong> Planned<br>
            <strong>Phase:</strong> Planning<br>
            <strong>Progress:</strong> 15%<br>
            <strong>Budget:</strong> ₱2,500,000<br>
            <strong>Affected Reservations:</strong> 8
        </div>
        <div style="margin-bottom: 1rem;">
            <strong>Description:</strong><br>
            <p style="color: #8b95b5; margin-top: 0.5rem;">Complete renovation of the convention hall including new flooring, lighting, and AV equipment installation.</p>
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
            <small style="color: #8b95b5;">
                <strong>Note:</strong> This facility will be automatically blocked during the project timeline. 
                Affected reservations will be notified and alternative facilities will be suggested.
            </small>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function closeProjectModal() {
    document.getElementById('projectModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('projectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeProjectModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

