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
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/maintenance_helper.php';
$pdo = db();
$pageTitle = 'Facility Management | LGU Facilities Reservation';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facilityId = (int)($_POST['facility_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rate = trim($_POST['base_rate'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $capacity = trim($_POST['capacity'] ?? '');
    $amenities = trim($_POST['amenities'] ?? '');
    $rules = trim($_POST['rules'] ?? '');
    $imageCitation = trim($_POST['image_citation'] ?? '');
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $status = $_POST['status'] ?? 'available';
    $autoApprove = isset($_POST['auto_approve']) && $_POST['auto_approve'] === '1';
    $capacityThreshold = !empty($_POST['capacity_threshold']) ? (int)$_POST['capacity_threshold'] : null;
    $maxDurationHours = !empty($_POST['max_duration_hours']) ? (float)$_POST['max_duration_hours'] : null;
    $operatingHours = trim($_POST['operating_hours'] ?? '');

    // Handle image upload (optional) with enhanced security
    $imagePath = null;
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        require_once __DIR__ . '/../../../../config/security.php';
        require_once __DIR__ . '/../../../../config/upload_helper.php';
        $uploadErrors = validateFileUpload($_FILES['image'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 5 * 1024 * 1024);
        
        if (!empty($uploadErrors)) {
            $message = implode(' ', $uploadErrors);
            $messageType = 'error';
        } else {
            $uploadDir = __DIR__ . '/../../../../public/img/facilities';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($name));
            $fileName = $safeName . '-' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $fileName;
            [$ok, $err] = saveOptimizedImage($_FILES['image']['tmp_name'], $targetPath, 1600, 82);
            if (!$ok) {
                // Fallback to original move for GIFs/unsupported types
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $message = $err ?: 'Failed to upload image. Please try again.';
                    $messageType = 'error';
                } else {
                    @chmod($targetPath, 0644);
                    $imagePath = '/public/img/facilities/' . $fileName;
                }
            } else {
                $imagePath = '/public/img/facilities/' . $fileName;
            }
        }
    }

    if (!$name) {
        $message = 'Facility name is required.';
        $messageType = 'error';
    } else {
        try {
            if ($facilityId) {
                // Get old facility data for audit log
                $oldStmt = $pdo->prepare('SELECT name, status, image_path, image_citation FROM facilities WHERE id = ?');
                $oldStmt->execute([$facilityId]);
                $oldFacility = $oldStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($imagePath === null) {
                    $imagePath = $oldFacility['image_path'] ?? null;
                }
                
                // Preserve existing citation if not provided
                if ($imageCitation === '' && isset($oldFacility['image_citation'])) {
                    $imageCitation = $oldFacility['image_citation'];
                }
                
                // Geocode location if coordinates not provided but location is
                if (($latitude === null || $longitude === null) && !empty($location)) {
                    require_once __DIR__ . '/../../../../config/geocoding.php';
                    $coords = geocodeAddress($location);
                    if ($coords) {
                        $latitude = $coords['lat'];
                        $longitude = $coords['lng'];
                    }
                }

                $stmt = $pdo->prepare('UPDATE facilities SET name = ?, description = ?, base_rate = ?, image_path = ?, image_citation = ?, location = ?, latitude = ?, longitude = ?, capacity = ?, amenities = ?, rules = ?, status = ?, auto_approve = ?, capacity_threshold = ?, max_duration_hours = ?, operating_hours = ? WHERE id = ?');
                $stmt->execute([$name, $description, $rate, $imagePath, $imageCitation ?: null, $location, $latitude, $longitude, $capacity, $amenities, $rules, $status, $autoApprove ? 1 : 0, $capacityThreshold, $maxDurationHours, $operatingHours ?: null, $facilityId]);
                
                // Log audit event
                $details = $name;
                if ($oldFacility && $oldFacility['status'] !== $status) {
                    $details .= ' – Status changed from ' . $oldFacility['status'] . ' to ' . $status;
                    
                    // Handle reservation status updates when facility status changes
                    if ($status === 'maintenance') {
                        // Facility went to maintenance - update reservations
                        $maintenanceResult = handleFacilityMaintenanceStatusChange($facilityId, $name);
                        $details .= '. Updated reservations: ' . ($maintenanceResult['pending_cancelled'] + $maintenanceResult['approved_postponed']) . ' affected';
                        
                        if ($maintenanceResult['pending_cancelled'] > 0 || $maintenanceResult['approved_postponed'] > 0) {
                            $message = 'Facility updated successfully. ';
                            if ($maintenanceResult['pending_cancelled'] > 0) {
                                $message .= "Cancelled {$maintenanceResult['pending_cancelled']} pending reservation(s). ";
                            }
                            if ($maintenanceResult['approved_postponed'] > 0) {
                                $message .= "Postponed {$maintenanceResult['approved_postponed']} approved reservation(s) with priority. ";
                                $message .= "Email notifications have been sent to affected users.";
                            }
                        } else {
                            $message = 'Facility updated successfully.';
                        }
                    } elseif ($oldFacility['status'] === 'maintenance' && $status === 'available') {
                        // Facility became available again - notify users with postponed reservations
                        $availableResult = handleFacilityAvailableStatusChange($facilityId, $name);
                        if ($availableResult['notified'] > 0) {
                            $message = 'Facility updated successfully. Notified ' . $availableResult['notified'] . ' user(s) with priority reservations that the facility is available again.';
                        } else {
                            $message = 'Facility updated successfully.';
                        }
                    } else {
                        $message = 'Facility updated successfully.';
                    }
                } else {
                    $message = 'Facility updated successfully.';
                }
                
                logAudit('Updated facility', 'Facility Management', $details);
            } else {
                // Geocode location if coordinates not provided but location is
                if (($latitude === null || $longitude === null) && !empty($location)) {
                    require_once __DIR__ . '/../../../../config/geocoding.php';
                    $coords = geocodeAddress($location);
                    if ($coords) {
                        $latitude = $coords['lat'];
                        $longitude = $coords['lng'];
                    }
                }
                
                $stmt = $pdo->prepare('INSERT INTO facilities (name, description, base_rate, image_path, image_citation, location, latitude, longitude, capacity, amenities, rules, status, auto_approve, capacity_threshold, max_duration_hours, operating_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $description, $rate, $imagePath, $imageCitation ?: null, $location, $latitude, $longitude, $capacity, $amenities, $rules, $status, $autoApprove ? 1 : 0, $capacityThreshold, $maxDurationHours, $operatingHours ?: null]);
                
                // Log audit event
                logAudit('Created facility', 'Facility Management', $name . ' (' . $status . ')');
                
                $message = 'Facility added successfully.';
            }
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Unable to save facility. Please try again.';
            $messageType = 'error';
        }
    }
}

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$totalFacilities = (int)$pdo->query('SELECT COUNT(*) FROM facilities')->fetchColumn();
$totalPages = max(1, (int)ceil($totalFacilities / $perPage));

$facilitiesStmt = $pdo->prepare('SELECT *, latitude, longitude, operating_hours FROM facilities ORDER BY updated_at DESC LIMIT :limit OFFSET :offset');
$facilitiesStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$facilitiesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$facilitiesStmt->execute();
$facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent audit log entries for Facility Management module with pagination
$auditPerPage = 5;
$auditPage = max(1, (int)($_GET['audit_page'] ?? 1));
$auditOffset = ($auditPage - 1) * $auditPerPage;

$auditCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM audit_log WHERE module = "Facility Management"'
);
$auditCountStmt->execute();
$auditTotal = (int)$auditCountStmt->fetchColumn();
$auditTotalPages = max(1, (int)ceil($auditTotal / $auditPerPage));

$auditStmt = $pdo->prepare(
    'SELECT a.id, a.action, a.module, a.details, a.created_at, u.name AS user_name
     FROM audit_log a
     LEFT JOIN users u ON a.user_id = u.id
     WHERE a.module = "Facility Management"
     ORDER BY a.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$auditStmt->bindValue(':limit', $auditPerPage, PDO::PARAM_INT);
$auditStmt->bindValue(':offset', $auditOffset, PDO::PARAM_INT);
$auditStmt->execute();
$auditTrail = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Administration</span><span class="sep">/</span><span>Facility Management</span>
    </div>
    <h1>Facility Management</h1>
    <small>Maintain facility records, capacities, and maintenance statuses.</small>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="facility-admin">
    <div style="margin-bottom: 1.5rem;">
        <button class="btn-primary" type="button" onclick="openFacilityModal()" style="display: inline-flex; align-items: center; gap: 0.75rem; padding: 1rem 1.75rem; font-size: 1rem; font-weight: 600;">
            <span style="font-size: 1.2rem;">➕</span>
            <span>Add Facility</span>
        </button>
    </div>

    <section class="collapsible-card">
        <button type="button" class="collapsible-header" data-collapse-target="facilities-list">
            <span>Facilities</span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="facilities-list">
            <?php if (empty($facilities)): ?>
                <article class="facility-card-admin">
                    <p>No facilities added yet. Click "Add Facility" to add your first facility.</p>
                </article>
            <?php else: ?>
                <?php foreach ($facilities as $facility): ?>
                    <article class="facility-card-admin">
                        <header>
                            <div>
                                <h3><?= htmlspecialchars($facility['name']); ?></h3>
                                <?php if ($facility['base_rate']): ?>
                                    <small><?= htmlspecialchars($facility['base_rate']); ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge <?= $facility['status']; ?>">
                                <?= ucfirst($facility['status']); ?>
                            </span>
                        </header>
                        <?php if ($facility['description']): ?>
                            <p style="margin:0.5rem 0 1rem;color:#4c5b7c;"><?= nl2br(htmlspecialchars($facility['description'])); ?></p>
                        <?php endif; ?>
                        <div class="availability-toggle" style="display:flex; align-items:flex-start; gap:0.5rem;">
                            <input type="checkbox" <?= $facility['status'] === 'available' ? 'checked' : ''; ?> disabled style="width:18px; height:18px; min-width:18px; flex-shrink:0; margin-top:0.125rem;">
                            <span style="line-height:1.5;"><?= $facility['status'] === 'available' ? 'Available for booking' : ($facility['status'] === 'maintenance' ? 'Under Maintenance' : 'Offline'); ?></span>
                        </div>
                        <?php $payload = htmlspecialchars(json_encode($facility), ENT_QUOTES, 'UTF-8'); ?>
                        <button class="btn btn-outline confirm-action" data-message="Load facility data for editing?" type="button" data-facility='<?= $payload; ?>'>Edit Details</button>
                    </article>
                <?php endforeach; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1; ?>">&larr; Prev</a>
                        <?php endif; ?>
                        <span class="current">Page <?= $page; ?> of <?= $totalPages; ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1; ?>">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Facility Modal -->
<div id="facilityModal" class="facility-modal">
    <div class="facility-modal-backdrop" onclick="closeFacilityModal()"></div>
    <div class="facility-modal-dialog">
        <div class="facility-modal-content">
            <div class="facility-modal-header">
                <h2 id="form-title">Add Facility</h2>
                <button type="button" class="facility-modal-close" onclick="closeFacilityModal()" aria-label="Close">×</button>
            </div>
            <div class="facility-modal-body">
                <form class="facility-form" method="POST" enctype="multipart/form-data" id="facilityForm">
                    <input type="hidden" name="facility_id" id="facility_id">
                    <label>
                        Facility Name
                        <div class="input-wrapper">
                            <span class="input-icon">🏛️</span>
                            <input type="text" name="name" id="form-name" placeholder="e.g., Barangay Function Room" required>
                        </div>
                    </label>
                    <label>
                        Standard Rate
                        <div class="input-wrapper">
                            <span class="input-icon">💰</span>
                            <input type="text" name="base_rate" id="form-rate" placeholder="₱2,500 / 4 hrs">
                        </div>
                    </label>
                    <label>
                        Description
                        <textarea name="description" id="form-description" placeholder="Key features, inclusions, restrictions"></textarea>
                    </label>
                    <label>
                        Location
                        <div class="input-wrapper">
                            <span class="input-icon">📍</span>
                            <input type="text" name="location" id="form-location" placeholder="e.g., Barangay Culiat, Quezon City">
                        </div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Full address for location-based recommendations</small>
                        <div id="facility-geocode-status" style="margin-top:0.25rem; display:none; font-size:0.85rem;"></div>
                    </label>
                    <label>
                        Latitude (Optional)
                        <div class="input-wrapper">
                            <span class="input-icon">🌐</span>
                            <input type="number" step="any" name="latitude" id="form-latitude" placeholder="14.6760">
                        </div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Will be auto-filled when you enter an address</small>
                    </label>
                    <label>
                        Longitude (Optional)
                        <div class="input-wrapper">
                            <span class="input-icon">🌐</span>
                            <input type="number" step="any" name="longitude" id="form-longitude" placeholder="121.0437">
                        </div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Will be auto-filled when you enter an address</small>
                    </label>
                    <label>
                        Capacity
                        <div class="input-wrapper">
                            <span class="input-icon">👥</span>
                            <input type="text" name="capacity" id="form-capacity" placeholder="e.g., 200 persons">
                        </div>
                    </label>
                    <label>
                        Amenities
                        <textarea name="amenities" id="form-amenities" placeholder="e.g., Sound system, projector, chairs, air-conditioning"></textarea>
                    </label>
                    <label>
                        Rules / Guidelines
                        <textarea name="rules" id="form-rules" placeholder="Key house rules to show on the public page"></textarea>
                    </label>
                    <label>
                        Facility Image
                        <input type="file" name="image" id="form-image" accept="image/*">
                    </label>
                    <label>
                        Image Citation
                        <div class="input-wrapper">
                            <span class="input-icon">📷</span>
                            <input type="text" name="image_citation" id="form-image-citation" placeholder="e.g., Google Maps, Photo by John Doe">
                        </div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Source/attribution for the facility image (optional)</small>
                    </label>
                    <label>
                        Status
                        <div class="input-wrapper">
                            <span class="input-icon">📊</span>
                            <select name="status" id="form-status">
                                <option value="available">Available</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="offline">Offline</option>
                            </select>
                        </div>
                    </label>
                    <label>
                        Operating Hours
                        <div class="input-wrapper">
                            <span class="input-icon">🕐</span>
                            <input type="text" name="operating_hours" id="form-operating-hours" placeholder="e.g., 09:00-16:00 or 8:00 AM - 4:00 PM">
                        </div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                            Facility operating hours. Format: HH:MM-HH:MM (24-hour) or HH:MM AM/PM - HH:MM AM/PM. Example: "09:00-16:00" or "8:00 AM - 4:00 PM". Leave blank for default (8:00 AM - 9:00 PM).
                        </small>
                    </label>

                    <!-- Auto-Approval Settings as Collapsible Section -->
                    <div class="collapsible-card" style="margin-top: 1.5rem;">
                        <button type="button" class="collapsible-header" id="auto-approval-header" onclick="toggleAutoApprovalSection(event);" style="cursor: pointer;">
                            <span>Auto-Approval Settings</span>
                            <span class="chevron" id="auto-approval-chevron">▼</span>
                        </button>
                        <div class="collapsible-body is-collapsed" id="auto-approval-settings">
                            <p style="margin:0 0 1rem; color:#5b6888; font-size:0.85rem; line-height:1.5;">
                                When enabled, reservations meeting all conditions will be automatically approved without staff review.
                            </p>

                            <label style="display:flex; align-items:flex-start; gap:0.5rem; margin-bottom:1rem; cursor:pointer;">
                                <input type="checkbox" name="auto_approve" value="1" id="form-auto-approve" style="width:18px; height:18px; min-width:18px; flex-shrink:0; margin-top:0.125rem;">
                                <span style="flex:1; line-height:1.5;">Enable auto-approval for this facility</span>
                            </label>

                            <label>
                                Capacity Threshold (Optional)
                                <div class="input-wrapper">
                                    <span class="input-icon">👥</span>
                                    <input type="number" name="capacity_threshold" id="form-capacity-threshold" min="1" placeholder="e.g., 100">
                                </div>
                                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                                    Maximum expected attendees allowed for auto-approval. Leave blank for no limit.
                                </small>
                            </label>

                            <label>
                                Maximum Duration (hours, Optional)
                                <div class="input-wrapper">
                                    <span class="input-icon">⏰</span>
                                    <input type="number" step="0.5" name="max_duration_hours" id="form-max-duration" min="0.5" placeholder="e.g., 4.0">
                                </div>
                                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                                    Maximum reservation duration in hours for auto-approval. Leave blank for no limit. (Default time slots are 4 hours each)
                                </small>
                            </label>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                        <button class="btn-primary" type="submit">Save Facility</button>
                        <button class="btn-outline" type="button" onclick="resetFacilityForm()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Disable global collapsible handler for this page to prevent conflicts
window.DISABLE_GLOBAL_COLLAPSIBLE = true;

function openFacilityModal(resetForm = true) {
    const modal = document.getElementById('facilityModal');
    if (resetForm) {
        resetFacilityForm();
    } else {
        // When editing, ensure auto-approval section chevron is initialized correctly
        const autoApprovalSection = document.getElementById('auto-approval-settings');
        const autoApprovalChevron = document.getElementById('auto-approval-chevron');
        if (autoApprovalSection && autoApprovalChevron) {
            if (autoApprovalSection.classList.contains('is-collapsed')) {
                autoApprovalChevron.style.transform = 'rotate(-90deg)';
            } else {
                autoApprovalChevron.style.transform = 'rotate(0deg)';
            }
        }
    }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
        const nameField = document.getElementById('form-name');
        if (nameField) {
            nameField.focus();
        }
    }, 100);
}

function closeFacilityModal() {
    const modal = document.getElementById('facilityModal');
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

function toggleAutoApprovalSection(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const section = document.getElementById('auto-approval-settings');
    const chevron = document.getElementById('auto-approval-chevron');
    const isCollapsed = section.classList.contains('is-collapsed');
    
    if (isCollapsed) {
        section.classList.remove('is-collapsed');
        chevron.style.transform = 'rotate(0deg)';
    } else {
        section.classList.add('is-collapsed');
        chevron.style.transform = 'rotate(-90deg)';
    }
}

function editFacility(payload) {
    const facility = typeof payload === 'string' ? JSON.parse(payload) : payload;
    
    // Set form title first
    document.getElementById('form-title').textContent = 'Update Facility';
    
    // Populate all form fields
    document.getElementById('facility_id').value = facility.id || '';
    document.getElementById('form-name').value = facility.name || '';
    document.getElementById('form-rate').value = facility.base_rate || '';
    document.getElementById('form-description').value = facility.description || '';
    document.getElementById('form-location').value = facility.location || '';
    document.getElementById('form-latitude').value = facility.latitude || '';
    document.getElementById('form-longitude').value = facility.longitude || '';
    document.getElementById('form-capacity').value = facility.capacity || '';
    document.getElementById('form-amenities').value = facility.amenities || '';
    document.getElementById('form-rules').value = facility.rules || '';
    document.getElementById('form-image-citation').value = facility.image_citation || '';
    document.getElementById('form-status').value = facility.status || 'available';
    document.getElementById('form-operating-hours').value = facility.operating_hours || '';
    document.getElementById('form-auto-approve').checked = (facility.auto_approve == 1 || facility.auto_approve === true);
    document.getElementById('form-capacity-threshold').value = facility.capacity_threshold || '';
    document.getElementById('form-max-duration').value = facility.max_duration_hours || '';
    
    // Open modal WITHOUT resetting the form (pass false)
    openFacilityModal(false);
}

function resetFacilityForm() {
    document.getElementById('form-title').textContent = 'Add Facility';
    document.getElementById('facility_id').value = '';
    document.getElementById('form-name').value = '';
    document.getElementById('form-rate').value = '';
    document.getElementById('form-description').value = '';
    document.getElementById('form-location').value = '';
    document.getElementById('form-latitude').value = '';
    document.getElementById('form-longitude').value = '';
    document.getElementById('form-capacity').value = '';
    document.getElementById('form-amenities').value = '';
    document.getElementById('form-rules').value = '';
    document.getElementById('form-image-citation').value = '';
    document.getElementById('form-status').value = 'available';
    document.getElementById('form-operating-hours').value = '';
    document.getElementById('form-auto-approve').checked = false;
    document.getElementById('form-capacity-threshold').value = '';
    document.getElementById('form-max-duration').value = '';
    document.getElementById('form-image').value = '';
    
    // Reset auto-approval section to collapsed state
    const autoApprovalSection = document.getElementById('auto-approval-settings');
    const autoApprovalChevron = document.getElementById('auto-approval-chevron');
    if (autoApprovalSection && autoApprovalChevron) {
        autoApprovalSection.classList.add('is-collapsed');
        autoApprovalChevron.style.transform = 'rotate(-90deg)';
    }
}

// Collapsible helper with localStorage persistence
(function() {
    const STORAGE_KEY = 'collapse-state-facility-management';
    let state = {};
    let initialized = false;
    
    try {
        state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    } catch (e) {
        state = {};
    }

    function saveState() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    function initCollapsibles() {
        if (initialized) return; // Prevent duplicate initialization
        
        document.querySelectorAll('.collapsible-header').forEach(header => {
            const targetId = header.getAttribute('data-collapse-target');
            if (!targetId) return;
            
            const body = document.getElementById(targetId);
            if (!body) return;
            const chevron = header.querySelector('.chevron');

            // Remove any existing listeners by cloning
            const newHeader = header.cloneNode(true);
            header.parentNode.replaceChild(newHeader, header);
            const freshHeader = document.querySelector(`[data-collapse-target="${targetId}"]`);
            const freshBody = document.getElementById(targetId);
            const freshChevron = freshHeader.querySelector('.chevron');

            // Apply saved state
            if (state[targetId]) {
                freshBody.classList.add('is-collapsed');
                if (freshChevron) freshChevron.style.transform = 'rotate(-90deg)';
            } else {
                freshBody.classList.remove('is-collapsed');
                if (freshChevron) freshChevron.style.transform = 'rotate(0deg)';
            }

            // Add click handler
            freshHeader.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const currentCollapsed = freshBody.classList.contains('is-collapsed');
                const newCollapsed = !currentCollapsed;
                
                if (newCollapsed) {
                    freshBody.classList.add('is-collapsed');
                } else {
                    freshBody.classList.remove('is-collapsed');
                }
                
                if (freshChevron) {
                    freshChevron.style.transform = newCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
                }
                
                state[targetId] = newCollapsed;
                saveState();
            });
        });
        
        initialized = true;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCollapsibles);
    } else {
        initCollapsibles();
    }
    
    // Fallback initialization
    setTimeout(initCollapsibles, 300);
})();

// Geocoding for facility address
(function() {
    const base = (typeof window !== 'undefined' && window.APP_BASE_PATH) ? window.APP_BASE_PATH : '';
    const addressEl = document.getElementById('form-location');
    const latEl = document.getElementById('form-latitude');
    const lngEl = document.getElementById('form-longitude');
    const statusEl = document.getElementById('facility-geocode-status');
    if (!addressEl || !latEl || !lngEl) return;

    let geocodeTimer = null;
    function showGeocodeStatus(msg, isError) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.display = msg ? 'block' : 'none';
        statusEl.style.color = isError ? '#c00' : '#0d7a43';
    }

    function fetchGeocode() {
        const addr = (addressEl.value || '').trim();
        if (addr.length < 5) {
            showGeocodeStatus('', false);
            return;
        }
        showGeocodeStatus('Looking up coordinates…', false);
        const form = new URLSearchParams();
        form.append('address', addr);
        fetch(base + '/resources/views/pages/dashboard/geocode_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form
        })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.lat != null && data.lng != null) {
                    latEl.value = data.lat;
                    lngEl.value = data.lng;
                    showGeocodeStatus('✓ Coordinates updated from address', false);
                    setTimeout(function() { showGeocodeStatus('', false); }, 3000);
                } else {
                    showGeocodeStatus(data.error || 'Could not find coordinates for this address', true);
                }
            })
            .catch(function() {
                showGeocodeStatus('Geocoding unavailable. Enter coordinates manually.', true);
            });
    }

    addressEl.addEventListener('blur', fetchGeocode);
    addressEl.addEventListener('input', function() {
        if (geocodeTimer) clearTimeout(geocodeTimer);
        geocodeTimer = setTimeout(fetchGeocode, 800);
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
