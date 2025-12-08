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
require_once __DIR__ . '/../../../../config/audit.php';
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

    // Handle image upload (optional) with enhanced security
    $imagePath = null;
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        require_once __DIR__ . '/../../../../config/security.php';
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
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                // Additional security: Set proper file permissions
                @chmod($targetPath, 0644);
                $imagePath = '/public/img/facilities/' . $fileName;
            } else {
                $message = 'Failed to upload image. Please try again.';
                $messageType = 'error';
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

                $stmt = $pdo->prepare('UPDATE facilities SET name = ?, description = ?, base_rate = ?, image_path = ?, image_citation = ?, location = ?, latitude = ?, longitude = ?, capacity = ?, amenities = ?, rules = ?, status = ? WHERE id = ?');
                $stmt->execute([$name, $description, $rate, $imagePath, $imageCitation ?: null, $location, $latitude, $longitude, $capacity, $amenities, $rules, $status, $facilityId]);
                
                // Log audit event
                $details = $name;
                if ($oldFacility && $oldFacility['status'] !== $status) {
                    $details .= ' – Status changed from ' . $oldFacility['status'] . ' to ' . $status;
                }
                logAudit('Updated facility', 'Facility Management', $details);
                
                $message = 'Facility updated successfully.';
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
                
                $stmt = $pdo->prepare('INSERT INTO facilities (name, description, base_rate, image_path, image_citation, location, latitude, longitude, capacity, amenities, rules, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $description, $rate, $imagePath, $imageCitation ?: null, $location, $latitude, $longitude, $capacity, $amenities, $rules, $status]);
                
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

$facilitiesStmt = $pdo->prepare('SELECT *, latitude, longitude FROM facilities ORDER BY updated_at DESC LIMIT :limit OFFSET :offset');
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
    <section>
        <?php if (empty($facilities)): ?>
            <article class="facility-card-admin">
                <p>No facilities added yet. Use the form to add your first facility.</p>
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
                    <div class="availability-toggle">
                        <input type="checkbox" <?= $facility['status'] === 'available' ? 'checked' : ''; ?> disabled>
                        <span><?= $facility['status'] === 'available' ? 'Available for booking' : ($facility['status'] === 'maintenance' ? 'Under Maintenance' : 'Offline'); ?></span>
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
    </section>

    <aside>
        <div class="facility-card-admin">
            <h3 id="form-title">Add Facility</h3>
            <form class="facility-form" method="POST" enctype="multipart/form-data">
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
                </label>
                <label>
                    Latitude (Optional)
                    <div class="input-wrapper">
                        <span class="input-icon">🌐</span>
                        <input type="number" step="any" name="latitude" id="form-latitude" placeholder="14.6760">
                    </div>
                    <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Will be auto-filled if Google Maps API is configured</small>
                </label>
                <label>
                    Longitude (Optional)
                    <div class="input-wrapper">
                        <span class="input-icon">🌐</span>
                        <input type="number" step="any" name="longitude" id="form-longitude" placeholder="121.0437">
                    </div>
                    <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Will be auto-filled if Google Maps API is configured</small>
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
                <button class="btn-primary" type="submit">Save Facility</button>
                <button class="btn-outline" type="button" onclick="resetFacilityForm()" style="margin-top:0.5rem;">Cancel / New Facility</button>
            </form>
        </div>

        <div class="facility-card-admin">
            <h3>Recent Activity</h3>
            <?php if (empty($auditTrail)): ?>
                <p style="color:#8b95b5; font-size:0.9rem; margin:0;">No activity recorded yet.</p>
            <?php else: ?>
                <ul class="audit-list">
                    <?php foreach ($auditTrail as $entry): ?>
                        <li>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem;">
                                <div style="flex:1;">
                                    <strong style="display:block; color:#1b1b1f; font-size:0.9rem; margin-bottom:0.25rem;">
                                        <?= htmlspecialchars($entry['action']); ?>
                                    </strong>
                                    <?php if ($entry['details']): ?>
                                        <p style="margin:0; color:#5b6888; font-size:0.85rem; line-height:1.4;">
                                            <?= htmlspecialchars($entry['details']); ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($entry['user_name']): ?>
                                        <small style="color:#8b95b5; font-size:0.8rem; display:block; margin-top:0.25rem;">
                                            by <?= htmlspecialchars($entry['user_name']); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <small style="color:#8b95b5; font-size:0.75rem; white-space:nowrap;">
                                    <?= date('M j, Y g:i A', strtotime($entry['created_at'])); ?>
                                </small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($auditTotalPages > 1): ?>
                    <div class="pagination" style="margin-top:0.75rem; justify-content:center;">
                        <?php if ($auditPage > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['audit_page' => $auditPage - 1])); ?>">&larr; Prev</a>
                        <?php endif; ?>
                        <span class="current">Page <?= $auditPage; ?> of <?= $auditTotalPages; ?></span>
                        <?php if ($auditPage < $auditTotalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['audit_page' => $auditPage + 1])); ?>">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/audit_trail.php?module=Facility+Management" 
                   style="display:block; text-align:center; margin-top:0.75rem; color:var(--gov-blue); font-size:0.85rem; font-weight:500;">
                    View Full Audit Trail →
                </a>
            <?php endif; ?>
        </div>
    </aside>
</div>

<script>
function editFacility(payload) {
    const facility = JSON.parse(payload);
    document.getElementById('form-title').textContent = 'Update Facility';
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
    document.getElementById('form-name').focus();
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
    document.getElementById('form-image').value = '';
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
