<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/secure_documents.php';

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
        $acceptTerms = isset($_POST['accept_terms']) && $_POST['accept_terms'] === 'on';
        
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
        } elseif (!$acceptTerms) {
            $message = 'You must read and accept the Terms and Conditions and Data Privacy Policy to register.';
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
                            // Check if is_verified column exists (for backward compatibility)
                            $checkColumnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_verified'");
                            $hasVerifiedColumn = $checkColumnStmt->rowCount() > 0;
                            
                            // Valid ID document is now optional during registration
                            $validIdFile = $_FILES['doc_valid_id'] ?? null;
                            $hasValidId = $validIdFile && isset($validIdFile['tmp_name']) && $validIdFile['error'] === UPLOAD_ERR_OK && $validIdFile['size'] > 0;
                            
                            // Insert new user with active status (auto-activated) but unverified
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            
                            if ($hasVerifiedColumn) {
                                // New schema with is_verified column
                                $stmt = $pdo->prepare("INSERT INTO users (name, email, mobile, address, password_hash, role, status, is_verified) VALUES (?, ?, ?, ?, ?, 'Resident', 'active', ?)");
                                $stmt->execute([
                                    $name,
                                    $email,
                                    $mobile ?: null,
                                    $address ?: null,
                                    $passwordHash,
                                    $hasValidId ? 1 : 0  // Set verified to true only if ID is provided
                                ]);
                            } else {
                                // Old schema without is_verified column - use pending status (will need admin approval)
                                $stmt = $pdo->prepare("INSERT INTO users (name, email, mobile, address, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'Resident', 'pending')");
                                $stmt->execute([
                                    $name,
                                    $email,
                                    $mobile ?: null,
                                    $address ?: null,
                                    $passwordHash
                                ]);
                            }

                            $userId = (int)$pdo->lastInsertId();

                            // If valid ID was uploaded, store it
                            if ($hasValidId) {
                                require_once __DIR__ . '/../../../../config/secure_documents.php';
                                $result = saveDocumentToSecureStorage($validIdFile, $userId, 'valid_id');
                                
                                if ($result['success']) {
                                    // Store relative path (storage/private/documents/{userId}/{filename})
                                    $docStmt = $pdo->prepare("INSERT INTO user_documents (user_id, document_type, file_path, file_name, file_size) VALUES (?, ?, ?, ?, ?)");
                                    $docStmt->execute([
                                        $userId,
                                        'valid_id',
                                        $result['file_path'],
                                        basename($result['file_path']),
                                        (int)$validIdFile['size']
                                    ]);
                                    
                                    // Update verification status if document saved successfully (only if column exists)
                                    if ($hasVerifiedColumn) {
                                        $updateStmt = $pdo->prepare("UPDATE users SET is_verified = TRUE WHERE id = ?");
                                        $updateStmt->execute([$userId]);
                                    }
                                } else {
                                    $message = 'Registration successful, but failed to save your ID document. You can upload it later from your profile.';
                                    $messageType = 'warning';
                                }
                            }

                            if ($messageType !== 'error' && $messageType !== 'warning') {
                                logSecurityEvent('registration_success', "New user registered: $email", 'info');
                                $message = 'Registration successful! Your account is now active. To enable auto-approval features, please submit a valid ID from your profile.';
                                $messageType = 'success';
                            }
                        }
                    } catch (Exception $e) {
                        // Check if the error is due to missing is_verified column
                        $errorMsg = $e->getMessage();
                        if (stripos($errorMsg, 'is_verified') !== false || stripos($errorMsg, 'Unknown column') !== false) {
                            $message = 'Database migration required. Please contact the administrator or run the migration: database/migration_add_user_verification.sql';
                            $messageType = 'error';
                            logSecurityEvent('registration_error', "Database migration needed - is_verified column missing: " . $errorMsg, 'error');
                        } else {
                            $message = 'Registration failed: ' . htmlspecialchars($errorMsg);
                            $messageType = 'error';
                            logSecurityEvent('registration_error', "Database error during registration: " . $errorMsg, 'error');
                        }
                    }
                }
            }
        }
    }
}

ob_start();
?>
<div class="auth-container">
    <div class="auth-card auth-card-wide">
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
        
        <form method="POST" class="auth-form auth-form-horizontal" enctype="multipart/form-data">
            <?= csrf_field(); ?>
            <div class="auth-form-row">
                <label>
                    Full Name
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input name="name" type="text" placeholder="Juan Dela Cruz" required autofocus value="<?= isset($_POST['name']) ? e($_POST['name']) : ''; ?>" minlength="2">
                    </div>
                </label>
                
                <label>
                    Email Address
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input name="email" type="email" placeholder="official@lgu.gov.ph" required value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                    </div>
                </label>
            </div>

            <div class="auth-form-row">
                <label>
                    Address (must be in Barangay Culiat)
                    <div class="input-wrapper">
                        <span class="input-icon">🏠</span>
                        <input name="address" type="text" placeholder="e.g., Street, Barangay Culiat, Quezon City" required value="<?= isset($_POST['address']) ? e($_POST['address']) : ''; ?>">
                    </div>
                    <small style="color:#e0e7ff; font-size:0.85rem; display:block; margin-top:0.25rem; font-weight:500; opacity:0.95;">
                        Registration is limited to residents of Barangay Culiat.
                    </small>
                </label>
                
                <label>
                    Mobile Number
                    <div class="input-wrapper">
                        <span class="input-icon">📱</span>
                        <input name="mobile" type="tel" placeholder="+63 900 000 0000" value="<?= isset($_POST['mobile']) ? e($_POST['mobile']) : ''; ?>">
                    </div>
                </label>
            </div>
            
            <label>
                Password
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input name="password" type="password" placeholder="Create a strong password (min. 8 characters)" required minlength="<?= PASSWORD_MIN_LENGTH; ?>">
                </div>
                <small style="color:#e0e7ff; font-size:0.85rem; display:block; margin-top:0.25rem; font-weight:500; opacity:0.95;">
                    Must be at least <?= PASSWORD_MIN_LENGTH; ?> characters with uppercase, lowercase, and number.
                </small>
            </label>

            <div style="padding:0.75rem 0; border-top:1px solid rgba(255,255,255,0.2); margin-top:1rem;">
                <p style="margin:0 0 0.5rem; font-weight:600; color:#fff;">Upload Valid ID (Optional)</p>
                <small style="color:#e0e7ff; font-size:0.85rem; display:block; margin-bottom:0.75rem; font-weight:500; opacity:0.95; line-height:1.5;">
                    Your account will be activated immediately. To enable auto-approval features for facility bookings, you can upload a valid ID now or later from your profile. Accepted: PDF, JPG, PNG. Max 5MB. Any government-issued ID (Birth Certificate, Barangay ID, Resident ID, Driver's License, etc.) is acceptable.
                </small>

                <label style="color:#fff;">Valid ID
                    <input type="file" name="doc_valid_id" accept=".pdf,image/*" style="margin-top:0.5rem; padding:0.9rem 1rem; border:2px solid rgba(255,255,255,0.3); border-radius:8px; background:rgba(255,255,255,0.2); color:#fff; width:100%;">
                </label>
            </div>
            
            <div style="margin: 1.5rem 0; padding: 1rem; background: rgba(255, 255, 255, 0.1); border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2);">
                <label style="display: flex !important; flex-direction: row !important; align-items: flex-start; gap: 0.75rem; cursor: pointer; margin-bottom: 0 !important;">
                    <input type="checkbox" name="accept_terms" required style="width: 18px !important; height: 18px !important; min-width: 18px !important; flex-shrink: 0 !important; cursor: pointer; margin-top: 0.125rem; margin-right: 0 !important;">
                    <span style="color: #fff; font-size: 0.9rem; line-height: 1.6; flex: 1; margin-top: 0;">
                        I have read and agree to the <a href="#" id="termsLink" style="color: rgba(255, 255, 255, 0.9); text-decoration: underline;">Terms and Conditions</a> and <a href="#" id="privacyLink" style="color: rgba(255, 255, 255, 0.9); text-decoration: underline;">Data Privacy Policy</a> of Barangay Culiat Public Facilities Reservation System, including compliance with the Data Privacy Act of 2012 (Republic Act No. 10173).
                    </span>
                </label>
            </div>
            
            <button class="btn-primary" type="submit" id="submitBtn">Register</button>
        </form>
        
        <div class="auth-footer">
            Already registered? <a href="<?= base_path(); ?>/resources/views/pages/auth/login.php">Sign in here</a>
        </div>
    </div>
</div>

<!-- Terms and Conditions Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content" style="background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(15px); border-radius: 18px; border: none; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);">
            <div class="modal-header" style="border-bottom: 2px solid rgba(0, 0, 0, 0.1); padding: 1.5rem;">
                <h5 class="modal-title" id="termsModalLabel" style="color: #1e3a5f; font-weight: 700; font-size: 1.5rem;">
                    Terms and Conditions & Data Privacy Policy
                </h5>
            </div>
            <div class="modal-body" style="padding: 1.5rem; color: #333; max-height: 60vh; overflow-y: auto;">
                <div style="margin-bottom: 2rem;">
                    <h3 style="color: #1e3a5f; font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem;">Terms and Conditions</h3>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        These Terms and Conditions govern the use of the Barangay Culiat Public Facilities Reservation System. By accessing the portal, citizens, organizations, and partner agencies agree to observe the policies set by the Municipal Facilities Management Office.
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        Reservations are considered tentative until a confirmation notice is issued by the LGU. The LGU reserves the right to reassign, reschedule, or decline requests to ensure continuity of essential public services, disaster response operations, and official functions.
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        Users shall provide accurate contact information, submit complete supporting documents, and settle applicable fees within the prescribed period. Non-compliance may result in cancellation without prejudice to future bookings.
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        Any unauthorized commercial activity, political gathering without clearance, or activity that jeopardizes public safety is strictly prohibited. Damages to facilities shall be charged to the reserving party and may include administrative sanctions.
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        By proceeding, you acknowledge that you have read and understood these terms and agree to comply with all LGU directives related to facility utilization.
                    </p>
                </div>
                
                <div style="border-top: 2px solid rgba(0, 0, 0, 0.1); padding-top: 2rem;">
                    <h3 style="color: #1e3a5f; font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem;">Data Privacy Policy</h3>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        The Barangay Culiat Public Facilities Reservation System collects only the minimum personal data required to process reservations, namely contact information, organization affiliation, and event details. All records are handled in accordance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong> and the LGU's Data Governance Manual.
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        Information gathered through the portal is used solely for verification, scheduling, coordination, and communication of official advisories. Data shall not be shared with third parties unless authorized by law or necessary to protect public interest.
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        Users have the right to request access to their submitted data, rectify inaccuracies, and withdraw consent subject to existing retention policies. Security safeguards, including role-based access, audit logs, and encrypted storage, are implemented to prevent unauthorized disclosure.
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        For privacy concerns, you may reach the LGU Data Protection Officer through the Facilities Management Office or via the Contact page of this portal.
                    </p>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 2px solid rgba(0, 0, 0, 0.1); padding: 1.5rem;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="understandBtn" style="padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; background: #6c757d; border: none; color: #fff; cursor: pointer;">
                    I Understand
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Ensure modal is fully interactive */
#termsModal {
    z-index: 1055 !important;
}
#termsModal.show {
    display: block !important;
}
#termsModal .modal-dialog {
    z-index: 1056 !important;
    pointer-events: auto !important;
    margin: 1.75rem auto !important;
}
#termsModal .modal-content {
    pointer-events: auto !important;
    position: relative !important;
    z-index: 1057 !important;
}
#termsModal .modal-body {
    pointer-events: auto !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
}
#termsModal .modal-footer,
#termsModal .modal-header {
    pointer-events: auto !important;
}
#termsModal .btn {
    pointer-events: auto !important;
    cursor: pointer !important;
}
.sidebar-backdrop {
    display: none !important;
    pointer-events: none !important;
}
.modal-backdrop {
    z-index: 1050 !important;
    background-color: rgba(0, 0, 0, 0.5) !important;
    pointer-events: none !important; /* don't block clicks to modal */
}
</style>

<script>
// Auto-open modal on page load using Bootstrap's default behavior
// Only show once - check localStorage
window.addEventListener('load', function() {
    const modalElement = document.getElementById('termsModal');
    if (!modalElement) return;

    // Remove any sidebar backdrops injected by main.js on public pages
    document.querySelectorAll('.sidebar-backdrop').forEach(el => el.remove());

    // Ensure Bootstrap is loaded
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        console.error('Bootstrap Modal not available');
        return;
    }

    const termsModal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: false
    });

    // Helper to remove backdrops and unlock scroll
    const cleanupBackdrop = () => {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
    };

    // Check if user has already accepted terms
    const termsAcceptedKey = 'lgu_facilities_terms_accepted';
    const termsAccepted = localStorage.getItem(termsAcceptedKey);

    // Show modal only if terms haven't been accepted yet
    if (!termsAccepted) {
        setTimeout(function() {
            termsModal.show();

            const modalDialog = modalElement.querySelector('.modal-dialog');
            const modalContent = modalElement.querySelector('.modal-content');
            const modalBody = modalElement.querySelector('.modal-body');
            const understandBtn = document.getElementById('understandBtn');

            [modalDialog, modalContent, modalBody].forEach(el => {
                if (el) el.style.pointerEvents = 'auto';
            });
            if (understandBtn) {
                understandBtn.style.pointerEvents = 'auto';
                understandBtn.style.cursor = 'pointer';
                understandBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    // Store acceptance in localStorage
                    localStorage.setItem(termsAcceptedKey, 'true');
                    try {
                        termsModal.hide();
                    } catch (err) {
                        // ignore
                    }
                    cleanupBackdrop();
                });
            }
        }, 100);
    }

    // Open when clicking the links (always allow manual viewing)
    document.getElementById('termsLink')?.addEventListener('click', function(e) {
        e.preventDefault();
        termsModal.show();
    });
    document.getElementById('privacyLink')?.addEventListener('click', function(e) {
        e.preventDefault();
        termsModal.show();
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


