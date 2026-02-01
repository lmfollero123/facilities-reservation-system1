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
                                    0  // Always start as unverified - requires admin/staff approval
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
                                    0  // Always start as unverified - requires admin/staff approval
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
                                    
                                    // Note: Verification status must be manually approved by admin/staff
                                    // We do NOT auto-verify users even if they upload an ID during registration
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
            Already registered? <a href="<?= base_path(); ?>/login">Sign in here</a>
        </div>
    </div>
</div>

<!-- Terms and Conditions Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg terms-modal-dialog terms-modal-content">
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
                    
                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">1. Data Controller</h4>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        The Barangay Culiat Public Facilities Reservation System is operated by the Barangay Culiat Facilities Management Office, Quezon City. We are committed to protecting your personal data in accordance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong> and its Implementing Rules and Regulations.
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">2. Data Protection Officer</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        For privacy concerns, you may contact our Data Protection Officer:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li>Email: dpo@barangayculiat.gov.ph</li>
                        <li>Office: Barangay Culiat Facilities Management Office</li>
                        <li>Contact: Via the Contact page of this portal</li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">3. What Data We Collect</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        We collect only the minimum personal data required to process facility reservations:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong>Identity Information</strong>: Name, valid ID (optional)</li>
                        <li><strong>Contact Information</strong>: Email address, mobile number</li>
                        <li><strong>Address Information</strong>: Street, house number (to verify residency in Barangay Culiat)</li>
                        <li><strong>Reservation Details</strong>: Facility, date, time, purpose, number of attendees</li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">4. Why We Collect Your Data (Legal Basis)</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        We process your personal data based on:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong>Your consent</strong> when you register and accept this policy</li>
                        <li><strong>Legitimate government function</strong> to manage public facilities and serve residents</li>
                        <li><strong>Legal obligation</strong> to maintain records as required by government regulations</li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">5. How We Use Your Data</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        Your information is used solely for:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li>Verifying your identity and residency</li>
                        <li>Processing and managing facility reservations</li>
                        <li>Communicating reservation status and updates</li>
                        <li>Coordinating facility usage and scheduling</li>
                        <li>Sending official advisories related to your reservations</li>
                        <li>Improving service delivery through anonymized analytics</li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">6. Data Sharing and Disclosure</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        We do <strong>not sell or share</strong> your personal data with third parties, except:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li>When required by law or court order</li>
                        <li>When necessary to protect public safety or interest</li>
                        <li>With other LGU offices for official coordination (e.g., disaster response)</li>
                        <li>With your explicit consent</li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">7. Data Retention</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        Personal data is retained for:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li><strong>Active accounts</strong>: Duration of account + 3 years after last activity</li>
                        <li><strong>Reservation records</strong>: 5 years as required by COA regulations</li>
                        <li><strong>Audit logs</strong>: 2 years for security purposes</li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        After retention periods, data is securely deleted or anonymized.
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">8. Your Rights as a Data Subject</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        Under the Data Privacy Act, you have the right to:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li><strong>Access</strong>: Request a copy of your personal data</li>
                        <li><strong>Rectify</strong>: Correct inaccurate or incomplete information</li>
                        <li><strong>Erase</strong>: Request deletion of your data (subject to legal retention requirements)</li>
                        <li><strong>Object</strong>: Object to processing for direct marketing or automated decisions</li>
                        <li><strong>Data Portability</strong>: Receive your data in a structured format</li>
                        <li><strong>Withdraw Consent</strong>: Withdraw consent at any time (may affect service availability)</li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        To exercise these rights, contact our Data Protection Officer.
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">9. Security Measures</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        We implement robust security safeguards:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong>Technical</strong>: Encrypted storage, password hashing, secure connections (HTTPS)</li>
                        <li><strong>Organizational</strong>: Role-based access control, staff training, audit logs</li>
                        <li><strong>Physical</strong>: Secure server facilities, restricted access to systems</li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">10. Automated Decision-Making</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        This system uses AI-powered features for:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li>Conflict detection (alerts for double-booking)</li>
                        <li>Facility recommendations based on your purpose</li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        These are <strong>advisory only</strong>. Final decisions on reservation approval are made by authorized LGU staff.
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">11. Data Breach Notification</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        In the unlikely event of a data breach affecting your personal information, we will:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li>Notify the National Privacy Commission within 72 hours</li>
                        <li>Notify affected individuals without undue delay</li>
                        <li>Take immediate steps to contain and remediate the breach</li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">12. Cookies and Tracking</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        This system uses:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li><strong>Essential cookies</strong>: For authentication and session management (required)</li>
                        <li><strong>Analytics</strong>: Anonymized usage data to improve services (optional)</li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        We do not use third-party tracking or advertising cookies.
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">13. Children's Privacy</h4>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        This system is intended for users 18 years and older. We do not knowingly collect personal data from minors without parental or guardian consent.
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">14. Changes to This Policy</h4>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        We may update this privacy policy to reflect changes in law or practice. Significant changes will be communicated via email or system notification.
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;">15. Contact Us</h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        For questions, concerns, or to exercise your data subject rights:
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong>Data Protection Officer</strong>: dpo@barangayculiat.gov.ph</li>
                        <li><strong>Office</strong>: Barangay Culiat Facilities Management Office</li>
                        <li><strong>National Privacy Commission</strong>: complaints@privacy.gov.ph (for unresolved concerns)</li>
                    </ul>

                    <p style="margin-top: 1.5rem; font-size: 0.9rem; opacity: 0.8; line-height: 1.6;">
                        <strong>Last Updated</strong>: February 1, 2026<br>
                        <strong>Effective Date</strong>: February 1, 2026
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
/* Terms modal styling - Fixed and improved */
#termsModal {
    z-index: 1055 !important;
}

#termsModal .terms-modal-dialog {
    max-width: 650px;
    margin: 5rem auto 2rem;
    max-height: 70vh;
    display: flex;
    flex-direction: column;
}

#termsModal .terms-modal-content {
    background: #ffffff;
    border-radius: 16px;
    border: none;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    max-height: 70vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#termsModal .modal-header {
    border-bottom: 2px solid rgba(0, 0, 0, 0.1);
    padding: 1.25rem 1.5rem;
    flex-shrink: 0;
    background: #ffffff;
    border-radius: 16px 16px 0 0;
}

#termsModal .modal-title {
    color: #1e3a5f;
    font-weight: 700;
    font-size: 1.375rem;
    margin: 0;
}

#termsModal .modal-body {
    padding: 1.5rem;
    color: #333;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    -webkit-overflow-scrolling: touch;
}

#termsModal .modal-footer {
    border-top: 2px solid rgba(0, 0, 0, 0.1);
    padding: 1.25rem 1.5rem;
    flex-shrink: 0;
    background: #ffffff;
    border-radius: 0 0 16px 16px;
}

/* Ensure modal is clickable */
#termsModal.show {
    pointer-events: auto;
}

#termsModal.show .modal-dialog {
    pointer-events: auto;
}

#termsModal.show .modal-content {
    pointer-events: auto;
}

/* Mobile adjustments */
@media (max-width: 768px) {
    #termsModal .terms-modal-dialog {
        max-width: 95%;
        margin: 3rem auto 1rem;
        max-height: 80vh;
    }
    
    #termsModal .terms-modal-content {
        max-height: 80vh;
    }
    
    #termsModal .modal-header,
    #termsModal .modal-footer {
        padding: 1rem;
    }
    
    #termsModal .modal-title {
        font-size: 1.125rem;
    }
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
        backdrop: false,     // No backdrop to avoid stacked appearance
        keyboard: true,      // Allow ESC to close
        focus: true          // Focus on modal when shown
    });

    // Helper to remove duplicate backdrops and ensure proper functionality
    // Note: Since backdrop is disabled, this is mainly for cleanup if any remnants exist
    const cleanupBackdrop = () => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        // Remove all backdrops since we don't want any
        backdrops.forEach(backdrop => backdrop.remove());
        // Ensure body can scroll (but modal prevents it)
        document.body.style.overflow = 'hidden';
    };
    
    // Clean up on modal hide
    modalElement.addEventListener('hidden.bs.modal', () => {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    });

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

// Real-time Form Validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.auth-form');
    if (!form) return;
    
    // Validation rules
    const validators = {
        first_name: {
            validate: (value) => {
                if (!value || value.trim().length < 2) {
                    return 'First name must be at least 2 characters';
                }
                if (!/^[a-zA-Z\s\-ñÑ]+$/.test(value)) {
                    return 'First name can only contain letters, spaces, and hyphens';
                }
                return null;
            }
        },
        middle_name: {
            validate: (value) => {
                if (value && !/^[a-zA-Z\s\-ñÑ]+$/.test(value)) {
                    return 'Middle name can only contain letters, spaces, and hyphens';
                }
                return null;
            }
        },
        last_name: {
            validate: (value) => {
                if (!value || value.trim().length < 2) {
                    return 'Last name must be at least 2 characters';
                }
                if (!/^[a-zA-Z\s\-ñÑ]+$/.test(value)) {
                    return 'Last name can only contain letters, spaces, and hyphens';
                }
                return null;
            }
        },
        suffix: {
            validate: (value) => {
                if (value && !/^[a-zA-Z.\s]+$/.test(value)) {
                    return 'Suffix can only contain letters, periods, and spaces';
                }
                return null;
            }
        },
        email: {
            validate: (value) => {
                if (!value) {
                    return 'Email address is required';
                }
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    return 'Please enter a valid email address (e.g., user@example.com)';
                }
                return null;
            }
        },
        mobile: {
            validate: (value) => {
                if (!value) return null; // Optional field
                // Remove spaces and special characters for validation
                const cleaned = value.replace(/[\s\-()]/g, '');
                // Philippine mobile number: +63 followed by 10 digits OR 09/08 followed by 9 digits
                if (!/^(\+639\d{9}|09\d{9}|08\d{9})$/.test(cleaned)) {
                    return 'Please enter a valid Philippine mobile number (e.g., +63 900 000 0000 or 0900 000 0000)';
                }
                return null;
            }
        },
        house_number: {
            validate: (value) => {
                if (!value || value.trim().length === 0) {
                    return 'House number is required';
                }
                return null;
            }
        },
        password: {
            validate: (value) => {
                if (!value || value.length < <?= PASSWORD_MIN_LENGTH; ?>) {
                    return 'Password must be at least <?= PASSWORD_MIN_LENGTH; ?> characters';
                }
                if (!/[A-Z]/.test(value)) {
                    return 'Password must contain at least one uppercase letter';
                }
                if (!/[a-z]/.test(value)) {
                    return 'Password must contain at least one lowercase letter';
                }
                if (!/[0-9]/.test(value)) {
                    return 'Password must contain at least one number';
                }
                return null;
            }
        }
    };
    
    // Create error message element
    function createErrorElement(fieldName) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.id = `error-${fieldName}`;
        errorDiv.style.cssText = 'color: #dc3545; font-size: 0.875rem; margin-top: 0.375rem; display: none; font-weight: 500;';
        return errorDiv;
    }
    
    // Show error
    function showError(input, message) {
        const wrapper = input.closest('.input-wrapper') || input.parentElement;
        const fieldName = input.name;
        let errorDiv = document.getElementById(`error-${fieldName}`);
        
        if (!errorDiv) {
            errorDiv = createErrorElement(fieldName);
            wrapper.appendChild(errorDiv);
        }
        
        errorDiv.textContent = '⚠️ ' + message;
        errorDiv.style.display = 'block';
        input.style.borderColor = '#dc3545';
        input.style.background = 'rgba(220, 53, 69, 0.1)';
        input.setAttribute('aria-invalid', 'true');
    }
    
    // Clear error
    function clearError(input) {
        const wrapper = input.closest('.input-wrapper') || input.parentElement;
        const fieldName = input.name;
        const errorDiv = document.getElementById(`error-${fieldName}`);
        
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
        
        input.style.borderColor = '';
        input.style.background = '';
        input.removeAttribute('aria-invalid');
    }
    
    // Validate field
    function validateField(input) {
        const fieldName = input.name;
        const validator = validators[fieldName];
        
        if (!validator) return true;
        
        const error = validator.validate(input.value);
        
        if (error) {
            showError(input, error);
            return false;
        } else {
            clearError(input);
            return true;
        }
    }
    
    // Add real-time validation to all fields
    Object.keys(validators).forEach(fieldName => {
        const input = form.querySelector(`[name="${fieldName}"]`);
        if (!input) return;
        
        // Validate on blur (when user leaves the field)
        input.addEventListener('blur', () => {
            validateField(input);
        });
        
        // Validate on input (as user types) - with debounce
        let timeout;
        input.addEventListener('input', () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                // Only show errors if field has been touched and has content
                if (input.value.length > 0) {
                    validateField(input);
                } else if (input.hasAttribute('required')) {
                    // For required fields, show error if empty after typing
                    validateField(input);
                } else {
                    clearError(input);
                }
            }, 500); // Wait 500ms after user stops typing
        });
    });
    
    // Validate all fields on form submit
    form.addEventListener('submit', (e) => {
        let isValid = true;
        
        Object.keys(validators).forEach(fieldName => {
            const input = form.querySelector(`[name="${fieldName}"]`);
            if (input && !validateField(input)) {
                isValid = false;
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            
            // Scroll to first error
            const firstError = form.querySelector('[aria-invalid="true"]');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstError.focus();
            }
            
            // Show general error message
            alert('Please fix the errors in the form before submitting.');
        }
    });
});

</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


