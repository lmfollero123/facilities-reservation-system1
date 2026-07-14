<?php
$useTailwind = true;
$authSplitLayout = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/secure_documents.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/captcha.php';
require_once __DIR__ . '/../../../../config/culiat_streets.php';

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
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $captcha = frs_verify_turnstile($_POST['cf-turnstile-response'] ?? null, (string)$clientIp);
        if (!$captcha['ok']) {
            $message = $captcha['error'];
            $messageType = 'error';
        } else
        if (!checkRateLimit('register_form_ip', (string)$clientIp, 3, 900)) {
            $message = 'Too many registration attempts from your network. Please try again later.';
            $messageType = 'error';
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
        if ($email !== '' && !checkRateLimit('register_form_email', strtolower($email), 2, 3600)) {
            $message = 'Too many registration attempts for this email. Please try again later.';
            $messageType = 'error';
        } else {
        
        // Build full name from parts (for backward compatibility with 'name' column)
        $nameParts = array_filter([$firstName, $middleName, $lastName]);
        $fullName = implode(' ', $nameParts);
        if (!empty($suffix)) {
            $fullName .= ' ' . $suffix;
        }
        
        // Build full address from parts (for backward compatibility with 'address' column)
        $fullAddress = frs_build_culiat_address($houseNumber, $street);
        
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
        } elseif (empty($street) || !frs_is_valid_culiat_street($street)) {
            $message = 'Please select a valid street.';
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
                            
                            // Insert new user with active status (auto-activated) but:
                            // - is_verified = 0 (ID/document verification by admin/staff)
                            // - email_verified = 0 (must verify email via code before login)
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            
                            if ($hasVerifiedColumn && $hasNameColumns) {
                                // New schema with is_verified and name/address columns
                                $stmt = $pdo->prepare("INSERT INTO users (name, first_name, middle_name, last_name, suffix, email, mobile, address, street, house_number, password_hash, role, status, is_verified, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Resident', 'active', ?, 0)");
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
                                    0  // ID verification handled by admin/staff; email verification handled separately
                                ]);
                            } elseif ($hasVerifiedColumn) {
                                // Schema with is_verified but no name/address columns (backward compatibility)
                                $stmt = $pdo->prepare("INSERT INTO users (name, email, mobile, address, password_hash, role, status, is_verified, email_verified) VALUES (?, ?, ?, ?, ?, 'Resident', 'active', ?, 0)");
                                $stmt->execute([
                                    $fullName,
                                    $email,
                                    $mobile ?: null,
                                    $fullAddress,
                                    $passwordHash,
                                    0  // ID verification handled by admin/staff; email verification handled separately
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
                                try {
                                    frs_send_email_verification($pdo, $userId, $email, $fullName);
                                } catch (Exception $e) {
                                    logSecurityEvent('registration_email_error', 'Failed to send email verification: ' . $e->getMessage(), 'error');
                                }

                                logSecurityEvent('registration_success', "New user registered (email verification pending): $email", 'info');

                                // Store context for verification step and redirect
                                if (session_status() === PHP_SESSION_NONE) {
                                    session_start();
                                }
                                $_SESSION['pending_email_verify_user_id'] = $userId;
                                $_SESSION['pending_email_verify_email'] = $email;
                                $_SESSION['email_verify_login_message'] = 'Enter the code sent to your email. It is valid for '
                                    . max(1, (int) ceil(((int) EMAIL_VERIFICATION_CODE_TTL_SECONDS) / 60))
                                    . ' minutes.';

                                header('Location: ' . base_path() . '/verify-email');
                                exit;
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
    }
}

$base = base_path();
ob_start();
?>
<section class="auth-split auth-split-register">
    <aside class="auth-split-brand" aria-hidden="false">
        <div class="auth-split-brand-bg" style="background-image: url('<?= htmlspecialchars($base); ?>/public/uploads/Main%20Bg.jpg');"></div>
        <div class="auth-split-decor" aria-hidden="true"></div>
        <div class="auth-split-brand-inner">
            <a href="<?= htmlspecialchars($base); ?>/" class="auth-split-back">
                <i class="bi bi-arrow-left"></i> Back to website
            </a>
            <img src="<?= htmlspecialchars($base); ?>/public/img/infragov-logo.png" alt="Barangay Culiat CPRFS" class="auth-split-brand-logo">
            <h2>Reserving Spaces,<br>Serving Community.</h2>
            <p>Join the Barangay Culiat Public Facilities Reservation System. Book courts, halls, and community spaces — made for Culiat residents.</p>
            <?php include __DIR__ . '/../../components/auth_facility_slideshow.php'; ?>
            <p class="auth-split-brand-footer">&copy; <?= date('Y'); ?> Barangay Culiat CPRFS. All rights reserved.</p>
        </div>
    </aside>

    <div class="auth-split-form-panel">
        <div class="auth-split-form-bg" style="background-image: url('<?= htmlspecialchars($base); ?>/public/uploads/Main%20Bg.jpg');"></div>
        <div class="auth-split-form-overlay" aria-hidden="true"></div>
        <div class="auth-split-form-inner is-wide">
            <div class="auth-split-form-top">
                <div class="auth-split-logo-text">
                    <img src="<?= htmlspecialchars($base); ?>/public/img/infragov-logo.png" alt="">
                    <span>Barangay Culiat <span style="color:#059669;">CPRFS</span></span>
                </div>
                <h1>Create an account</h1>
                <p class="auth-split-sub">Already have an account? <a href="<?= htmlspecialchars($base); ?>/login">Log in</a></p>
            </div>

            <?php if ($message): ?>
                <div class="auth-split-alert <?= $messageType === 'success' ? 'is-success' : ($messageType === 'warning' ? 'is-warning' : 'is-error'); ?>" role="alert">
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-split-form" enctype="multipart/form-data" id="registerForm">
                <?= csrf_field(); ?>
                <?php if (frs_captcha_enabled() && frs_turnstile_site_key() !== ''): ?>
                    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(frs_turnstile_site_key(), ENT_QUOTES, 'UTF-8'); ?>"></div>
                <?php endif; ?>

                <div class="auth-split-form-scroll">
                    <div class="auth-split-form-row">
                        <label>
                            First name *
                            <input name="first_name" type="text" placeholder="Juan" required autofocus value="<?= isset($_POST['first_name']) ? e($_POST['first_name']) : ''; ?>" minlength="2">
                        </label>
                        <label>
                            Last name *
                            <input name="last_name" type="text" placeholder="Dela Cruz" required value="<?= isset($_POST['last_name']) ? e($_POST['last_name']) : ''; ?>" minlength="2">
                        </label>
                    </div>

                    <div class="auth-split-form-row">
                        <label>
                            Middle name
                            <input name="middle_name" type="text" placeholder="Santos" value="<?= isset($_POST['middle_name']) ? e($_POST['middle_name']) : ''; ?>">
                        </label>
                        <label>
                            Suffix
                            <input name="suffix" type="text" placeholder="Jr., Sr., III" value="<?= isset($_POST['suffix']) ? e($_POST['suffix']) : ''; ?>" maxlength="10">
                        </label>
                    </div>

                    <label>
                        Email address *
                        <input name="email" type="email" placeholder="official@lgu.gov.ph" required value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                    </label>

                    <label>
                        Mobile number
                        <input name="mobile" type="tel" placeholder="+63 900 000 0000" value="<?= isset($_POST['mobile']) ? e($_POST['mobile']) : ''; ?>">
                    </label>

                    <div class="auth-split-form-row">
                        <?php
                        $streetFieldName = 'street';
                        $houseFieldName = 'house_number';
                        $selectedStreet = $_POST['street'] ?? '';
                        $selectedHouseNumber = $_POST['house_number'] ?? '';
                        $required = true;
                        $showHint = true;
                        $hintClass = 'auth-split-hint';
                        include __DIR__ . '/../../components/culiat_street_fields.php';
                        ?>
                    </div>

                    <label>
                        Password *
                        <div class="auth-split-field">
                            <input name="password" id="registerPassword" type="password" placeholder="Create a strong password" required minlength="<?= PASSWORD_MIN_LENGTH; ?>">
                            <button type="button" class="auth-split-password-toggle" id="toggleRegisterPassword" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <span class="auth-split-hint">At least <?= PASSWORD_MIN_LENGTH; ?> characters with uppercase, lowercase, and number.</span>
                    </label>

                    <div class="auth-split-section">
                        <p class="auth-split-section-title">Upload Valid ID (Optional)</p>
                        <span class="auth-split-hint" style="display:block;margin-bottom:0.75rem;line-height:1.5;">
                            Upload now or later from your profile to enable auto-approval on bookings. PDF, JPG, PNG. Max 5MB.
                        </span>
                        <label>
                            Valid ID
                            <input type="file" name="doc_valid_id" accept=".pdf,image/*">
                        </label>
                    </div>

                    <label class="auth-split-terms">
                        <input type="checkbox" name="accept_terms" required>
                        <span>I agree to the <a href="#" id="termsLink">Terms &amp; Conditions</a> and <a href="#" id="privacyLink">Data Privacy Policy</a> of Barangay Culiat CPRFS, including compliance with the Data Privacy Act of 2012 (RA 10173).</span>
                    </label>
                </div>

                <button class="btn-primary" type="submit" id="submitBtn">Create account</button>
            </form>
        </div>
    </div>
</section>

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

/* Terms modal - dark mode (data-theme="dark" on html) */
[data-theme="dark"] #termsModal .terms-modal-content,
[data-theme="dark"] #termsModal .modal-header,
[data-theme="dark"] #termsModal .modal-footer {
    background: #1e293b !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
}

[data-theme="dark"] #termsModal .modal-body {
    background: #1e293b !important;
    color: #e2e8f0 !important;
}

[data-theme="dark"] #termsModal .modal-body h1,
[data-theme="dark"] #termsModal .modal-body h2,
[data-theme="dark"] #termsModal .modal-body h3,
[data-theme="dark"] #termsModal .modal-body h4,
[data-theme="dark"] #termsModal .modal-body p,
[data-theme="dark"] #termsModal .modal-body li,
[data-theme="dark"] #termsModal .modal-body strong {
    color: #e2e8f0 !important;
}

[data-theme="dark"] #termsModal .modal-title {
    color: #f1f5f9 !important;
}

[data-theme="dark"] #termsModal .modal-header .btn-close {
    filter: invert(1);
    opacity: 0.9;
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
    const form = document.getElementById('registerForm') || document.querySelector('.auth-split-form');
    if (!form) return;

    const toggleBtn = document.getElementById('toggleRegisterPassword');
    const pwdInput = document.getElementById('registerPassword');
    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function () {
            const isHidden = pwdInput.type === 'password';
            pwdInput.type = isHidden ? 'text' : 'password';
            toggleBtn.innerHTML = isHidden ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    }
    
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
        const wrapper = input.closest('.auth-split-field') || input.closest('.input-wrapper') || input.parentElement;
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
        const wrapper = input.closest('.auth-split-field') || input.closest('.input-wrapper') || input.parentElement;
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


