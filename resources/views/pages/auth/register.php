<?php
$useTailwind = true;
$authSplitLayout = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/secure_documents.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/sms_helper.php';
require_once __DIR__ . '/../../../../config/captcha.php';
require_once __DIR__ . '/../../../../config/geocoding.php';

$pageTitle = frs_t('register.page_title');
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = frs_t('register.error_csrf');
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
            $message = frs_t('register.error_rate_ip');
            $messageType = 'error';
        } else {
        // Get name fields
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $middleName = sanitizeInput($_POST['middle_name'] ?? '');
        $lastName = sanitizeInput($_POST['last_name'] ?? '');
        $suffix = sanitizeInput($_POST['suffix'] ?? '');
        
        // Get address field (any address within Quezon City — no longer
        // restricted to Barangay Culiat streets; a referral from a Culiat
        // resident is required at booking time instead).
        $address = sanitizeInput($_POST['address'] ?? '');

        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        $mobileRaw = sanitizeInput($_POST['mobile'] ?? '');
        $mobile = $mobileRaw !== '' ? normalizePhilippineMobileNumber($mobileRaw) : '';
        $password = $_POST['password'] ?? '';
        $acceptTerms = isset($_POST['accept_terms']) && $_POST['accept_terms'] === 'on';
        if ($email !== '' && !checkRateLimit('register_form_email', strtolower($email), 2, 3600)) {
            $message = frs_t('register.error_rate_email');
            $messageType = 'error';
        } else {
        
        // Build full name from parts (for backward compatibility with 'name' column)
        $nameParts = array_filter([$firstName, $middleName, $lastName]);
        $fullName = implode(' ', $nameParts);
        if (!empty($suffix)) {
            $fullName .= ' ' . $suffix;
        }
        
        $fullAddress = $address;

        // Validate inputs
        if (empty($firstName) || strlen($firstName) < 2) {
            $message = frs_t('register.error_first_name');
            $messageType = 'error';
        } elseif (empty($lastName) || strlen($lastName) < 2) {
            $message = frs_t('register.error_last_name');
            $messageType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = frs_t('register.error_email_invalid');
            $messageType = 'error';
        } elseif (empty($address) || strlen($address) < 5) {
            $message = frs_t('register.error_address');
            $messageType = 'error';
        } elseif ($mobileRaw !== '' && $mobile === null) {
            $message = frs_t('register.error_mobile');
            $messageType = 'error';
        } elseif (!$acceptTerms) {
            $message = frs_t('register.error_terms');
            $messageType = 'error';
        } else {
            // Check rate limiting (by IP)
            $clientIP = getClientIP();
            if (!checkRegisterRateLimit($clientIP)) {
                $message = frs_t('register.error_rate_limit');
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
                            $message = frs_t('register.error_email_taken');
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

                            // Geocode so chatbot "nearest facility" etc. work right away.
                            $latitude = null;
                            $longitude = null;
                            $coords = geocodeAddress($fullAddress);
                            if ($coords && isset($coords['lat'], $coords['lng'])) {
                                $latitude = $coords['lat'];
                                $longitude = $coords['lng'];
                            }

                            if ($hasVerifiedColumn && $hasNameColumns) {
                                // New schema with is_verified and name/address columns
                                $stmt = $pdo->prepare("INSERT INTO users (name, first_name, middle_name, last_name, suffix, email, mobile, address, latitude, longitude, password_hash, role, status, is_verified, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Resident', 'active', ?, 0)");
                                $stmt->execute([
                                    $fullName,
                                    $firstName,
                                    $middleName ?: null,
                                    $lastName,
                                    $suffix ?: null,
                                    $email,
                                    $mobile ?: null,
                                    $fullAddress,
                                    $latitude,
                                    $longitude,
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
                                $stmt = $pdo->prepare("INSERT INTO users (name, first_name, middle_name, last_name, suffix, email, mobile, address, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Resident', 'pending')");
                                $stmt->execute([
                                    $fullName,
                                    $firstName,
                                    $middleName ?: null,
                                    $lastName,
                                    $suffix ?: null,
                                    $email,
                                    $mobile ?: null,
                                    $fullAddress,
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
                                    $message = frs_t('register.warning_id_save_failed');
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
                                $_SESSION['email_verify_login_message'] = frs_t('register.verify_email_notice', [
                                    'minutes' => max(1, (int) ceil(((int) EMAIL_VERIFICATION_CODE_TTL_SECONDS) / 60)),
                                ]);

                                header('Location: ' . base_path() . '/verify-email');
                                exit;
                            }
                        }
                    } catch (Exception $e) {
                        // Check if the error is due to missing is_verified column
                        $errorMsg = $e->getMessage();
                        if (stripos($errorMsg, 'is_verified') !== false || stripos($errorMsg, 'Unknown column') !== false) {
                            $message = frs_t('register.error_migration');
                            $messageType = 'error';
                            logSecurityEvent('registration_error', "Database migration needed - is_verified column missing: " . $errorMsg, 'error');
                        } else {
                            $message = frs_t('register.error_failed_prefix') . htmlspecialchars($errorMsg);
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
        <?php include __DIR__ . '/../../components/auth_brand_illustration.php'; ?>
        <div class="auth-split-brand-inner">
            <a href="<?= htmlspecialchars($base); ?>/" class="auth-split-back">
                <i class="bi bi-arrow-left"></i> <?= frs_te('register.back_to_website'); ?>
            </a>
            <img src="<?= htmlspecialchars($base); ?>/public/img/brgy-culiat-logo.png" alt="Barangay Culiat CPRFS" class="auth-split-brand-logo">
            <h2><?= frs_te('register.brand_tagline_1'); ?><br><?= frs_te('register.brand_tagline_2'); ?></h2>
            <p><?= frs_te('register.brand_intro'); ?></p>
            <?php include __DIR__ . '/../../components/auth_facility_slideshow.php'; ?>
            <p class="auth-split-brand-footer"><?= frs_te('register.brand_footer', ['year' => date('Y')]); ?></p>
        </div>
    </aside>

    <div class="auth-split-form-panel">
        <div class="auth-split-form-decor" aria-hidden="true">
            <!-- Top-right tropical palm / leaf cluster -->
            <svg class="auth-split-form-decor__palm-tr" viewBox="0 0 280 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g fill="#059669" fill-opacity="0.5">
                    <path d="M140 30 C 170 50, 210 60, 260 55 C 215 70, 180 90, 150 120 C 142 90, 135 60, 140 30 Z"/>
                    <path d="M140 30 C 130 60, 100 90, 55 110 C 90 85, 118 65, 138 35 Z" fill-opacity="0.4"/>
                    <path d="M140 30 C 168 48, 200 100, 225 160 C 190 125, 165 85, 142 38 Z" fill-opacity="0.45"/>
                    <path d="M140 30 C 115 55, 80 115, 45 175 C 80 140, 110 90, 138 35 Z" fill-opacity="0.35"/>
                </g>
                <g fill="#10b981" fill-opacity="0.35">
                    <path d="M145 60 C 165 80, 200 120, 245 170 C 208 140, 178 105, 148 65 Z"/>
                    <path d="M138 60 C 118 90, 85 140, 35 200 C 80 165, 115 115, 140 65 Z"/>
                </g>
                <!-- Thin stem -->
                <path d="M140 35 C 142 80, 144 140, 146 220" stroke="#047857" stroke-opacity="0.4" stroke-width="3" stroke-linecap="round" fill="none"/>
            </svg>

            <!-- Bottom-left grass / cropland tufts -->
            <svg class="auth-split-form-decor__grass-bl" viewBox="0 0 320 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Background grass mound -->
                <ellipse cx="160" cy="200" rx="160" ry="40" fill="#059669" fill-opacity="0.18"/>
                <ellipse cx="110" cy="210" rx="120" ry="32" fill="#10b981" fill-opacity="0.15"/>
                <!-- Individual grass blades -->
                <g stroke="#059669" stroke-opacity="0.55" fill="none" stroke-linecap="round">
                    <path d="M40 210 C 42 185, 44 165, 46 140" stroke-width="3"/>
                    <path d="M60 210 C 65 180, 70 155, 75 130" stroke-width="3.5"/>
                    <path d="M82 210 C 88 178, 94 150, 100 120" stroke-width="4"/>
                    <path d="M105 210 C 110 185, 115 160, 122 135" stroke-width="3"/>
                    <path d="M128 210 C 133 182, 138 155, 146 128" stroke-width="3.5"/>
                    <path d="M152 210 C 156 188, 160 165, 168 140" stroke-width="2.8"/>
                    <path d="M176 210 C 180 185, 184 158, 192 132" stroke-width="3.2"/>
                    <path d="M200 210 C 204 188, 208 162, 216 138" stroke-width="2.6"/>
                    <path d="M222 210 C 226 190, 230 168, 238 148" stroke-width="3"/>
                    <!-- Secondary layer shorter blades -->
                    <path d="M50 210 C 53 195, 56 180, 60 165" stroke-width="2"/>
                    <path d="M72 210 C 76 195, 80 178, 86 160" stroke-width="2.2"/>
                    <path d="M96 210 C 100 192, 104 172, 110 154" stroke-width="2"/>
                    <path d="M118 210 C 122 195, 126 175, 132 158" stroke-width="2"/>
                    <path d="M142 210 C 146 196, 150 178, 156 160" stroke-width="2"/>
                    <path d="M164 210 C 168 195, 172 178, 178 160" stroke-width="1.8"/>
                    <path d="M186 210 C 190 195, 194 178, 200 160" stroke-width="2"/>
                    <path d="M210 210 C 214 195, 218 180, 224 165" stroke-width="2"/>
                </g>
                <!-- Wheat / grain tufts accent (amber, barangay harvest feel) -->
                <g fill="#f59e0b" fill-opacity="0.45">
                    <circle cx="76" cy="118" r="4"/>
                    <circle cx="72" cy="128" r="3"/>
                    <circle cx="82" cy="130" r="3"/>
                    <circle cx="102" cy="108" r="4.5"/>
                    <circle cx="97" cy="120" r="3.5"/>
                    <circle cx="108" cy="122" r="3.2"/>
                    <circle cx="148" cy="116" r="4"/>
                    <circle cx="144" cy="128" r="3"/>
                    <circle cx="154" cy="130" r="3"/>
                </g>
            </svg>

            <!-- Sun accent (mirrors the left panel's sun motif) -->
            <svg class="auth-split-form-decor__sun-accent" viewBox="0 0 70 70" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Soft sun disc -->
                <circle cx="35" cy="35" r="24" fill="#fbbf24" fill-opacity="0.5"/>
                <circle cx="35" cy="35" r="18" fill="#fde68a" fill-opacity="0.7"/>
                <!-- Rays -->
                <g stroke="#fbbf24" stroke-opacity="0.55" stroke-width="2" stroke-linecap="round">
                    <line x1="35" y1="4"  x2="35" y2="12"/>
                    <line x1="35" y1="58" x2="35" y2="66"/>
                    <line x1="4"  y1="35" x2="12" y2="35"/>
                    <line x1="58" y1="35" x2="66" y2="35"/>
                    <line x1="13" y1="13" x2="19" y2="19"/>
                    <line x1="51" y1="51" x2="57" y2="57"/>
                    <line x1="13" y1="57" x2="19" y2="51"/>
                    <line x1="51" y1="19" x2="57" y2="13"/>
                </g>
            </svg>

            <!-- Tiny floating leaf #1 (right) -->
            <svg class="auth-split-form-decor__leaf auth-split-form-decor__leaf--1" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 23 C 3 23, 8 18, 14 12 C 20 6, 24 3, 24 3 C 24 3, 19 9, 13 15 C 7 21, 3 23, 3 23 Z" fill="#10b981" fill-opacity="0.65"/>
                <path d="M4 22 C 10 16, 22 5, 23 4" stroke="#047857" stroke-opacity="0.5" stroke-width="1" fill="none"/>
            </svg>
            <!-- Tiny floating leaf #2 (left-lower) -->
            <svg class="auth-split-form-decor__leaf auth-split-form-decor__leaf--2" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 23 C 3 23, 8 18, 14 12 C 20 6, 24 3, 24 3 C 24 3, 19 9, 13 15 C 7 21, 3 23, 3 23 Z" fill="#059669" fill-opacity="0.55"/>
                <path d="M4 22 C 10 16, 22 5, 23 4" stroke="#064e3b" stroke-opacity="0.4" stroke-width="1" fill="none"/>
            </svg>
            <!-- Tiny floating leaf #3 (left-upper) -->
            <svg class="auth-split-form-decor__leaf auth-split-form-decor__leaf--3" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M23 3 C 23 3, 18 8, 12 14 C 6 20, 3 24, 3 24 C 3 24, 9 19, 15 13 C 21 7, 23 3, 23 3 Z" fill="#fbbf24" fill-opacity="0.55"/>
                <path d="M22 4 C 16 10, 5 22, 4 23" stroke="#d97706" stroke-opacity="0.45" stroke-width="1" fill="none"/>
            </svg>
        </div>
        <div class="auth-split-form-inner is-wide">
            <div class="auth-split-form-top">
                <div class="auth-split-logo-text">
                    <img src="<?= htmlspecialchars($base); ?>/public/img/brgy-culiat-logo.png" alt="">
                    <span>Barangay Culiat <span style="color:#059669;">CPRFS</span></span>
                </div>
                <h1><?= frs_te('register.heading'); ?></h1>
                <p class="auth-split-sub"><?= frs_te('register.have_account'); ?> <a href="<?= htmlspecialchars($base); ?>/login"><?= frs_te('register.login_link'); ?></a></p>
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
                            <?= frs_te('register.first_name'); ?> *
                            <input name="first_name" type="text" placeholder="Juan" required autofocus value="<?= isset($_POST['first_name']) ? e($_POST['first_name']) : ''; ?>" minlength="2">
                        </label>
                        <label>
                            <?= frs_te('register.last_name'); ?> *
                            <input name="last_name" type="text" placeholder="Dela Cruz" required value="<?= isset($_POST['last_name']) ? e($_POST['last_name']) : ''; ?>" minlength="2">
                        </label>
                    </div>

                    <div class="auth-split-form-row">
                        <label>
                            <?= frs_te('register.middle_name'); ?>
                            <input name="middle_name" type="text" placeholder="Santos" value="<?= isset($_POST['middle_name']) ? e($_POST['middle_name']) : ''; ?>">
                        </label>
                        <label>
                            <?= frs_te('register.suffix'); ?>
                            <input name="suffix" type="text" placeholder="Jr., Sr., III" value="<?= isset($_POST['suffix']) ? e($_POST['suffix']) : ''; ?>" maxlength="10">
                        </label>
                    </div>

                    <label>
                        <?= frs_te('register.email'); ?> *
                        <div class="auth-split-field">
                            <i class="bi bi-envelope auth-split-field-icon" aria-hidden="true"></i>
                            <input name="email" type="email" placeholder="official@lgu.gov.ph" required value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                        </div>
                    </label>

                    <label>
                        <?= frs_te('register.mobile'); ?>
                        <input name="mobile" type="tel" placeholder="+63 900 000 0000" value="<?= isset($_POST['mobile']) ? e($_POST['mobile']) : ''; ?>">
                    </label>

                    <label>
                        <?= frs_te('register.address'); ?> *
                        <input name="address" type="text" placeholder="<?= frs_te('register.address_placeholder'); ?>" required minlength="5" value="<?= isset($_POST['address']) ? e($_POST['address']) : ''; ?>">
                        <small class="auth-split-hint"><?= frs_te('register.address_hint'); ?></small>
                    </label>

                    <label>
                        <?= frs_te('register.password'); ?> *
                        <div class="auth-split-field">
                            <i class="bi bi-lock auth-split-field-icon" aria-hidden="true"></i>
                            <input name="password" id="registerPassword" type="password" placeholder="<?= frs_te('register.password_placeholder'); ?>" required minlength="<?= PASSWORD_MIN_LENGTH; ?>">
                            <button type="button" class="auth-split-password-toggle" id="toggleRegisterPassword" aria-label="<?= frs_te('register.show_password'); ?>">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <span class="auth-split-hint"><?= frs_te('register.password_hint', ['min' => PASSWORD_MIN_LENGTH]); ?></span>
                    </label>

                    <div class="auth-split-section">
                        <p class="auth-split-section-title"><?= frs_te('register.upload_id_title'); ?></p>
                        <span class="auth-split-hint" style="display:block;margin-bottom:0.75rem;line-height:1.5;">
                            <?= frs_te('register.upload_id_hint'); ?>
                        </span>
                        <label>
                            <?= frs_te('register.valid_id'); ?>
                            <input type="file" name="doc_valid_id" accept=".pdf,image/*">
                        </label>
                    </div>

                    <label class="auth-split-terms">
                        <span class="auth-split-terms-control">
                            <input type="checkbox" name="accept_terms" required class="auth-split-terms-input">
                            <span class="auth-split-terms-box" aria-hidden="true"></span>
                        </span>
                        <span><?= frs_te('register.terms_agree_prefix'); ?> <a href="#" id="termsLink"><?= frs_te('register.terms_link'); ?></a> <?= frs_te('register.terms_agree_and'); ?> <a href="#" id="privacyLink"><?= frs_te('register.privacy_link'); ?></a> <?= frs_te('register.terms_agree_suffix'); ?></span>
                    </label>
                </div>

                <button class="btn-primary" type="submit" id="submitBtn"><?= frs_te('register.submit'); ?></button>
                <p class="auth-split-trust"><i class="bi bi-shield-check" aria-hidden="true"></i> <?= frs_te('register.trust_note'); ?></p>
            </form>
        </div>
    </div>
</section>

<!-- Terms and Conditions Modal -->
<div class="modal" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg terms-modal-dialog terms-modal-content">
            <div class="modal-header" style="border-bottom: 2px solid rgba(0, 0, 0, 0.1); padding: 1.5rem; flex-shrink: 0;">
                <h5 class="modal-title" id="termsModalLabel" style="color: #1e3a5f; font-weight: 700; font-size: 1.5rem;">
                    <?= frs_te('register.modal_title'); ?>
                </h5>
                <button type="button" class="btn-close" aria-label="<?= frs_te('register.modal_close'); ?>"></button>
            </div>
            <div class="modal-body" style="padding: 1.5rem; color: #333; overflow-y: auto; flex: 1; min-height: 0;">
                <div style="margin-bottom: 2rem;">
                    <h3 style="color: #1e3a5f; font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem;"><?= frs_te('register.terms_heading'); ?></h3>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.terms_p1'); ?>
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.terms_p2'); ?>
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.terms_p3'); ?>
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.terms_p4'); ?>
                    </p>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.terms_p5'); ?>
                    </p>
                </div>
                
                <div style="border-top: 2px solid rgba(0, 0, 0, 0.1); padding-top: 2rem;">
                    <h3 style="color: #1e3a5f; font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem;"><?= frs_te('register.privacy_heading'); ?></h3>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s1_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.privacy_s1_before'); ?> <strong><?= frs_te('register.privacy_s1_law'); ?></strong> <?= frs_te('register.privacy_s1_after'); ?>
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s2_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s2_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><?= frs_te('register.privacy_s2_li1'); ?></li>
                        <li><?= frs_te('register.privacy_s2_li2'); ?></li>
                        <li><?= frs_te('register.privacy_s2_li3'); ?></li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s3_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s3_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong><?= frs_te('register.privacy_s3_li1_label'); ?></strong>: <?= frs_te('register.privacy_s3_li1_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s3_li2_label'); ?></strong>: <?= frs_te('register.privacy_s3_li2_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s3_li3_label'); ?></strong>: <?= frs_te('register.privacy_s3_li3_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s3_li4_label'); ?></strong>: <?= frs_te('register.privacy_s3_li4_text'); ?></li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s4_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s4_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong><?= frs_te('register.privacy_s4_li1_label'); ?></strong> <?= frs_te('register.privacy_s4_li1_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s4_li2_label'); ?></strong> <?= frs_te('register.privacy_s4_li2_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s4_li3_label'); ?></strong> <?= frs_te('register.privacy_s4_li3_text'); ?></li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s5_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s5_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><?= frs_te('register.privacy_s5_li1'); ?></li>
                        <li><?= frs_te('register.privacy_s5_li2'); ?></li>
                        <li><?= frs_te('register.privacy_s5_li3'); ?></li>
                        <li><?= frs_te('register.privacy_s5_li4'); ?></li>
                        <li><?= frs_te('register.privacy_s5_li5'); ?></li>
                        <li><?= frs_te('register.privacy_s5_li6'); ?></li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s6_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s6_intro_before'); ?> <strong><?= frs_te('register.privacy_s6_intro_strong'); ?></strong> <?= frs_te('register.privacy_s6_intro_after'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><?= frs_te('register.privacy_s6_li1'); ?></li>
                        <li><?= frs_te('register.privacy_s6_li2'); ?></li>
                        <li><?= frs_te('register.privacy_s6_li3'); ?></li>
                        <li><?= frs_te('register.privacy_s6_li4'); ?></li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s7_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s7_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li><strong><?= frs_te('register.privacy_s7_li1_label'); ?></strong>: <?= frs_te('register.privacy_s7_li1_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s7_li2_label'); ?></strong>: <?= frs_te('register.privacy_s7_li2_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s7_li3_label'); ?></strong>: <?= frs_te('register.privacy_s7_li3_text'); ?></li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.privacy_s7_after'); ?>
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s8_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s8_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li><strong><?= frs_te('register.privacy_s8_li1_label'); ?></strong>: <?= frs_te('register.privacy_s8_li1_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s8_li2_label'); ?></strong>: <?= frs_te('register.privacy_s8_li2_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s8_li3_label'); ?></strong>: <?= frs_te('register.privacy_s8_li3_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s8_li4_label'); ?></strong>: <?= frs_te('register.privacy_s8_li4_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s8_li5_label'); ?></strong>: <?= frs_te('register.privacy_s8_li5_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s8_li6_label'); ?></strong>: <?= frs_te('register.privacy_s8_li6_text'); ?></li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.privacy_s8_after'); ?>
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s9_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s9_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong><?= frs_te('register.privacy_s9_li1_label'); ?></strong>: <?= frs_te('register.privacy_s9_li1_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s9_li2_label'); ?></strong>: <?= frs_te('register.privacy_s9_li2_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s9_li3_label'); ?></strong>: <?= frs_te('register.privacy_s9_li3_text'); ?></li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s10_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s10_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li><?= frs_te('register.privacy_s10_li1'); ?></li>
                        <li><?= frs_te('register.privacy_s10_li2'); ?></li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.privacy_s10_after_before'); ?> <strong><?= frs_te('register.privacy_s10_after_strong'); ?></strong><?= frs_te('register.privacy_s10_after_after'); ?>
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s11_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s11_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><?= frs_te('register.privacy_s11_li1'); ?></li>
                        <li><?= frs_te('register.privacy_s11_li2'); ?></li>
                        <li><?= frs_te('register.privacy_s11_li3'); ?></li>
                    </ul>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s12_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s12_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 0.5rem; padding-left: 1.5rem;">
                        <li><strong><?= frs_te('register.privacy_s12_li1_label'); ?></strong>: <?= frs_te('register.privacy_s12_li1_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s12_li2_label'); ?></strong>: <?= frs_te('register.privacy_s12_li2_text'); ?></li>
                    </ul>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.privacy_s12_after'); ?>
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s13_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.privacy_s13_p'); ?>
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s14_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        <?= frs_te('register.privacy_s14_p'); ?>
                    </p>

                    <h4 style="color: #1e3a5f; font-size: 1.1rem; font-weight: 600; margin: 1.5rem 0 0.75rem;"><?= frs_te('register.privacy_s15_title'); ?></h4>
                    <p style="line-height: 1.8; margin-bottom: 0.5rem;">
                        <?= frs_te('register.privacy_s15_intro'); ?>
                    </p>
                    <ul style="line-height: 1.8; margin-bottom: 1rem; padding-left: 1.5rem;">
                        <li><strong><?= frs_te('register.privacy_s15_li1_label'); ?></strong>: <?= frs_te('register.privacy_s15_li1_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s15_li2_label'); ?></strong>: <?= frs_te('register.privacy_s15_li2_text'); ?></li>
                        <li><strong><?= frs_te('register.privacy_s15_li3_label'); ?></strong>: <?= frs_te('register.privacy_s15_li3_text'); ?></li>
                    </ul>

                    <p style="margin-top: 1.5rem; font-size: 0.9rem; opacity: 0.8; line-height: 1.6;">
                        <strong><?= frs_te('register.privacy_last_updated_label'); ?></strong>: <?= frs_te('register.privacy_policy_date'); ?><br>
                        <strong><?= frs_te('register.privacy_effective_label'); ?></strong>: <?= frs_te('register.privacy_policy_date'); ?>
                    </p>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 2px solid rgba(0, 0, 0, 0.1); padding: 1.5rem; flex-shrink: 0;">
                <button type="button" class="btn btn-secondary" id="understandBtn" style="padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; background: #6c757d; border: none; color: #fff; cursor: pointer;">
                    <?= frs_te('register.modal_understand'); ?>
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
    // Terms & Conditions modal is now driven by the vanilla-JS controller further down this file.
    // The legacy Bootstrap-based logic below is intentionally bypassed by this early return.
    const modalElement = null;
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

// Terms & Conditions modal — vanilla controller (replaces the Bootstrap/Alpine versions; no eval, CSP-safe)
(function () {
    var modal = document.getElementById('termsModal');
    if (!modal) return;
    var STORAGE_KEY = 'lgu_facilities_terms_accepted';
    function openModal() { modal.classList.add('show'); modal.style.display = 'block'; document.body.style.overflow = 'hidden'; }
    function closeModal() { modal.classList.remove('show'); modal.style.display = 'none'; document.body.style.overflow = ''; }
    function acceptTerms() { localStorage.setItem(STORAGE_KEY, 'true'); closeModal(); }
    var closeBtn = modal.querySelector('.btn-close');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    var understandBtn = document.getElementById('understandBtn');
    if (understandBtn) understandBtn.addEventListener('click', acceptTerms);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
    ['termsLink', 'privacyLink'].forEach(function (id) {
        var link = document.getElementById(id);
        if (link) link.addEventListener('click', function (e) { e.preventDefault(); openModal(); });
    });
    if (!localStorage.getItem(STORAGE_KEY)) openModal();
})();

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
            toggleBtn.setAttribute('aria-label', isHidden ? <?= json_encode(frs_t('register.hide_password')); ?> : <?= json_encode(frs_t('register.show_password')); ?>);
        });
    }
    
    // Validation rules
    const validators = {
        first_name: {
            validate: (value) => {
                if (!value || value.trim().length < 2) {
                    return <?= json_encode(frs_t('register.js_first_name_min')); ?>;
                }
                if (!/^[a-zA-Z\s\-ñÑ]+$/.test(value)) {
                    return <?= json_encode(frs_t('register.js_first_name_chars')); ?>;
                }
                return null;
            }
        },
        middle_name: {
            validate: (value) => {
                if (value && !/^[a-zA-Z\s\-ñÑ]+$/.test(value)) {
                    return <?= json_encode(frs_t('register.js_middle_name_chars')); ?>;
                }
                return null;
            }
        },
        last_name: {
            validate: (value) => {
                if (!value || value.trim().length < 2) {
                    return <?= json_encode(frs_t('register.js_last_name_min')); ?>;
                }
                if (!/^[a-zA-Z\s\-ñÑ]+$/.test(value)) {
                    return <?= json_encode(frs_t('register.js_last_name_chars')); ?>;
                }
                return null;
            }
        },
        suffix: {
            validate: (value) => {
                if (value && !/^[a-zA-Z.\s]+$/.test(value)) {
                    return <?= json_encode(frs_t('register.js_suffix_chars')); ?>;
                }
                return null;
            }
        },
        email: {
            validate: (value) => {
                if (!value) {
                    return <?= json_encode(frs_t('register.js_email_required')); ?>;
                }
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    return <?= json_encode(frs_t('register.js_email_invalid')); ?>;
                }
                return null;
            }
        },
        mobile: {
            validate: (value) => {
                if (!value) return null; // Optional field
                // Strip everything but digits so +63 956 5121 966, 09565121966,
                // 639565121966, and 9565121966 all normalize the same way.
                const digits = value.replace(/\D/g, '');
                // 63 + 10 digits, OR 0 + 10 digits, OR bare 9 + 9 digits
                if (!/^(63\d{10}|0\d{10}|9\d{9})$/.test(digits)) {
                    return <?= json_encode(frs_t('register.js_mobile_invalid')); ?>;
                }
                return null;
            }
        },
        house_number: {
            validate: (value) => {
                if (!value || value.trim().length === 0) {
                    return <?= json_encode(frs_t('register.js_house_number_required')); ?>;
                }
                return null;
            }
        },
        password: {
            validate: (value) => {
                if (!value || value.length < <?= PASSWORD_MIN_LENGTH; ?>) {
                    return <?= json_encode(frs_t('register.js_password_min', ['min' => PASSWORD_MIN_LENGTH])); ?>;
                }
                if (!/[A-Z]/.test(value)) {
                    return <?= json_encode(frs_t('register.js_password_upper')); ?>;
                }
                if (!/[a-z]/.test(value)) {
                    return <?= json_encode(frs_t('register.js_password_lower')); ?>;
                }
                if (!/[0-9]/.test(value)) {
                    return <?= json_encode(frs_t('register.js_password_number')); ?>;
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
            alert(<?= json_encode(frs_t('register.js_fix_errors')); ?>);
        } else {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="auth-split-btn-spinner" aria-hidden="true"></span> ' + <?= json_encode(frs_t('register.js_creating_account')); ?>;
            }
        }
    });
});

</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


