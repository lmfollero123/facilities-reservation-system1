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
        // Get name fields
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $middleName = sanitizeInput($_POST['middle_name'] ?? '');
        $lastName = sanitizeInput($_POST['last_name'] ?? '');
        $suffix = sanitizeInput($_POST['suffix'] ?? '');
        
        // Get address fields
        $street = sanitizeInput($_POST['street'] ?? '');
        $houseNumber = sanitizeInput($_POST['house_number'] ?? '');
        
        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        $mobile = sanitizeInput($_POST['mobile'] ?? '');
        $password = $_POST['password'] ?? '';
        $acceptTerms = isset($_POST['accept_terms']) && $_POST['accept_terms'] === 'on';
        
        // Build full name from parts (for backward compatibility with 'name' column)
        $nameParts = array_filter([$firstName, $middleName, $lastName]);
        $fullName = implode(' ', $nameParts);
        if (!empty($suffix)) {
            $fullName .= ' ' . $suffix;
        }
        
        // Build full address from parts (for backward compatibility with 'address' column)
        $addressParts = array_filter([$houseNumber, $street]);
        $fullAddress = implode(' ', $addressParts);
        if (!empty($fullAddress)) {
            $fullAddress .= ', Barangay Culiat, Quezon City';
        }
        
        // Validate inputs
        if (empty($firstName) || strlen($firstName) < 2) {
            $message = 'Please enter a valid first name (at least 2 characters).';
            $messageType = 'error';
        } elseif (empty($lastName) || strlen($lastName) < 2) {
            $message = 'Please enter a valid last name (at least 2 characters).';
            $messageType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } elseif (empty($street)) {
            $message = 'Please select a street.';
            $messageType = 'error';
        } elseif (empty($houseNumber)) {
            $message = 'Please enter your house number.';
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
                            
                            // Check if new name/address columns exist
                            $checkNameColStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'first_name'");
                            $hasNameColumns = $checkNameColStmt->rowCount() > 0;
                            
                            // Valid ID document is now optional during registration
                            $validIdFile = $_FILES['doc_valid_id'] ?? null;
                            $hasValidId = $validIdFile && isset($validIdFile['tmp_name']) && $validIdFile['error'] === UPLOAD_ERR_OK && $validIdFile['size'] > 0;
                            
                            // Insert new user with active status (auto-activated) but unverified
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            
                            if ($hasVerifiedColumn && $hasNameColumns) {
                                // New schema with is_verified and name/address columns
                                $stmt = $pdo->prepare("INSERT INTO users (name, first_name, middle_name, last_name, suffix, email, mobile, address, street, house_number, password_hash, role, status, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Resident', 'active', ?)");
                                $stmt->execute([
                                    $fullName,
                                    $firstName,
                                    $middleName ?: null,
                                    $lastName,
                                    $suffix ?: null,
                                    $email,
                                    $mobile ?: null,
                                    $fullAddress,
                                    $street,
                                    $houseNumber,
                                    $passwordHash,
                                    $hasValidId ? 1 : 0  // Set verified to true only if ID is provided
                                ]);
                            } elseif ($hasVerifiedColumn) {
                                // Schema with is_verified but no name/address columns (backward compatibility)
                                $stmt = $pdo->prepare("INSERT INTO users (name, email, mobile, address, password_hash, role, status, is_verified) VALUES (?, ?, ?, ?, ?, 'Resident', 'active', ?)");
                                $stmt->execute([
                                    $fullName,
                                    $email,
                                    $mobile ?: null,
                                    $fullAddress,
                                    $passwordHash,
                                    $hasValidId ? 1 : 0
                                ]);
                            } elseif ($hasNameColumns) {
                                // Schema with name/address columns but no is_verified
                                $stmt = $pdo->prepare("INSERT INTO users (name, first_name, middle_name, last_name, suffix, email, mobile, address, street, house_number, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Resident', 'pending')");
                                $stmt->execute([
                                    $fullName,
                                    $firstName,
                                    $middleName ?: null,
                                    $lastName,
                                    $suffix ?: null,
                                    $email,
                                    $mobile ?: null,
                                    $fullAddress,
                                    $street,
                                    $houseNumber,
                                    $passwordHash
                                ]);
                            } else {
                                // Old schema without is_verified or name/address columns (backward compatibility)
                                $stmt = $pdo->prepare("INSERT INTO users (name, email, mobile, address, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'Resident', 'pending')");
                                $stmt->execute([
                                    $fullName,
                                    $email,
                                    $mobile ?: null,
                                    $fullAddress,
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
                    First Name *
                    <div class="input-wrapper">
                        <input name="first_name" type="text" placeholder="Juan" required autofocus value="<?= isset($_POST['first_name']) ? e($_POST['first_name']) : ''; ?>" minlength="2">
                    </div>
                </label>
                
                <label>
                    Middle Name
                    <div class="input-wrapper">
                        <input name="middle_name" type="text" placeholder="Santos" value="<?= isset($_POST['middle_name']) ? e($_POST['middle_name']) : ''; ?>">
                    </div>
                </label>
            </div>

            <div class="auth-form-row">
                <label>
                    Last Name *
                    <div class="input-wrapper">
                        <input name="last_name" type="text" placeholder="Dela Cruz" required value="<?= isset($_POST['last_name']) ? e($_POST['last_name']) : ''; ?>" minlength="2">
                    </div>
                </label>
                
                <label>
                    Suffix
                    <div class="input-wrapper">
                        <input name="suffix" type="text" placeholder="Jr., Sr., III" value="<?= isset($_POST['suffix']) ? e($_POST['suffix']) : ''; ?>" maxlength="10">
                    </div>
                </label>
            </div>

            <div class="auth-form-row">
                <label>
                    Email Address *
                    <div class="input-wrapper">
                        <input name="email" type="email" placeholder="official@lgu.gov.ph" required value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                    </div>
                </label>
                
                <label>
                    Mobile Number
                    <div class="input-wrapper">
                        <input name="mobile" type="tel" placeholder="+63 900 000 0000" value="<?= isset($_POST['mobile']) ? e($_POST['mobile']) : ''; ?>">
                    </div>
                </label>
            </div>

            <div class="auth-form-row">
                <label>
                    Street *
                    <div class="input-wrapper">
                        <select name="street" required style="width: 100%; padding: 0.9rem 1rem; border: 2px solid rgba(255,255,255,0.3); border-radius: 8px; background: rgba(255,255,255,0.2); color: #1b1b1f; font-size: 1rem;">
                            <option value="">-- Select Street --</option>
                            <option value="A. Limqueco Street" <?= (isset($_POST['street']) && $_POST['street'] === 'A. Limqueco Street') ? 'selected' : ''; ?>>A. Limqueco Street</option>
                            <option value="Adelfa Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Adelfa Street') ? 'selected' : ''; ?>>Adelfa Street</option>
                            <option value="Admirable Lane" <?= (isset($_POST['street']) && $_POST['street'] === 'Admirable Lane') ? 'selected' : ''; ?>>Admirable Lane</option>
                            <option value="Aldrin Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Aldrin Street') ? 'selected' : ''; ?>>Aldrin Street</option>
                            <option value="Allan Bean Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Allan Bean Street') ? 'selected' : ''; ?>>Allan Bean Street</option>
                            <option value="Anahaw Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Anahaw Street') ? 'selected' : ''; ?>>Anahaw Street</option>
                            <option value="Andrew Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Andrew Street') ? 'selected' : ''; ?>>Andrew Street</option>
                            <option value="Aquino Marquez Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Aquino Marquez Street') ? 'selected' : ''; ?>>Aquino Marquez Street</option>
                            <option value="Arboretum Road" <?= (isset($_POST['street']) && $_POST['street'] === 'Arboretum Road') ? 'selected' : ''; ?>>Arboretum Road</option>
                            <option value="Armstrong Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Armstrong Street') ? 'selected' : ''; ?>>Armstrong Street</option>
                            <option value="Borman Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Borman Street') ? 'selected' : ''; ?>>Borman Street</option>
                            <option value="Casanova Drive" <?= (isset($_POST['street']) && $_POST['street'] === 'Casanova Drive') ? 'selected' : ''; ?>>Casanova Drive</option>
                            <option value="Casanova Drive Extension" <?= (isset($_POST['street']) && $_POST['street'] === 'Casanova Drive Extension') ? 'selected' : ''; ?>>Casanova Drive Extension</option>
                            <option value="Cebu Street Extension" <?= (isset($_POST['street']) && $_POST['street'] === 'Cebu Street Extension') ? 'selected' : ''; ?>>Cebu Street Extension</option>
                            <option value="Cenacle Drive" <?= (isset($_POST['street']) && $_POST['street'] === 'Cenacle Drive') ? 'selected' : ''; ?>>Cenacle Drive</option>
                            <option value="Central Avenue" <?= (isset($_POST['street']) && $_POST['street'] === 'Central Avenue') ? 'selected' : ''; ?>>Central Avenue</option>
                            <option value="Charity Lane" <?= (isset($_POST['street']) && $_POST['street'] === 'Charity Lane') ? 'selected' : ''; ?>>Charity Lane</option>
                            <option value="Charles Conrad Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Charles Conrad Street') ? 'selected' : ''; ?>>Charles Conrad Street</option>
                            <option value="Charlie Conrad Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Charlie Conrad Street') ? 'selected' : ''; ?>>Charlie Conrad Street</option>
                            <option value="Collins Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Collins Street') ? 'selected' : ''; ?>>Collins Street</option>
                            <option value="Commonwealth Avenue" <?= (isset($_POST['street']) && $_POST['street'] === 'Commonwealth Avenue') ? 'selected' : ''; ?>>Commonwealth Avenue</option>
                            <option value="Demetria Reynaldo Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Demetria Reynaldo Street') ? 'selected' : ''; ?>>Demetria Reynaldo Street</option>
                            <option value="Diamond Lane" <?= (isset($_POST['street']) && $_POST['street'] === 'Diamond Lane') ? 'selected' : ''; ?>>Diamond Lane</option>
                            <option value="Emerald Lane" <?= (isset($_POST['street']) && $_POST['street'] === 'Emerald Lane') ? 'selected' : ''; ?>>Emerald Lane</option>
                            <option value="Fisheries Street" <?= (isset($_POST['street']) && $_POST['street'] === 'Fisheries Street') ? 'selected' : ''; ?>>Fisheries Street</option>
                            <option value="Forestry Street Extension" <?= (isset($_POST['street']) && $_POST['street'] === 'Forestry Street Extension') ? 'selected' : ''; ?>>Forestry Street Extension</option>
                            <option value="Other" <?= (isset($_POST['street']) && $_POST['street'] === 'Other') ? 'selected' : ''; ?>>Other (please specify in house number)</option>
                        </select>
                    </div>
                    <small style="color:#4c5b7c; font-size:0.85rem; display:block; margin-top:0.25rem; font-weight:500;">
                        Registration is limited to residents of Barangay Culiat, Quezon City.
                    </small>
                </label>
                
                <label>
                    House Number *
                    <div class="input-wrapper">
                        <input name="house_number" type="text" placeholder="123" required value="<?= isset($_POST['house_number']) ? e($_POST['house_number']) : ''; ?>">
                    </div>
                </label>
            </div>
            
            <label>
                Password
                <div class="input-wrapper">
                    <input name="password" type="password" placeholder="Create a strong password (min. 8 characters)" required minlength="<?= PASSWORD_MIN_LENGTH; ?>">
                </div>
                <small style="color:#4c5b7c; font-size:0.85rem; display:block; margin-top:0.25rem; font-weight:500;">
                    Must be at least <?= PASSWORD_MIN_LENGTH; ?> characters with uppercase, lowercase, and number.
                </small>
            </label>

            <div style="padding:0.75rem 0; border-top:1px solid rgba(255,255,255,0.2); margin-top:1rem;">
                <p style="margin:0 0 0.5rem; font-weight:600; color:#1b1b1f;">Upload Valid ID (Optional)</p>
                <small style="color:#4c5b7c; font-size:0.85rem; display:block; margin-bottom:0.75rem; font-weight:500; line-height:1.5;">
                    Your account will be activated immediately. To enable auto-approval features for facility bookings, you can upload a valid ID now or later from your profile. Accepted: PDF, JPG, PNG. Max 5MB. Any government-issued ID (Birth Certificate, Barangay ID, Resident ID, Driver's License, etc.) is acceptable.
                </small>

                <label style="color:#fff;">Valid ID
                    <input type="file" name="doc_valid_id" accept=".pdf,image/*" style="margin-top:0.5rem; padding:0.9rem 1rem; border:2px solid rgba(255,255,255,0.3); border-radius:8px; background:rgba(255,255,255,0.2); color:#fff; width:100%;">
                </label>
            </div>
            
            <div style="margin: 1.5rem 0; padding: 1rem; background: rgba(255, 255, 255, 0.1); border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2);">
                <label style="display: flex !important; flex-direction: row !important; align-items: flex-start; gap: 0.75rem; cursor: pointer; margin-bottom: 0 !important;">
                    <input type="checkbox" name="accept_terms" required style="width: 18px !important; height: 18px !important; min-width: 18px !important; flex-shrink: 0 !important; cursor: pointer; margin-top: 0.125rem; margin-right: 0 !important;">
                    <span style="color: #1b1b1f; font-size: 0.9rem; line-height: 1.6; flex: 1; margin-top: 0;">
                        I have read and agree to the <a href="#" id="termsLink" style="color: #2864ef; text-decoration: underline;">Terms and Conditions</a> and <a href="#" id="privacyLink" style="color: #2864ef; text-decoration: underline;">Data Privacy Policy</a> of Barangay Culiat Public Facilities Reservation System, including compliance with the Data Privacy Act of 2012 (Republic Act No. 10173).
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
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg" style="max-width: 700px; margin: 0; max-height: 65vh; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);">
        <div class="modal-content" style="background: #ffffff; border-radius: 18px; border: none; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3); max-height: 65vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="border-bottom: 2px solid rgba(0, 0, 0, 0.1); padding: 1.5rem; flex-shrink: 0;">
                <h5 class="modal-title" id="termsModalLabel" style="color: #1e3a5f; font-weight: 700; font-size: 1.5rem;">
                    Terms and Conditions & Data Privacy Policy
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 1.5rem; color: #333; overflow-y: auto; flex: 1; min-height: 0;">
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
            <div class="modal-footer" style="border-top: 2px solid rgba(0, 0, 0, 0.1); padding: 1.5rem; flex-shrink: 0;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="understandBtn" style="padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; background: #6c757d; border: none; color: #fff; cursor: pointer;">
                    I Understand
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Terms modal - no backdrop, fully interactive */
#termsModal {
    z-index: 1055 !important;
}

/* Hide backdrops when terms modal is showing (Bootstrap shouldn't create them with backdrop: false, but just in case) */
body:has(#termsModal.show) .modal-backdrop {
    display: none !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

/* Prevent body scroll lock */
body:has(#termsModal.show),
body.modal-open {
    overflow: auto !important;
    padding-right: 0 !important;
}

#termsModal.show {
    display: block !important;
}

#termsModal .modal-dialog {
    pointer-events: auto !important;
    margin: 0 !important;
    max-height: 65vh !important;
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    z-index: 1056 !important;
}

#termsModal .modal-content {
    pointer-events: auto !important;
    position: relative !important;
}

#termsModal .modal-body {
    pointer-events: auto !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
}

#termsModal .btn {
    cursor: pointer !important;
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
        backdrop: false,  // No backdrop
        keyboard: true    // Allow ESC to close
    });

    // Helper to remove backdrops and unlock scroll
    const cleanupBackdrop = () => {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    };

    // Check if user has already accepted terms
    const termsAcceptedKey = 'lgu_facilities_terms_accepted';
    const termsAccepted = localStorage.getItem(termsAcceptedKey);

    // Show modal only if terms haven't been accepted yet
    if (!termsAccepted) {
        setTimeout(function() {
            // Remove any backdrops that Bootstrap might have created
            cleanupBackdrop();
            
            // Show the modal
            try {
                termsModal.show();
            } catch (err) {
                console.error('Error showing modal:', err);
            }
            
            // Clean up backdrop after modal is shown
            setTimeout(cleanupBackdrop, 100);
            
            const understandBtn = document.getElementById('understandBtn');
            if (understandBtn) {
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
            
            // Also allow closing with ESC or clicking outside (since no backdrop)
            modalElement.addEventListener('hidden.bs.modal', cleanupBackdrop);
        }, 100);
    }

    // Open when clicking the links (always allow manual viewing)
    document.getElementById('termsLink')?.addEventListener('click', function(e) {
        e.preventDefault();
        cleanupBackdrop();
        termsModal.show();
        setTimeout(cleanupBackdrop, 100);
    });
    document.getElementById('privacyLink')?.addEventListener('click', function(e) {
        e.preventDefault();
        cleanupBackdrop();
        termsModal.show();
        setTimeout(cleanupBackdrop, 100);
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


