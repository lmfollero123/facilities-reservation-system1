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
require_once __DIR__ . '/../../../../config/security.php';

$pdo = db();
$base = base_path();
$pageTitle = 'Contact Information Management | LGU Facilities Reservation';

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update each field
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

// Fetch current contact information
$contactStmt = $pdo->query(
    'SELECT field_name, field_value FROM contact_info ORDER BY display_order ASC, id ASC'
);
$contactData = [];
while ($row = $contactStmt->fetch(PDO::FETCH_ASSOC)) {
    $contactData[$row['field_name']] = $row['field_value'];
}

// Set defaults if no data exists
$contactInfo = [
    'office_name' => $contactData['office_name'] ?? 'Barangay Culiat Facilities Management Office',
    'address' => $contactData['address'] ?? 'Barangay Culiat, Quezon City, Metro Manila',
    'phone' => $contactData['phone'] ?? '(02) 1234-5678',
    'mobile' => $contactData['mobile'] ?? '0912-345-6789',
    'email' => $contactData['email'] ?? 'facilities@barangayculiat.gov.ph',
    'office_hours' => $contactData['office_hours'] ?? 'Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM<br>Sunday: Closed',
];

ob_start();
?>

<div class="dashboard-header-section">
    <h1><i class="bi bi-telephone"></i> Contact Information Management</h1>
    <p>Manage Barangay Culiat contact information displayed on the public contact page</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : ($messageType === 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?= $messageType === 'error' ? 'exclamation-circle' : ($messageType === 'success' ? 'check-circle' : 'info-circle'); ?>"></i>
        <?= htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="booking-card">
    <div class="card-header-custom">
        <h2>Update Contact Information</h2>
        <small>Changes will be reflected on the public contact page</small>
    </div>
    
    <form method="POST" class="contact-info-form">
        <?= csrf_field(); ?>
        
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
            <a href="<?= $base; ?>/resources/views/pages/public/contact.php" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-eye"></i> Preview Contact Page
            </a>
        </div>
    </form>
</div>

<style>
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
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
?>
