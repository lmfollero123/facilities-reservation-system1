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
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/upload_helper.php';

$pdo = db();
$base = base_path();
$pageTitle = 'Announcements Management | LGU Facilities Reservation';

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $link = trim($_POST['link'] ?? '');
        
        if (empty($title) || empty($message)) {
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
                    
                    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($title));
                    $fileName = $safeTitle . '-' . time() . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $fileName;
                    
                    [$ok, $err] = saveOptimizedImage($_FILES['image']['tmp_name'], $targetPath, 1600, 82);
                    if (!$ok) {
                        // Fallback to original move for GIFs/unsupported types
                        if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                            $message = $err ?: 'Failed to upload image. Please try again.';
                            $messageType = 'error';
                        } else {
                            @chmod($targetPath, 0644);
                            $imagePath = '/public/img/announcements/' . $fileName;
                        }
                    } else {
                        @chmod($targetPath, 0644);
                        $imagePath = '/public/img/announcements/' . $fileName;
                    }
                }
            }
            
            if ($messageType !== 'error') {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO notifications (user_id, type, title, message, link, image_path) 
                         VALUES (NULL, :type, :title, :message, :link, :image_path)'
                    );
                    $stmt->execute([
                        'type' => 'system',
                        'title' => $title,
                        'message' => $message,
                        'link' => $link ?: null,
                        'image_path' => $imagePath,
                    ]);
                    
                    logAudit('Created announcement', 'Announcements', 'Title: ' . $title);
                    
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
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        if ($announcementId > 0) {
            try {
                // Get announcement data for audit log and image deletion
                $getStmt = $pdo->prepare('SELECT title, image_path FROM notifications WHERE id = ? AND user_id IS NULL');
                $getStmt->execute([$announcementId]);
                $announcement = $getStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($announcement) {
                    // Delete the announcement
                    $deleteStmt = $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id IS NULL');
                    $deleteStmt->execute([$announcementId]);
                    
                    // Delete image file if exists
                    if (!empty($announcement['image_path'])) {
                        $imagePath = __DIR__ . '/../../../../' . $announcement['image_path'];
                        if (file_exists($imagePath)) {
                            @unlink($imagePath);
                        }
                    }
                    
                    logAudit('Deleted announcement', 'Announcements', 'Title: ' . ($announcement['title'] ?? 'Unknown'));
                    
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
    <h1>Announcements Management</h1>
    <p class="text-muted">Create and manage public announcements displayed on the home page</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : ($messageType === 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Create Announcement Form -->
<div class="booking-card mb-4">
    <h2 class="mb-3">Create New Announcement</h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        
        <div class="mb-3">
            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title" required maxlength="150" placeholder="e.g., Public Health Advisory, City Event, Emergency Notice">
        </div>
        
        <div class="mb-3">
            <label for="message" class="form-label">Message <span class="text-danger">*</span></label>
            <textarea class="form-control" id="message" name="message" rows="5" required placeholder="Enter the announcement message..."></textarea>
        </div>
        
        <div class="mb-3">
            <label for="link" class="form-label">Optional Link</label>
            <input type="url" class="form-control" id="link" name="link" placeholder="https://example.com (optional)">
            <small class="form-text text-muted">Link to related page or external resource</small>
        </div>
        
        <div class="mb-3">
            <label for="image" class="form-label">Image (Optional)</label>
            <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <small class="form-text text-muted">Supported formats: JPEG, PNG, GIF, WebP (Max 5MB)</small>
        </div>
        
        <button type="submit" class="btn btn-primary">Create Announcement</button>
    </form>
</div>

<!-- Existing Announcements -->
<div class="booking-card">
    <h2 class="mb-3">Existing Announcements</h2>
    
    <?php if (empty($announcements)): ?>
        <p class="text-muted">No announcements yet. Create one above to get started.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Message Preview</th>
                        <th>Image</th>
                        <th>Date Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $announcement): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($announcement['title']); ?></strong></td>
                            <td>
                                <?= htmlspecialchars(mb_strimwidth($announcement['message'], 0, 100, '…')); ?>
                                <?php if (!empty($announcement['link'])): ?>
                                    <br><small class="text-muted"><i class="bi bi-link-45deg"></i> Has link</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($announcement['image_path'])): ?>
                                    <img src="<?= htmlspecialchars($base . $announcement['image_path']); ?>" alt="Announcement image" style="max-width: 80px; max-height: 80px; object-fit: cover; border-radius: 4px;">
                                <?php else: ?>
                                    <span class="text-muted">No image</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y g:i A', strtotime($announcement['created_at'])); ?></td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="announcement_id" value="<?= (int)$announcement['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
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

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
?>
