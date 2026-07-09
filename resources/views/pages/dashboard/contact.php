<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/security.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'communications')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pdo = db();
$base = base_path();
$pageTitle = 'Contact Management | LGU Facilities Reservation';

$message = '';
$messageType = '';
$success = '';
$error = '';

// Determine active tab
$activeTab = $_GET['tab'] ?? 'information';
$activeTab = in_array($activeTab, ['information', 'inquiries']) ? $activeTab : 'information';

// Handle Contact Information form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact_info'])) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            $fields = ['office_name', 'address', 'phone', 'mobile', 'email', 'office_hours'];
            $updatedFields = [];
            
            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $value = sanitizeInput(trim($_POST[$field]), 'string');
                    
                    $stmt = $pdo->prepare(
                        'INSERT INTO contact_info (field_name, field_value, display_order) 
                         VALUES (:field, :value, (SELECT COALESCE(MAX(display_order), 0) + 1 FROM (SELECT display_order FROM contact_info) AS tmp))
                         ON DUPLICATE KEY UPDATE field_value = :value2, updated_at = CURRENT_TIMESTAMP'
                    );
                    $stmt->execute([
                        'field' => $field,
                        'value' => $value,
                        'value2' => $value
                    ]);
                    
                    $updatedFields[] = $field;
                }
            }
            
            $pdo->commit();
            
            if (!empty($updatedFields)) {
                logAudit('Updated contact information', 'Contact Info', 'Fields updated: ' . implode(', ', $updatedFields));
                $message = 'Contact information updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'No changes were made.';
                $messageType = 'info';
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Contact info update failed: ' . $e->getMessage());
            $message = 'Failed to update contact information. Please try again.';
            $messageType = 'error';
        }
    }
}

// Handle Inquiry status update
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

// Fetch current contact information
$contactStmt = $pdo->query(
    'SELECT field_name, field_value FROM contact_info ORDER BY display_order ASC, id ASC'
);
$contactData = [];
while ($row = $contactStmt->fetch(PDO::FETCH_ASSOC)) {
    $contactData[$row['field_name']] = $row['field_value'];
}

$contactInfo = [
    'office_name' => $contactData['office_name'] ?? 'Barangay Culiat Facilities Management Office',
    'address' => $contactData['address'] ?? 'Barangay Culiat, Quezon City, Metro Manila',
    'phone' => $contactData['phone'] ?? '(02) 1234-5678',
    'mobile' => $contactData['mobile'] ?? '0912-345-6789',
    'email' => $contactData['email'] ?? 'facilities@barangayculiat.gov.ph',
    'office_hours' => $contactData['office_hours'] ?? 'Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM<br>Sunday: Closed',
];

// Get inquiries filter
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
        <span>Admin</span><span class="sep">/</span><span>Contact Management</span>
    </div>
    <?= frs_page_title('Contact Management', 'Manage contact information and inquiries from the public.'); ?>
</div>

<!-- Tabs -->
<div class="tabs-container">
    <button class="tab-btn <?= $activeTab === 'information' ? 'active' : ''; ?>" onclick="switchTab('information')">
        <i class="bi bi-info-circle"></i> Contact Information
    </button>
    <button class="tab-btn <?= $activeTab === 'inquiries' ? 'active' : ''; ?>" onclick="switchTab('inquiries')">
        <i class="bi bi-envelope"></i> Contact Inquiries
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : ($messageType === 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?= $messageType === 'error' ? 'exclamation-circle' : ($messageType === 'success' ? 'check-circle' : 'info-circle'); ?>"></i>
        <?= htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i>
        <?= htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <?= htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Contact Information Tab -->
<div class="tab-content <?= $activeTab === 'information' ? 'active' : ''; ?>" id="tab-information">
    <div class="booking-card">
        <div class="card-header-custom">
            <?= frs_heading_with_tip('Update Contact Information', 'Office name, hours, phone, and email shown on the public Contact page.'); ?>
        </div>
        
        <form method="POST" class="contact-info-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="update_contact_info" value="1">
            
            <div class="form-group">
                <label for="office_name" class="form-label">Office Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="office_name" name="office_name" required value="<?= htmlspecialchars($contactInfo['office_name']); ?>" placeholder="Barangay Culiat Facilities Management Office">
            </div>
            
            <div class="form-group">
                <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                <textarea class="form-control" id="address" name="address" rows="2" required placeholder="Barangay Culiat, Quezon City, Metro Manila"><?= htmlspecialchars($contactInfo['address']); ?></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="phone" class="form-label">Phone Number</label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($contactInfo['phone']); ?>" placeholder="(02) 1234-5678">
                </div>
                
                <div class="form-group col-md-6">
                    <label for="mobile" class="form-label">Mobile Number</label>
                    <input type="text" class="form-control" id="mobile" name="mobile" value="<?= htmlspecialchars($contactInfo['mobile']); ?>" placeholder="0912-345-6789">
                </div>
            </div>
            
            <div class="form-group">
                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="email" name="email" required value="<?= htmlspecialchars($contactInfo['email']); ?>" placeholder="facilities@barangayculiat.gov.ph">
            </div>
            
            <div class="form-group">
                <label for="office_hours" class="form-label">Office Hours</label>
                <textarea class="form-control" id="office_hours" name="office_hours" rows="4" placeholder="Monday - Friday: 8:00 AM - 5:00 PM&#10;Saturday: 8:00 AM - 12:00 PM&#10;Sunday: Closed"><?= htmlspecialchars(strip_tags($contactInfo['office_hours'])); ?></textarea>
                <small class="form-text text-muted">You can use line breaks. HTML &lt;br&gt; tags will be preserved for formatting.</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Changes
                </button>
                <a href="<?= $base; ?>/contact" target="_blank" class="btn btn-outline-secondary">
                    <i class="bi bi-eye"></i> Preview Contact Page
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Contact Inquiries Tab -->
<div class="tab-content <?= $activeTab === 'inquiries' ? 'active' : ''; ?>" id="tab-inquiries">
    <?php if ($inquiry): ?>
        <!-- Detail View -->
        <div class="card-elevated">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="color: #1b1b1f;">Inquiry Details</h2>
                <a href="?tab=inquiries" class="btn btn-outline">← Back to List</a>
            </div>
            
            <div style="background: #f5f7fd; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <strong style="color: #6b7897; font-size: 0.85rem;">Name</strong>
                        <p style="margin: 0.25rem 0 0; font-weight: 600; color: #1b1b1f;"><?= htmlspecialchars($inquiry['name']); ?></p>
                    </div>
                    <div>
                        <strong style="color: #6b7897; font-size: 0.85rem;">Email</strong>
                        <p style="margin: 0.25rem 0 0; color: #1b1b1f;"><a href="mailto:<?= htmlspecialchars($inquiry['email']); ?>" style="color: #285ccd; text-decoration: none;"><?= htmlspecialchars($inquiry['email']); ?></a></p>
                    </div>
                    <?php if ($inquiry['organization']): ?>
                    <div>
                        <strong style="color: #6b7897; font-size: 0.85rem;">Organization</strong>
                        <p style="margin: 0.25rem 0 0; color: #1b1b1f;"><?= htmlspecialchars($inquiry['organization']); ?></p>
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
                        <p style="margin: 0.25rem 0 0; color: #1b1b1f;"><?= date('M d, Y g:i A', strtotime($inquiry['created_at'])); ?></p>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <strong style="color: #6b7897; font-size: 0.85rem;">Message</strong>
                    <p style="margin: 0.75rem 0 0; white-space: pre-wrap; line-height: 1.7; color: #1b1b1f;"><?= htmlspecialchars($inquiry['message']); ?></p>
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
                    <a href="?tab=inquiries&status=all" class="btn <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline'; ?>">All</a>
                    <a href="?tab=inquiries&status=new" class="btn <?= $statusFilter === 'new' ? 'btn-primary' : 'btn-outline'; ?>">New</a>
                    <a href="?tab=inquiries&status=in_progress" class="btn <?= $statusFilter === 'in_progress' ? 'btn-primary' : 'btn-outline'; ?>">In Progress</a>
                    <a href="?tab=inquiries&status=resolved" class="btn <?= $statusFilter === 'resolved' ? 'btn-primary' : 'btn-outline'; ?>">Resolved</a>
                    <a href="?tab=inquiries&status=closed" class="btn <?= $statusFilter === 'closed' ? 'btn-primary' : 'btn-outline'; ?>">Closed</a>
                </div>
            </div>
            
            <?php if (empty($inquiries)): ?>
                <p style="text-align: center; color: #6b7897; padding: 2rem;">No inquiries found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f7fd; border-bottom: 2px solid #e1e7f0;">
                                <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #1b1b1f;">Name</th>
                                <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #1b1b1f;">Email</th>
                                <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #1b1b1f;">Message Preview</th>
                                <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #1b1b1f;">Status</th>
                                <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #1b1b1f;">Date</th>
                                <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #1b1b1f;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inquiries as $inq): ?>
                                <tr style="border-bottom: 1px solid #e1e7f0;">
                                    <td style="padding: 0.75rem; color: #1b1b1f;"><?= htmlspecialchars($inq['name']); ?></td>
                                    <td style="padding: 0.75rem; color: #1b1b1f;"><a href="mailto:<?= htmlspecialchars($inq['email']); ?>" style="color: #285ccd; text-decoration: none;"><?= htmlspecialchars($inq['email']); ?></a></td>
                                    <td style="padding: 0.75rem; max-width: 300px; color: #1b1b1f;">
                                        <?= htmlspecialchars(mb_strimwidth($inq['message'], 0, 80, '...')); ?>
                                    </td>
                                    <td style="padding: 0.75rem; color: #1b1b1f;">
                                        <span class="status-badge <?= $inq['status']; ?>"><?= ucfirst(str_replace('_', ' ', $inq['status'])); ?></span>
                                    </td>
                                    <td style="padding: 0.75rem; color: #1b1b1f;"><?= date('M d, Y', strtotime($inq['created_at'])); ?></td>
                                    <td style="padding: 0.75rem; color: #1b1b1f;">
                                        <a href="?tab=inquiries&id=<?= $inq['id']; ?>" class="btn btn-outline" style="padding: 0.35rem 0.75rem; font-size: 0.85rem;">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.tabs-container {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 0;
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    background: transparent;
    font-size: 0.95rem;
    font-weight: 600;
    color: #6b7280;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.tab-btn:hover {
    color: #285ccd;
    background: #f9fafb;
}

.tab-btn.active {
    color: #285ccd;
    border-bottom-color: #285ccd;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
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

.contact-info-form {
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

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

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

.card-elevated .booking-form label,
.card-elevated .booking-form label span {
    color: #285ccd !important;
}

.card-elevated .booking-form select,
.card-elevated .booking-form textarea,
.card-elevated .booking-form input {
    color: #1b1b1f !important;
}

.card-elevated .booking-form select option {
    color: #1b1b1f;
}

.card-elevated table th,
.card-elevated table td {
    color: #1b1b1f !important;
}

.card-elevated table a {
    color: #285ccd !important;
}

.card-elevated table a:hover {
    color: #285ccd !important;
    text-decoration: underline;
}

@media (max-width: 480px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions button,
    .form-actions a {
        width: 100%;
    }
    
    .tabs-container {
        flex-direction: column;
    }
    
    .tab-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Add active class to clicked button
    event.target.closest('.tab-btn').classList.add('active');
    
    // Update URL without reloading
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    url.searchParams.delete('id');
    url.searchParams.delete('status');
    window.history.pushState({}, '', url);
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
?>
