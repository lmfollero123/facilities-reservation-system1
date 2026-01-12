<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

// Verify user is authenticated and has admin/staff role
if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/resources/views/pages/dashboard/index.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/upload_helper.php';
require_once __DIR__ . '/../../../../config/notifications.php';

$pdo = db();
$base = base_path();
$pageTitle = 'Announcements Management | LGU Facilities Reservation';

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        // Create new announcement
        $title = trim($_POST['title'] ?? '');
        $message_text = trim($_POST['message'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $link = trim($_POST['link'] ?? '');
        
        if (empty($title) || empty($message_text)) {
            $message = 'Title and message are required.';
            $messageType = 'error';
        } else {
            // Handle image upload (optional)
            $imagePath = null;
            if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadErrors = validateFileUpload($_FILES['image'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 5 * 1024 * 1024);
                
                if (!empty($uploadErrors)) {
                    $message = implode(' ', $uploadErrors);
                    $messageType = 'error';
                } else {
                    $uploadDir = __DIR__ . '/../../../../public/img/announcements';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    
                    $filename = 'announcement_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . '/' . $filename)) {
                        $imagePath = '/public/img/announcements/' . $filename;
                    }
                }
            }
            
            if ($messageType !== 'error') {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO notifications (user_id, type, title, message, link, image_path, created_at) 
                         VALUES (NULL, :type, :title, :message, :link, :image_path, NOW())'
                    );
                    $stmt->execute([
                        'type' => 'system',
                        'title' => $title,
                        'message' => $message_text,
                        'link' => $link ?: null,
                        'image_path' => $imagePath,
                    ]);
                    
                    logAudit('Created announcement', 'Announcements', 'Title: ' . $title . ', Category: ' . $category);
                    
                    $message = 'Announcement created successfully!';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    error_log('Announcement creation failed: ' . $e->getMessage());
                    $message = 'Failed to create announcement. Please try again.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'delete') {
        // Delete announcement
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        
        if ($announcementId) {
            try {
                // Get announcement details for audit log
                $stmt = $pdo->prepare('SELECT title FROM notifications WHERE id = :id AND user_id IS NULL');
                $stmt->execute(['id' => $announcementId]);
                $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($announcement) {
                    $deleteStmt = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id IS NULL');
                    $deleteStmt->execute(['id' => $announcementId]);
                    
                    logAudit('Deleted announcement', 'Announcements', 'Title: ' . $announcement['title']);
                    
                    $message = 'Announcement deleted successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Announcement not found.';
                    $messageType = 'error';
                }
            } catch (Throwable $e) {
                error_log('Announcement deletion failed: ' . $e->getMessage());
                $message = 'Failed to delete announcement. Please try again.';
                $messageType = 'error';
            }
        }
    }
}

// Fetch all public announcements (user_id IS NULL)
$announcementsStmt = $pdo->query(
    'SELECT id, title, message, link, image_path, created_at 
     FROM notifications 
     WHERE user_id IS NULL 
     ORDER BY created_at DESC'
);
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<div class="dashboard-header-section">
    <h1><i class="bi bi-megaphone"></i> Announcements Management</h1>
    <p>Create and manage public announcements displayed on the homepage</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
        <?= htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Create Announcement Form -->
<div class="booking-card mb-4">
    <div class="card-header-custom">
        <h2>Create New Announcement</h2>
        <small>Fill out the form below to create a new public announcement</small>
    </div>
    
    <form method="POST" enctype="multipart/form-data" class="announcement-form">
        <input type="hidden" name="action" value="create">
        
        <div class="form-group">
            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title" required maxlength="200" placeholder="e.g., Water Interruption Notice, Community Event, Maintenance Schedule">
            <small class="form-text text-muted">Clear, official-sounding title (max 200 chars)</small>
        </div>
        
        <div class="form-group">
            <label for="message" class="form-label">Message <span class="text-danger">*</span></label>
            <textarea class="form-control" id="message" name="message" rows="6" required placeholder="Announcement details and information for residents..."></textarea>
            <small class="form-text text-muted">2-4 sentences explaining the announcement. Be clear and direct.</small>
        </div>
        
        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="category" class="form-label">Category</label>
                <select class="form-control" id="category" name="category">
                    <option value="general">General</option>
                    <option value="urgent">Urgent / Emergency</option>
                    <option value="advisory">Advisory / Notice</option>
                    <option value="event">Event / Activity</option>
                </select>
                <small class="form-text text-muted">Category affects how announcement displays</small>
            </div>
            
            <div class="form-group col-md-6">
                <label for="link" class="form-label">Optional Link</label>
                <input type="url" class="form-control" id="link" name="link" placeholder="https://example.com/page">
                <small class="form-text text-muted">Link to related page or document (optional)</small>
            </div>
        </div>
        
        <div class="form-group">
            <label for="image" class="form-label">Feature Image (Optional)</label>
            <div class="image-upload-wrapper">
                <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewImage(event)">
                <small class="form-text text-muted">Supported: JPEG, PNG, GIF, WebP (Max 5MB). Recommended: 800x400px</small>
                <div id="image-preview" style="margin-top: 1rem; display: none;">
                    <img id="preview-img" src="" alt="Preview" style="max-width: 100%; max-height: 300px; border-radius: 8px;">
                    <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="clearImage()">Clear Image</button>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Create Announcement
            </button>
            <button type="reset" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise"></i> Clear Form
            </button>
        </div>
    </form>
</div>

<!-- Existing Announcements -->
<div class="booking-card">
    <div class="card-header-custom">
        <h2>Published Announcements</h2>
        <small><?= count($announcements); ?> announcement(s) published</small>
    </div>
    
    <?php if (empty($announcements)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <p>No announcements yet.</p>
            <small>Create your first announcement using the form above.</small>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Preview</th>
                        <th>Image</th>
                        <th>Published</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $announcement): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars(mb_strimwidth($announcement['title'], 0, 50, '…')); ?></strong>
                            </td>
                            <td>
                                <small><?= htmlspecialchars(mb_strimwidth($announcement['message'], 0, 100, '…')); ?></small>
                                <?php if (!empty($announcement['link'])): ?>
                                    <br><small class="text-muted"><i class="bi bi-link-45deg"></i> Has link</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($announcement['image_path'])): ?>
                                    <img src="<?= htmlspecialchars($base . $announcement['image_path']); ?>" alt="Announcement" style="max-width: 60px; max-height: 60px; object-fit: cover; border-radius: 4px;">
                                <?php else: ?>
                                    <span class="text-muted small">No image</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= date('M d, Y g:i A', strtotime($announcement['created_at'])); ?></small>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="announcement_id" value="<?= (int)$announcement['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete announcement">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
/* Announcement Management Styles */
.dashboard-header-section {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%);
    border-radius: 12px;
    color: white;
}

.dashboard-header-section h1 {
    margin: 0;
    font-size: 1.75rem;
    font-weight: 700;
}

.dashboard-header-section p {
    margin: 0;
    color: rgba(255,255,255,0.9);
    font-size: 0.95rem;
}

.card-header-custom {
    border-bottom: 1px solid #e5e7eb;
    margin: -1.5rem -1.5rem 1.5rem -1.5rem;
    padding: 1.5rem;
    background: #f9fafb;
}

.card-header-custom h2 {
    margin: 0 0 0.25rem 0;
    font-size: 1.5rem;
    color: #1f2937;
}

.card-header-custom small {
    color: #6b7280;
}

/* Form Styles */
.announcement-form {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 0.5rem;
}

.form-control {
    padding: 0.75rem 1rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-family: 'Poppins', sans-serif;
    transition: all 0.2s ease;
}

.form-control:focus {
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
    outline: none;
}

.form-control textarea {
    resize: vertical;
    min-height: 120px;
}

.form-text {
    font-size: 0.875rem;
    margin-top: 0.25rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

@media (max-width: 480px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

.image-upload-wrapper {
    padding: 1rem;
    border: 2px dashed #d1d5db;
    border-radius: 8px;
    background: #f9fafb;
    transition: all 0.2s ease;
}

.image-upload-wrapper:hover {
    border-color: #6384d2;
    background: #f0f4ff;
}

.image-upload-wrapper input {
    padding: 0.5rem 0 !important;
    border: none !important;
    background: transparent !important;
}

.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

@media (max-width: 480px) {
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions button {
        width: 100%;
    }
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #8b95b5;
}

.empty-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #d1d5db;
}

.empty-state p {
    font-size: 1.125rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #4b5563;
}

.empty-state small {
    color: #9ca3af;
}

/* Table Styles */
.table {
    margin-bottom: 0;
}

.table thead {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.table th {
    font-weight: 600;
    color: #1f2937;
    padding: 1rem 0.75rem;
}

.table td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
}

.table tbody tr {
    border-bottom: 1px solid #f3f4f6;
    transition: background-color 0.2s ease;
}

.table tbody tr:hover {
    background-color: #fafbfc;
}

/* Alerts */
.alert {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.alert-success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #86efac;
}

.alert-danger {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

/* Buttons */
.btn {
    font-weight: 600;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    transition: all 0.2s ease;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, #6384d2, #285ccd);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(99, 132, 210, 0.3);
}

.btn-outline-secondary {
    border: 2px solid #d1d5db;
    color: #4b5563;
    background: white;
}

.btn-outline-secondary:hover {
    border-color: #6384d2;
    color: #6384d2;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
}

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-close {
    cursor: pointer;
    background: transparent;
    border: none;
    font-size: 1.25rem;
    opacity: 0.7;
    transition: opacity 0.2s ease;
}

.btn-close:hover {
    opacity: 1;
}

/* Responsive */
@media (max-width: 768px) {
    .dashboard-header-section {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .dashboard-header-section h1 {
        font-size: 1.5rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table th,
    .table td {
        padding: 0.75rem 0.5rem;
    }
}
</style>

<script>
function previewImage(event) {
    const file = event.target.files[0];
    const preview = document.getElementById('image-preview');
    const img = document.getElementById('preview-img');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
}

function clearImage() {
    document.getElementById('image').value = '';
    document.getElementById('image-preview').style.display = 'none';
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
?>
