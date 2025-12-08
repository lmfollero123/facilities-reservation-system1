<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';

$pageTitle = 'Register | LGU Facilities Reservation';
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $messageType = 'error';
        logSecurityEvent('csrf_validation_failed', 'Registration form', 'warning');
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        $mobile = sanitizeInput($_POST['mobile'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Validate inputs
        if (empty($name) || strlen($name) < 2) {
            $message = 'Please enter a valid name (at least 2 characters).';
            $messageType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } elseif (stripos($address, 'culiat') === false) {
            $message = 'Registration is limited to Barangay Culiat residents. Please include Barangay Culiat in your address.';
            $messageType = 'error';
        } else {
            // Check rate limiting (by IP)
            $clientIP = getClientIP();
            if (!checkRegisterRateLimit($clientIP)) {
                $message = 'Too many registration attempts. Please try again in 1 hour.';
                $messageType = 'error';
                logSecurityEvent('rate_limit_exceeded', "Registration attempts exceeded from IP: $clientIP", 'warning');
            } else {
                // Validate password
                $passwordErrors = validatePassword($password);
                if (!empty($passwordErrors)) {
                    $message = implode(' ', $passwordErrors);
                    $messageType = 'error';
                } else {
                    try {
                        $pdo = db();
                        
                        // Check if email already exists
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $message = 'This email is already registered.';
                            $messageType = 'error';
                            logSecurityEvent('registration_attempt_existing_email', "Registration attempt with existing email: $email", 'info');
                        } else {
                            // Validate at least one document
                            $docFields = [
                                'birth_certificate' => $_FILES['doc_birth_certificate'] ?? null,
                                'valid_id' => $_FILES['doc_valid_id'] ?? null,
                                'brgy_id' => $_FILES['doc_brgy_id'] ?? null,
                                'resident_id' => $_FILES['doc_resident_id'] ?? null,
                            ];

                            $uploads = [];
                            foreach ($docFields as $type => $file) {
                                if ($file && isset($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
                                    $uploads[$type] = $file;
                                }
                            }

                            if (empty($uploads)) {
                                $message = 'Please upload at least one supporting document (Birth Certificate, Valid ID, Barangay ID, or Resident ID).';
                                $messageType = 'error';
                            } else {
                                // Insert new user with pending status
                                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("INSERT INTO users (name, email, mobile, address, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'Resident', 'pending')");
                                $stmt->execute([
                                    $name,
                                    $email,
                                    $mobile ?: null,
                                    $address ?: null,
                                    $passwordHash
                                ]);

                                $userId = (int)$pdo->lastInsertId();

                                // Store documents
                                // Save documents under public/uploads/documents/{userId}
                                $docDir = base_path(true) . '/public/uploads/documents/' . $userId;
                                if (!is_dir($docDir)) {
                                    mkdir($docDir, 0775, true);
                                }

                                foreach ($uploads as $type => $file) {
                                    $errors = validateFileUpload($file, ['image/jpeg','image/png','image/gif','image/webp','application/pdf']);
                                    if (!empty($errors)) {
                                        $message = implode(' ', $errors);
                                        $messageType = 'error';
                                        break;
                                    }

                                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                                    $filename = $safeName . '_' . time() . '.' . $ext;
                                    $destPath = $docDir . '/' . $filename;

                                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                                        $message = 'Failed to save uploaded document.';
                                        $messageType = 'error';
                                        break;
                                    }

                                    $relPath = base_path() . '/public/uploads/documents/' . $userId . '/' . $filename;
                                    $docStmt = $pdo->prepare("INSERT INTO user_documents (user_id, document_type, file_path, file_name, file_size) VALUES (?, ?, ?, ?, ?)");
                                    $docStmt->execute([
                                        $userId,
                                        $type,
                                        $relPath,
                                        $filename,
                                        (int)$file['size']
                                    ]);
                                }

                                if ($messageType !== 'error') {
                                    logSecurityEvent('registration_success', "New user registered: $email", 'info');
                                    $message = 'Registration successful! Your account is pending document verification.';
                                    $messageType = 'success';
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $message = 'Registration failed. Please try again.';
                        $messageType = 'error';
                        logSecurityEvent('registration_error', "Database error during registration: " . $e->getMessage(), 'error');
                    }
                }
            }
        }
    }
}

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">📝</div>
            <h1>Create Account</h1>
            <p>Register for facility reservations</p>
        </div>
        
        <?php if ($message): ?>
            <div style="background: <?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>; color: <?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="auth-form" enctype="multipart/form-data">
            <?= csrf_field(); ?>
            <label>
                Full Name
                <div class="input-wrapper">
                    <span class="input-icon">👤</span>
                    <input name="name" type="text" placeholder="Juan Dela Cruz" required autofocus value="<?= isset($_POST['name']) ? e($_POST['name']) : ''; ?>" minlength="2">
                </div>
            </label>

            <label>
                Address (must be in Barangay Culiat)
                <div class="input-wrapper">
                    <span class="input-icon">🏠</span>
                    <input name="address" type="text" placeholder="e.g., Street, Barangay Culiat, Quezon City" required value="<?= isset($_POST['address']) ? e($_POST['address']) : ''; ?>">
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Registration is limited to residents of Barangay Culiat.
                </small>
            </label>
            
            <label>
                Email Address
                <div class="input-wrapper">
                    <span class="input-icon">✉️</span>
                    <input name="email" type="email" placeholder="official@lgu.gov.ph" required value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                </div>
            </label>
            
            <label>
                Mobile Number
                <div class="input-wrapper">
                    <span class="input-icon">📱</span>
                    <input name="mobile" type="tel" placeholder="+63 900 000 0000" value="<?= isset($_POST['mobile']) ? e($_POST['mobile']) : ''; ?>">
                </div>
            </label>
            
            <label>
                Password
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input name="password" type="password" placeholder="Create a strong password (min. 8 characters)" required minlength="<?= PASSWORD_MIN_LENGTH; ?>">
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Must be at least <?= PASSWORD_MIN_LENGTH; ?> characters with uppercase, lowercase, and number.
                </small>
            </label>

            <div style="padding:0.75rem 0; border-top:1px solid #e8ecf5; margin-top:1rem;">
                <p style="margin:0 0 0.5rem; font-weight:600;">Upload supporting documents (at least one)</p>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-bottom:0.75rem;">
                    Accepted: PDF, JPG, PNG. Max 5MB each.
                </small>

                <label>Birth Certificate (optional)
                    <input type="file" name="doc_birth_certificate" accept=".pdf,image/*">
                </label>
                <label>Valid ID (optional)
                    <input type="file" name="doc_valid_id" accept=".pdf,image/*">
                </label>
                <label>Barangay ID (optional)
                    <input type="file" name="doc_brgy_id" accept=".pdf,image/*">
                </label>
                <label>Resident ID (optional)
                    <input type="file" name="doc_resident_id" accept=".pdf,image/*">
                </label>
            </div>
            
            <button class="btn-primary" type="submit">Register</button>
        </form>
        
        <div class="auth-footer">
            Already registered? <a href="<?= base_path(); ?>/resources/views/pages/auth/login.php">Sign in here</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


