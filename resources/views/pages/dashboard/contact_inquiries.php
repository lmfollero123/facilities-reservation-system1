<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication and admin/staff role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

$pdo = db();
$base = base_path();
$pageTitle = 'Contact Inquiries | Dashboard';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token.';
    } else {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '', 'string');
        $notes = sanitizeInput($_POST['admin_notes'] ?? '', 'string');
        
        if (in_array($status, ['new', 'in_progress', 'resolved', 'closed'])) {
            $stmt = $pdo->prepare(
                'UPDATE contact_inquiries 
                 SET status = ?, admin_notes = ?, responded_by = ?, 
                     responded_at = CASE WHEN ? != "new" THEN NOW() ELSE responded_at END
                 WHERE id = ?'
            );
            $stmt->execute([$status, $notes ?: null, $_SESSION['user_id'], $status, $inquiryId]);
            $success = 'Inquiry status updated successfully.';
        }
    }
}

// Get filter
$statusFilter = $_GET['status'] ?? 'all';
$statusFilter = in_array($statusFilter, ['all', 'new', 'in_progress', 'resolved', 'closed']) ? $statusFilter : 'all';

// Get inquiries
$query = 'SELECT ci.*, u.name AS responded_by_name 
          FROM contact_inquiries ci
          LEFT JOIN users u ON ci.responded_by = u.id
          WHERE 1=1';
$params = [];

if ($statusFilter !== 'all') {
    $query .= ' AND ci.status = ?';
    $params[] = $statusFilter;
}

$query .= ' ORDER BY ci.created_at DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get single inquiry for detail view
$inquiry = null;
if (isset($_GET['id'])) {
    $detailStmt = $pdo->prepare('SELECT ci.*, u.name AS responded_by_name FROM contact_inquiries ci LEFT JOIN users u ON ci.responded_by = u.id WHERE ci.id = ?');
    $detailStmt->execute([(int)$_GET['id']]);
    $inquiry = $detailStmt->fetch(PDO::FETCH_ASSOC);
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Contact Inquiries</span>
    </div>
    <h1>Contact Inquiries</h1>
    <small>View and manage inquiries, concerns, and technical issues from the public contact form.</small>
</div>

<?php if (isset($success)): ?>
    <div style="background: #e3f8ef; color: #0d7a43; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($inquiry): ?>
    <!-- Detail View -->
    <div class="card-elevated">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>Inquiry Details</h2>
            <a href="<?= $base; ?>/resources/views/pages/dashboard/contact_inquiries.php" class="btn btn-outline">← Back to List</a>
        </div>
        
        <div style="background: #f5f7fd; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <strong style="color: #6b7897; font-size: 0.85rem;">Name</strong>
                    <p style="margin: 0.25rem 0 0; font-weight: 600;"><?= htmlspecialchars($inquiry['name']); ?></p>
                </div>
                <div>
                    <strong style="color: #6b7897; font-size: 0.85rem;">Email</strong>
                    <p style="margin: 0.25rem 0 0;"><a href="mailto:<?= htmlspecialchars($inquiry['email']); ?>"><?= htmlspecialchars($inquiry['email']); ?></a></p>
                </div>
                <?php if ($inquiry['organization']): ?>
                <div>
                    <strong style="color: #6b7897; font-size: 0.85rem;">Organization</strong>
                    <p style="margin: 0.25rem 0 0;"><?= htmlspecialchars($inquiry['organization']); ?></p>
                </div>
                <?php endif; ?>
                <div>
                    <strong style="color: #6b7897; font-size: 0.85rem;">Status</strong>
                    <p style="margin: 0.25rem 0 0;">
                        <span class="status-badge <?= $inquiry['status']; ?>"><?= ucfirst(str_replace('_', ' ', $inquiry['status'])); ?></span>
                    </p>
                </div>
                <div>
                    <strong style="color: #6b7897; font-size: 0.85rem;">Submitted</strong>
                    <p style="margin: 0.25rem 0 0;"><?= date('M d, Y g:i A', strtotime($inquiry['created_at'])); ?></p>
                </div>
            </div>
            
            <div style="margin-top: 1.5rem;">
                <strong style="color: #6b7897; font-size: 0.85rem;">Message</strong>
                <p style="margin: 0.75rem 0 0; white-space: pre-wrap; line-height: 1.7;"><?= htmlspecialchars($inquiry['message']); ?></p>
            </div>
        </div>
        
        <form method="POST" class="booking-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="inquiry_id" value="<?= $inquiry['id']; ?>">
            
            <label>
                <span>Status</span>
                <select name="status" required>
                    <option value="new" <?= $inquiry['status'] === 'new' ? 'selected' : ''; ?>>New</option>
                    <option value="in_progress" <?= $inquiry['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="resolved" <?= $inquiry['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="closed" <?= $inquiry['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
            </label>
            
            <label>
                <span>Admin Notes</span>
                <textarea name="admin_notes" rows="4" placeholder="Add notes about this inquiry..."><?= htmlspecialchars($inquiry['admin_notes'] ?? ''); ?></textarea>
            </label>
            
            <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
        </form>
        
        <?php if ($inquiry['responded_by_name']): ?>
            <p style="margin-top: 1rem; color: #6b7897; font-size: 0.85rem;">
                Last updated by <?= htmlspecialchars($inquiry['responded_by_name']); ?> 
                <?= $inquiry['responded_at'] ? 'on ' . date('M d, Y g:i A', strtotime($inquiry['responded_at'])) : ''; ?>
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- List View -->
    <div class="card-elevated">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="?status=all" class="btn <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline'; ?>">All</a>
                <a href="?status=new" class="btn <?= $statusFilter === 'new' ? 'btn-primary' : 'btn-outline'; ?>">New</a>
                <a href="?status=in_progress" class="btn <?= $statusFilter === 'in_progress' ? 'btn-primary' : 'btn-outline'; ?>">In Progress</a>
                <a href="?status=resolved" class="btn <?= $statusFilter === 'resolved' ? 'btn-primary' : 'btn-outline'; ?>">Resolved</a>
                <a href="?status=closed" class="btn <?= $statusFilter === 'closed' ? 'btn-primary' : 'btn-outline'; ?>">Closed</a>
            </div>
        </div>
        
        <?php if (empty($inquiries)): ?>
            <p style="text-align: center; color: #6b7897; padding: 2rem;">No inquiries found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f5f7fd; border-bottom: 2px solid #e1e7f0;">
                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Name</th>
                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Email</th>
                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Message Preview</th>
                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Status</th>
                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Date</th>
                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inquiries as $inq): ?>
                            <tr style="border-bottom: 1px solid #e1e7f0;">
                                <td style="padding: 0.75rem;"><?= htmlspecialchars($inq['name']); ?></td>
                                <td style="padding: 0.75rem;"><a href="mailto:<?= htmlspecialchars($inq['email']); ?>"><?= htmlspecialchars($inq['email']); ?></a></td>
                                <td style="padding: 0.75rem; max-width: 300px;">
                                    <?= htmlspecialchars(mb_strimwidth($inq['message'], 0, 80, '...')); ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <span class="status-badge <?= $inq['status']; ?>"><?= ucfirst(str_replace('_', ' ', $inq['status'])); ?></span>
                                </td>
                                <td style="padding: 0.75rem;"><?= date('M d, Y', strtotime($inq['created_at'])); ?></td>
                                <td style="padding: 0.75rem;">
                                    <a href="?id=<?= $inq['id']; ?>" class="btn btn-outline" style="padding: 0.35rem 0.75rem; font-size: 0.85rem;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<style>
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
}
.status-badge.new {
    background: #fff4e5;
    color: #856404;
}
.status-badge.in_progress {
    background: #e3f2fd;
    color: #1565c0;
}
.status-badge.resolved {
    background: #e3f8ef;
    color: #0d7a43;
}
.status-badge.closed {
    background: #f5f5f5;
    color: #616161;
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

