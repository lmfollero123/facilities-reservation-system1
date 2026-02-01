<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/data_export.php';

// Load Composer autoload for TOTP library
// Try multiple possible paths
$possiblePaths = [
    __DIR__ . '/../../../../vendor/autoload.php',  // From resources/views/pages/dashboard/
    __DIR__ . '/../../../vendor/autoload.php',      // Alternative path
    dirname(__DIR__, 4) . '/vendor/autoload.php', // Using dirname with levels
];
$autoloadLoaded = false;
foreach ($possiblePaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}
if (!$autoloadLoaded) {
    error_log('Composer autoload not found. Tried: ' . implode(', ', $possiblePaths) . ' | Current dir: ' . __DIR__);
}

$pdo = db();
$pageTitle = 'Profile | LGU Facilities Reservation';
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: /resources/views/pages/auth/login.php');
    exit;
}

$success = '';
$error = '';

// Handle account deactivation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_account'])) {
    require_once __DIR__ . '/../../../../config/audit.php';
    
    // Get user data first
    $stmt = $pdo->prepare('SELECT id, name, email, status, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $userCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userCheck) {
        $error = 'User not found.';
    } elseif (strtolower($userCheck['status']) === 'deactivated') {
        $error = 'Account is already deactivated.';
    } elseif (in_array($userCheck['role'], ['Admin', 'Staff'])) {
        $error = 'Administrator and Staff accounts cannot be self-deactivated. Please contact system administrator.';
    } else {
        try {
            $reason = trim($_POST['deactivation_reason'] ?? '');
            $reason = !empty($reason) ? substr($reason, 0, 500) : null; // Limit length
            
            // Soft delete: Set status to 'deactivated' and record timestamp
            // Check if deactivated_at column exists (backward compatibility)
            $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'deactivated_at'");
            $hasDeactivatedAt = $checkColumn->rowCount() > 0;
            
            if ($hasDeactivatedAt) {
                $updateStmt = $pdo->prepare(
                    'UPDATE users 
                     SET status = "deactivated", 
                         deactivated_at = CURRENT_TIMESTAMP,
                         deactivation_reason = :reason,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    'reason' => $reason,
                    'id' => $userId
                ]);
            } else {
                // Fallback if migration hasn't been run yet
                $updateStmt = $pdo->prepare(
                    'UPDATE users 
                     SET status = "deactivated", 
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $updateStmt->execute(['id' => $userId]);
            }
            
            // Log audit event
            $details = 'Account deactivated by user';
            if ($reason) {
                $details .= ' - Reason: ' . substr($reason, 0, 100);
            }
            logAudit('Deactivated account', 'User Management', $details, $userId);
            
            // Destroy session and redirect to login
            session_destroy();
            header('Location: ' . base_path() . '/resources/views/pages/auth/login.php?deactivated=1');
            exit;
            
        } catch (Throwable $e) {
            $error = 'Failed to deactivate account. Please try again or contact support.';
            error_log('Account deactivation error: ' . $e->getMessage());
        }
    }
}

// Handle TOTP (Google Authenticator) - Admin/Staff only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId && in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    if (isset($_POST['totp_disable'])) {
        if (isset($_POST[CSRF_TOKEN_NAME]) && verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            try {
                $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
                $success = 'Authenticator app has been disabled. You will use email OTP only.';
            } catch (Throwable $e) {
                $error = 'Failed to disable authenticator.';
            }
        }
    } elseif (isset($_POST['totp_enable_request'])) {
        if (isset($_POST[CSRF_TOKEN_NAME]) && verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            try {
                // Check if autoload is working
                if (!class_exists('RobThree\Auth\TwoFactorAuth')) {
                    throw new Exception('TwoFactorAuth class not found. Please run: composer install');
                }
                if (!class_exists('RobThree\Auth\Providers\Qr\QRServerProvider')) {
                    throw new Exception('QRServerProvider class not found. Please run: composer install');
                }
                $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
                $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
                $secret = $tfa->createSecret();
                $_SESSION['totp_pending_secret'] = $secret;
                header('Location: ' . base_path() . '/dashboard/profile?totp_setup=1');
                exit;
            } catch (Throwable $e) {
                error_log('TOTP setup error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
                $error = 'Could not start authenticator setup: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif (isset($_POST['totp_verify']) && isset($_POST['totp_code']) && !empty($_SESSION['totp_pending_secret'])) {
        if (isset($_POST[CSRF_TOKEN_NAME]) && verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $code = trim(preg_replace('/\D/', '', $_POST['totp_code']));
            try {
                if (!class_exists('RobThree\Auth\TwoFactorAuth')) {
                    throw new Exception('TwoFactorAuth class not available');
                }
                $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
                $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
                if (strlen($code) === 6 && $tfa->verifyCode($_SESSION['totp_pending_secret'], $code)) {
                    $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$_SESSION['totp_pending_secret'], $userId]);
                    unset($_SESSION['totp_pending_secret']);
                    header('Location: ' . base_path() . '/dashboard/profile?totp_success=1');
                    exit;
                }
            } catch (Throwable $e) {
                error_log('TOTP verification error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
            }
            unset($_SESSION['totp_pending_secret']);
            $error = 'Invalid verification code. Please try enabling again.';
        }
    }
}

// Handle data export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_data'])) {
    $exportType = $_POST['export_type'] ?? 'full';
    
    try {
        $filepath = exportUserData($userId, $exportType);
        if ($filepath) {
            $success = 'Your data export has been generated successfully. Check the "Data Export" section below to download it.';
        } else {
            $error = 'Failed to generate data export. Please try again.';
        }
    } catch (Exception $e) {
        $error = 'Failed to generate data export: ' . $e->getMessage();
    }
}

// Get export history
$exportHistory = getUserExportHistory($userId);

// Load current user data
// Check if deactivated_at column exists for backward compatibility
$checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'deactivated_at'");
$hasDeactivatedAt = $checkColumn->rowCount() > 0;
$selectFields = 'id, name, email, mobile, address, latitude, longitude, profile_picture, password_hash, role, status, is_verified, verified_at, COALESCE(enable_otp, 1) as enable_otp, COALESCE(totp_enabled, 0) as totp_enabled';
if ($hasDeactivatedAt) {
    $selectFields .= ', deactivated_at, deactivation_reason';
}
$stmt = $pdo->prepare("SELECT $selectFields FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Prevent deactivated users from accessing profile (they shouldn't be logged in, but double-check)
if ($user && strtolower($user['status']) === 'deactivated') {
    session_destroy();
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php?deactivated=1');
    exit;
}

// Check if user has valid ID document
$hasValidId = false;
$validIdDoc = null;
if ($user) {
    $docStmt = $pdo->prepare('SELECT id, file_name, uploaded_at, is_archived FROM user_documents WHERE user_id = :user_id AND document_type = "valid_id" AND is_archived = 0 ORDER BY uploaded_at DESC LIMIT 1');
    $docStmt->execute(['user_id' => $userId]);
    $validIdDoc = $docStmt->fetch(PDO::FETCH_ASSOC);
    $hasValidId = (bool)$validIdDoc;
}

if (!$user) {
    $error = 'Unable to load your profile information.';
}

if (isset($_GET['totp_success'])) {
    $success = 'Google Authenticator is now enabled. You can use your authenticator app code at login instead of email OTP.';
}

$totpQrUri = null;
$totpSecret = null;
if (isset($_SESSION['totp_pending_secret']) && $user && !empty($user['email'])) {
    try {
        if (!class_exists('RobThree\Auth\TwoFactorAuth')) {
            throw new Exception('TwoFactorAuth class not available');
        }
        $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
        $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
        $totpQrUri = $tfa->getQRCodeImageAsDataUri($user['email'], $_SESSION['totp_pending_secret']);
        $totpSecret = $_SESSION['totp_pending_secret'];
    } catch (Throwable $e) {
        error_log('TOTP QR generation error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
        unset($_SESSION['totp_pending_secret']);
        if (empty($error)) {
            $error = 'Could not generate QR code: ' . htmlspecialchars($e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    // Handle OTP preference update separately (if only OTP toggle is changed)
    if (isset($_POST['enable_otp']) && !isset($_POST['name']) && !isset($_POST['current_password'])) {
        // This is an OTP preference-only update
        $enableOtp = (int)$_POST['enable_otp'];
        try {
            $updateStmt = $pdo->prepare('UPDATE users SET enable_otp = :enable_otp, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $updateStmt->execute(['enable_otp' => $enableOtp, 'id' => $userId]);
            
            // Refresh user data with same select fields as loaded earlier
            $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'deactivated_at'");
            $hasDeactivatedAt = $checkColumn->rowCount() > 0;
            $refreshFields = 'id, name, email, mobile, address, latitude, longitude, profile_picture, password_hash, role, status, is_verified, verified_at, COALESCE(enable_otp, 1) as enable_otp, COALESCE(totp_enabled, 0) as totp_enabled';
            if ($hasDeactivatedAt) {
                $refreshFields .= ', deactivated_at, deactivation_reason';
            }
            $stmt = $pdo->prepare("SELECT $refreshFields FROM users WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $success = $enableOtp ? 'OTP has been enabled. You will receive a code via email on each login.' : 'OTP has been disabled. You can now log in with just your password.';
        } catch (Exception $e) {
            $error = 'Unable to update OTP preference. Please try again.';
        }
    }
    
    // Check if this is a password-only update (password form) or profile update
    $hasPasswordFields = !empty($_POST['current_password']) || !empty($_POST['new_password']) || !empty($_POST['confirm_password']);
    $hasProfileFields = isset($_POST['name']) && isset($_POST['email']);
    $hasProfilePicture = !empty($_FILES['profile_picture']['name']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK;
    $hasValidIdUpload = !empty($_FILES['doc_valid_id']['name']) && $_FILES['doc_valid_id']['error'] === UPLOAD_ERR_OK;
    $updateOtpPreference = isset($_POST['enable_otp']); // Check if OTP preference is being updated
    
    // Get form values, use existing user data as fallback
    $name = trim($_POST['name'] ?? $user['name']);
    $email = trim($_POST['email'] ?? $user['email']);
    $mobile = trim($_POST['mobile'] ?? $user['mobile'] ?? '');
    $address = trim($_POST['address'] ?? $user['address'] ?? '');
    
    // If only profile picture upload, use existing values for name/email/mobile
    if ($hasProfilePicture && !$hasProfileFields) {
        $name = $user['name'];
        $email = $user['email'];
        $mobile = $user['mobile'] ?? '';
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Handle profile picture upload with enhanced security
    $profilePicture = $user['profile_picture'] ?? null;
    if (!empty($_FILES['profile_picture']['name']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        require_once __DIR__ . '/../../../../config/security.php';
        require_once __DIR__ . '/../../../../config/upload_helper.php';
        $uploadErrors = validateFileUpload($_FILES['profile_picture'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 2 * 1024 * 1024);
        
        if (!empty($uploadErrors)) {
            $error = implode(' ', $uploadErrors);
        } else {
            $uploadDir = __DIR__ . '/../../../../public/uploads/profile_pictures';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($user['name'] ?? 'user'));
            $fileName = 'profile-' . $userId . '-' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $fileName;
            
            [$ok, $err] = saveOptimizedImage($_FILES['profile_picture']['tmp_name'], $targetPath, 900, 82);
            if (!$ok) {
                // Fallback to original move for GIFs/unsupported types
                if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                    $error = $err ?: 'Failed to upload profile picture. Please try again.';
                } else {
                    @chmod($targetPath, 0644);
                    $profilePicture = '/public/uploads/profile_pictures/' . $fileName;
                }
            } else {
                @chmod($targetPath, 0644);
                $profilePicture = '/public/uploads/profile_pictures/' . $fileName;
            }
            
            if (empty($error)) {
                // Delete old profile picture if exists
                if ($profilePicture && file_exists(__DIR__ . '/../../../../public' . ($user['profile_picture'] ?? ''))) {
                    @unlink(__DIR__ . '/../../../../public' . $user['profile_picture']);
                }
            }
        }
    }

    // Only validate name/email if this is a profile update (name/email fields were submitted)
    // Skip validation if only profile picture is being uploaded
    if ($hasProfileFields && !$hasProfilePicture) {
        if ($name === '' || $email === '') {
            $error = 'Name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        }
    }
    
    if ($error === '') {
        try {
            // Check if email is already used by another user (only if email is being changed)
            if ($hasProfileFields && $email !== $user['email']) {
                $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $emailStmt->execute(['email' => $email, 'id' => $userId]);
                $emailOwner = $emailStmt->fetch(PDO::FETCH_ASSOC);

                if ($emailOwner) {
                    $error = 'This email address is already in use by another account.';
                }
            }
            
            if ($error === '') {
                // Handle password change if requested
                $updatePassword = false;
                $newPasswordHash = $user['password_hash'];

                if ($hasPasswordFields) {
                    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                        $error = 'To change your password, fill in all password fields.';
                    } elseif (!password_verify($currentPassword, $user['password_hash'])) {
                        $error = 'Your current password is incorrect.';
                    } elseif (strlen($newPassword) < 8) {
                        $error = 'New password must be at least 8 characters.';
                    } elseif ($newPassword !== $confirmPassword) {
                        $error = 'New password and confirmation do not match.';
                    } else {
                        $updatePassword = true;
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    }
                }

                if ($error === '') {
                    // Handle coordinates: manual entry takes priority, then geocoding, then keep existing
                    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : $user['latitude'];
                    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : $user['longitude'];
                    
                    // If manual coordinates provided, use them
                    if (!empty($_POST['latitude']) && !empty($_POST['longitude'])) {
                        $latitude = (float)$_POST['latitude'];
                        $longitude = (float)$_POST['longitude'];
                    } elseif ($hasProfileFields && !empty($address) && $address !== ($user['address'] ?? '')) {
                        // Try geocoding if address changed and no manual coordinates
                        require_once __DIR__ . '/../../../../config/geocoding.php';
                        $coords = geocodeAddress($address);
                        if ($coords) {
                            $latitude = $coords['lat'];
                            $longitude = $coords['lng'];
                        }
                        // If geocoding fails, keep existing coordinates (don't clear them)
                    }
                    
                    // Only update fields that were actually changed
                    $enableOtp = isset($_POST['enable_otp']) ? (int)$_POST['enable_otp'] : ($user['enable_otp'] ?? 1);
                    $updateStmt = $pdo->prepare(
                        'UPDATE users 
                         SET name = :name, email = :email, mobile = :mobile, address = :address, latitude = :latitude, longitude = :longitude, profile_picture = :profile_picture, password_hash = :password_hash, enable_otp = :enable_otp, updated_at = CURRENT_TIMESTAMP 
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        'name' => $name,
                        'email' => $email,
                        'mobile' => $mobile ?: null,
                        'address' => $address ?: null,
                        'latitude' => $latitude ?: null,
                        'longitude' => $longitude ?: null,
                        'profile_picture' => $profilePicture,
                        'password_hash' => $newPasswordHash,
                        'enable_otp' => $enableOtp,
                        'id' => $userId,
                    ]);

                    // Refresh user data
                    $stmt = $pdo->prepare('SELECT id, name, email, mobile, address, latitude, longitude, profile_picture, password_hash, role, status, is_verified, verified_at, COALESCE(enable_otp, 1) as enable_otp, COALESCE(totp_enabled, 0) as totp_enabled FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Refresh document info
                    $docStmt = $pdo->prepare('SELECT id, file_name, uploaded_at, is_archived FROM user_documents WHERE user_id = :user_id AND document_type = "valid_id" AND is_archived = 0 ORDER BY uploaded_at DESC LIMIT 1');
                    $docStmt->execute(['user_id' => $userId]);
                    $validIdDoc = $docStmt->fetch(PDO::FETCH_ASSOC);
                    $hasValidId = (bool)$validIdDoc;

                    // Update session
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];

                    if ($updatePassword && $hasProfileFields) {
                        $success = 'Profile and password updated successfully.';
                    } elseif ($updatePassword) {
                        $success = 'Password updated successfully.';
                    } elseif ($hasProfilePicture && !$hasProfileFields) {
                        $success = 'Profile picture updated successfully.';
                    } else {
                        $success = 'Profile updated successfully.';
                    }
                }
            }
            
            // Handle Valid ID upload - only allow if user doesn't already have one
            if ($hasValidIdUpload) {
                if ($hasValidId) {
                    $error = ($error ? $error . ' ' : '') . 'You have already uploaded a valid ID document. Please wait for admin verification.';
                } else {
                    require_once __DIR__ . '/../../../../config/secure_documents.php';
                    $result = saveDocumentToSecureStorage($_FILES['doc_valid_id'], $userId, 'valid_id');
                    
                    if ($result['success']) {
                        // Store document in database
                        $docStmt = $pdo->prepare("INSERT INTO user_documents (user_id, document_type, file_path, file_name, file_size) VALUES (?, ?, ?, ?, ?)");
                        $docStmt->execute([
                            $userId,
                            'valid_id',
                            $result['file_path'],
                            basename($result['file_path']),
                            (int)$_FILES['doc_valid_id']['size']
                        ]);
                        
                        // Note: User verification status will be updated by admin after review
                        // We just save the document for now
                        $success = ($success ? $success . ' ' : '') . 'Valid ID uploaded successfully. Your document is pending admin verification. Once verified, you will be able to use auto-approval features.';
                        
                        // Refresh document info
                        $docStmt = $pdo->prepare('SELECT id, file_name, uploaded_at, is_archived FROM user_documents WHERE user_id = :user_id AND document_type = "valid_id" AND is_archived = 0 ORDER BY uploaded_at DESC LIMIT 1');
                        $docStmt->execute(['user_id' => $userId]);
                        $validIdDoc = $docStmt->fetch(PDO::FETCH_ASSOC);
                        $hasValidId = (bool)$validIdDoc;
                    } else {
                        $error = ($error ? $error . ' ' : '') . 'Failed to upload valid ID: ' . ($result['error'] ?? 'Unknown error');
                    }
                }
            }
        } catch (Throwable $e) {
            $error = 'Unable to update your profile at the moment. Please try again.';
        }
    }
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Account</span><span class="sep">/</span><span>Profile</span>
    </div>
    <h1>My Profile</h1>
    <small>Keep your account information up to date and secure.</small>
</div>

<?php if ($error): ?>
    <div class="message error" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:#fdecee;color:#b23030;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php elseif ($success): ?>
    <div class="message success" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:#e3f8ef;color:#0d7a43;">
        <?= htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($user): ?>
    <?php
    $initials = '';
    if (!empty($user['name'])) {
        $parts = preg_split('/\s+/', trim($user['name']));
        $initials = strtoupper(
            mb_substr($parts[0] ?? '', 0, 1) .
            mb_substr(end($parts) ?: '', 0, 1)
        );
    }
    ?>
    
    <div class="booking-wrapper">
        <div style="grid-column: 1 / -1;">
            <section class="booking-card" style="background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(29, 78, 216, 0.05)); border: 2px solid rgba(37, 99, 235, 0.2);">
                <div style="display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap;">
                    <?php 
                    $base = base_path();
                    $profilePicUrl = !empty($user['profile_picture']) ? $base . $user['profile_picture'] : null;
                    ?>
                    <div style="position:relative; flex-shrink:0;">
                        <div style="width:100px; height:100px; border-radius:50%; <?= $profilePicUrl ? 'background-image:url(' . htmlspecialchars($profilePicUrl) . '); background-size:cover; background-position:center;' : 'background:linear-gradient(135deg, #2563eb, #1d4ed8);'; ?> display:flex; align-items:center; justify-content:center; color:#fff; font-size:2.5rem; font-weight:600; box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3); border:3px solid #fff; overflow:hidden;">
                            <?php if (!$profilePicUrl): ?>
                                <?= htmlspecialchars($initials ?: 'LG'); ?>
                            <?php endif; ?>
                        </div>
                        <form method="POST" enctype="multipart/form-data" style="position:absolute; bottom:0; right:0; margin:0; padding:0;">
                            <label for="profile-picture-input" style="width:36px; height:36px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,0.2); transition:all 0.2s; border:3px solid #fff; z-index:10; margin:0; padding:0;" title="Change profile picture" onmouseover="this.style.background='#1d4ed8'; this.style.transform='scale(1.1)';" onmouseout="this.style.background='#2563eb'; this.style.transform='scale(1)';">
                                <span style="font-size:1.1rem;">📷</span>
                                <input type="file" id="profile-picture-input" name="profile_picture" accept="image/*" style="display:none;" onchange="this.form.submit();">
                            </label>
                        </form>
                    </div>
                    <div style="flex:1;">
                        <h2 style="margin:0 0 0.5rem; color:#1b1b1f; font-size:1.75rem; font-weight:700;"><?= htmlspecialchars($user['name'] ?? 'LGU Account Holder'); ?></h2>
                        <p style="margin:0 0 1rem; color:#5b6888; font-size:1.05rem; font-weight:500;"><?= htmlspecialchars($user['email'] ?? 'official@lgu.gov.ph'); ?></p>
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
                            <?php if (!empty($user['role'])): ?>
                                <span class="status-badge <?= strtolower($user['role']); ?>" style="font-size:0.85rem; padding:0.35rem 0.85rem;"><?= htmlspecialchars($user['role']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($user['status'])): ?>
                                <span class="status-badge <?= $user['status'] === 'active' ? 'active' : 'pending'; ?>" style="font-size:0.85rem; padding:0.35rem 0.85rem;"><?= ucfirst(htmlspecialchars($user['status'])); ?></span>
                            <?php endif; ?>
                            <button type="button" onclick="openSecurityModal()" class="btn-primary" style="padding:0.5rem 1rem; font-size:0.85rem; display:inline-flex; align-items:center; gap:0.5rem; margin-left:0.5rem;">
                                <span>🛡️</span>
                                <span>Account Security</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section class="booking-card">
            <h2>Profile Information</h2>
            <form method="POST" class="booking-form" enctype="multipart/form-data" id="profile-form">
                <label class="profile-form-label">
                    <span class="profile-label-text">Full Name</span>
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" name="name" value="<?= htmlspecialchars($user['name']); ?>" required class="profile-input">
                    </div>
                </label>

                <label class="profile-form-label">
                    <span class="profile-label-text">Email Address</span>
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']); ?>" required class="profile-input">
                    </div>
                </label>

                <label class="profile-form-label">
                    <span class="profile-label-text">Mobile Number</span>
                    <div class="input-wrapper">
                        <span class="input-icon">📱</span>
                        <input type="tel" name="mobile" value="<?= htmlspecialchars($user['mobile'] ?? ''); ?>" placeholder="+63 900 000 0000" class="profile-input">
                    </div>
                    <small class="profile-help-text">Your contact number for notifications and account recovery</small>
                </label>

                <label class="profile-form-label">
                    <span class="profile-label-text">Address</span>
                    <div class="input-wrapper">
                        <span class="input-icon">📍</span>
                        <input type="text" name="address" id="profile-address" value="<?= htmlspecialchars($user['address'] ?? ''); ?>" placeholder="e.g., Barangay Culiat, Quezon City" class="profile-input" autocomplete="address-line1">
                    </div>
                    <small class="profile-help-text">Your address helps us recommend nearby facilities. Latitude and longitude update automatically when you type and blur.</small>
                </label>
                
                <label class="profile-form-label">
                    <span class="profile-label-text">Latitude (Optional - for location-based recommendations)</span>
                    <div class="input-wrapper">
                        <span class="input-icon">🌐</span>
                        <input type="number" step="any" name="latitude" id="profile-latitude" value="<?= htmlspecialchars($user['latitude'] ?? ''); ?>" placeholder="14.6760" class="profile-input">
                    </div>
                    <small class="profile-help-text">Auto-filled from address via Google Geocoding, or enter manually. <a href="https://www.google.com/maps" target="_blank">Google Maps</a> (right-click → coordinates)</small>
                </label>
                
                <label class="profile-form-label">
                    <span class="profile-label-text">Longitude (Optional - for location-based recommendations)</span>
                    <div class="input-wrapper">
                        <span class="input-icon">🌐</span>
                        <input type="number" step="any" name="longitude" id="profile-longitude" value="<?= htmlspecialchars($user['longitude'] ?? ''); ?>" placeholder="121.0437" class="profile-input">
                    </div>
                    <small class="profile-help-text">Auto-filled from address.</small>
                </label>
                <div id="profile-geocode-status" class="profile-help-text" style="margin-top:0.25rem; display:none;"></div>
                
                <?php if (!empty($user['latitude']) && !empty($user['longitude'])): ?>
                    <div style="background:#e3f8ef; color:#0d7a43; padding:0.75rem; border-radius:6px; margin-top:0.5rem; font-size:0.9rem;">
                        ✓ Location coordinates saved (<?= htmlspecialchars($user['latitude']); ?>, <?= htmlspecialchars($user['longitude']); ?>)
                    </div>
                <?php endif; ?>

                <div style="margin-top:2rem; padding-top:1.5rem; border-top:2px solid #e1e7f0; display:flex; justify-content:flex-end;">
                    <button class="btn-primary" type="submit" style="padding:0.85rem 2rem; font-size:1rem; font-weight:600;">Save Changes</button>
                </div>
            </form>
        </section>

        <section class="booking-card" id="verification">
            <h2>Account Verification</h2>
            
            <?php 
            $userRole = $user['role'] ?? 'Resident';
            $isPrivilegedRole = in_array($userRole, ['Admin', 'Staff'], true);
            ?>
            
            <?php if ($isPrivilegedRole): ?>
                <!-- Staff/Admin automatic verification notice -->
                <div style="background:#e3f8ef; border:2px solid #0d7a43; border-radius:8px; padding:1rem; margin-bottom:1rem;">
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
                        <span style="font-size:1.5rem;">✅</span>
                        <h3 style="margin:0; color:#0d7a43;">Account Automatically Verified</h3>
                    </div>
                    <p style="margin:0; color:#0d7a43; line-height:1.6;">
                        As a <strong><?= htmlspecialchars($userRole); ?></strong>, your account is automatically verified. You have full access to auto-approval features for facility bookings without needing to upload a valid ID.
                    </p>
                </div>
                
                <!-- Optional ID upload for Staff/Admin -->
                <?php if (!$hasValidId): ?>
                <details style="margin-top:1rem;">
                    <summary style="cursor:pointer; padding:0.75rem; background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; font-weight:600; color:#495057;">
                        📄 Optional: Upload Valid ID (for record keeping)
                    </summary>
                    <form method="POST" class="booking-form" enctype="multipart/form-data" style="margin-top:1rem; padding:1rem; background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px;">
                        <label>
                            <span style="display:block; font-weight:600; margin-bottom:0.5rem; color:#1b1b1f;">Upload Valid ID</span>
                            <input type="file" name="doc_valid_id" accept=".pdf,image/*" style="padding:0.75rem; border:1px solid #ddd; border-radius:6px; width:100%; margin-bottom:0.5rem;">
                            <small style="color:#6c757d; font-size:0.85rem; display:block;">
                                Accepted: PDF, JPG, PNG. Max 5MB. Any government-issued ID is acceptable.
                            </small>
                        </label>
                        
                        <div style="margin-top:1rem;">
                            <button class="btn-primary" type="submit" style="width:100%; padding:0.85rem; font-size:1rem; font-weight:600;">
                                Upload Valid ID
                            </button>
                        </div>
                    </form>
                </details>
                <?php else: ?>
                <div style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:1rem; margin-top:1rem;">
                    <h4 style="margin:0 0 0.5rem; color:#495057; font-size:1rem;">📄 Valid ID on File</h4>
                    <p style="margin:0; color:#6c757d; font-size:0.9rem;">
                        File: <strong><?= htmlspecialchars($validIdDoc['file_name']); ?></strong><br>
                        Uploaded: <?= date('F j, Y g:i A', strtotime($validIdDoc['uploaded_at'])); ?>
                    </p>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- Resident verification (original logic) -->
                <?php if ($user['is_verified']): ?>
                    <div style="background:#e3f8ef; border:2px solid #0d7a43; border-radius:8px; padding:1rem; margin-bottom:1rem;">
                        <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
                            <span style="font-size:1.5rem;">✅</span>
                            <h3 style="margin:0; color:#0d7a43;">Account Verified</h3>
                        </div>
                        <p style="margin:0; color:#0d7a43; line-height:1.6;">
                            Your account has been verified. You can now use auto-approval features for facility bookings.
                            <?php if ($user['verified_at']): ?>
                                Verified on: <?= date('F j, Y g:i A', strtotime($user['verified_at'])); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div style="background:#fff4e5; border:2px solid #ffc107; border-radius:8px; padding:1rem; margin-bottom:1rem;">
                        <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
                            <span style="font-size:1.5rem;">⚠️</span>
                            <h3 style="margin:0; color:#856404;">Account Not Verified</h3>
                        </div>
                        <p style="margin:0; color:#856404; line-height:1.6; margin-bottom:0.75rem;">
                            Your account is active, but you haven't submitted a valid ID yet. To enable <strong>auto-approval features</strong> for facility bookings, please upload a valid government-issued ID below. Once uploaded, an admin will review and verify your account.
                        </p>
                        <p style="margin:0; color:#856404; font-size:0.9rem;">
                            <strong>Note:</strong> You can still make reservations, but they will require manual approval until your account is verified.
                        </p>
                    </div>
                <?php endif; ?>
                
                <?php if ($hasValidId): ?>
                    <div style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:1rem; margin-bottom:1rem;">
                        <h4 style="margin:0 0 0.5rem; color:#495057; font-size:1rem;">Current Valid ID Document</h4>
                        <p style="margin:0; color:#6c757d; font-size:0.9rem;">
                            File: <strong><?= htmlspecialchars($validIdDoc['file_name']); ?></strong><br>
                            Uploaded: <?= date('F j, Y g:i A', strtotime($validIdDoc['uploaded_at'])); ?>
                        </p>
                        <?php if (!$user['is_verified']): ?>
                            <p style="margin:0.5rem 0 0; color:#856404; font-size:0.85rem;">
                                Status: Pending admin verification
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!$hasValidId): ?>
                <form method="POST" class="booking-form" enctype="multipart/form-data">
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:0.5rem; color:#1b1b1f;">Upload Valid ID</span>
                        <input type="file" name="doc_valid_id" accept=".pdf,image/*" style="padding:0.75rem; border:1px solid #ddd; border-radius:6px; width:100%; margin-bottom:0.5rem;">
                        <small style="color:#6c757d; font-size:0.85rem; display:block;">
                            Accepted: PDF, JPG, PNG. Max 5MB. Any government-issued ID (Birth Certificate, Barangay ID, Resident ID, Driver's License, etc.) is acceptable.
                        </small>
                    </label>
                    
                    <div style="margin-top:1rem; padding-top:1rem; border-top:2px solid #e1e7f0;">
                        <button class="btn-primary" type="submit" style="width:100%; padding:0.85rem; font-size:1rem; font-weight:600;">
                            Upload Valid ID
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div style="padding:1rem; background:#e7f3ff; border:2px solid #2196F3; border-radius:8px;">
                    <h4 style="margin:0 0 0.5rem; color:#1976D2; font-size:1rem;">📋 Valid ID Submitted</h4>
                    <p style="margin:0; color:#1976D2; font-size:0.9rem; line-height:1.5;">
                        Your valid ID document has been submitted and is awaiting admin verification. Once verified, you'll be able to use auto-approval features.
                    </p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        
        <!-- Account Security Modal -->
        <div id="securityModal" class="facility-modal">
            <div class="facility-modal-backdrop" onclick="closeSecurityModal()"></div>
            <div class="facility-modal-dialog" style="max-width: 600px;">
                <div class="facility-modal-content">
                    <div class="facility-modal-header">
                        <h2>🛡️ Account Security</h2>
                        <button type="button" class="facility-modal-close" onclick="closeSecurityModal()" aria-label="Close">×</button>
                    </div>
                    <div class="facility-modal-body">
                        <p style="color:#5b6888; font-size:0.9rem; margin-bottom:1.5rem; line-height:1.6;">
                            Manage your account security settings, change your password, and export your data.
                        </p>
                        
                        <!-- OTP Preference Toggle -->
                        <div id="otp-toggle-container" style="margin-bottom:1.5rem; padding:1rem; background:#f8f9fa; border-radius:8px; border:1px solid #e1e7f0;">
                            <div style="display:flex; align-items:flex-start; gap:1rem;">
                                <div style="flex:1;">
                                    <label style="display:block; font-weight:600; color:#1b1b1f; margin-bottom:0.25rem; font-size:0.95rem;">
                                        Two-Factor Authentication (OTP)
                                    </label>
                                    <p id="otp-status-text" style="color:#5b6888; font-size:0.85rem; margin:0; line-height:1.5;">
                                        <?php if ($user['enable_otp'] ?? true): ?>
                                            OTP is currently <strong>enabled</strong>. You'll receive a code via email each time you log in.
                                        <?php else: ?>
                                            OTP is currently <strong>disabled</strong>. Your account will log in directly with just your password.
                                        <?php endif; ?>
                                    </p>
                                    <div id="otp-status-message" style="margin-top:0.5rem; font-size:0.85rem; display:none;"></div>
                                </div>
                                <label style="position:relative; display:inline-block; width:48px; height:26px; cursor:pointer; flex-shrink:0;">
                                    <input 
                                        type="checkbox" 
                                        id="otp-toggle-checkbox" 
                                        value="1" 
                                        <?= ($user['enable_otp'] ?? true) ? 'checked' : ''; ?> 
                                        onchange="toggleOTPPreference(this);" 
                                        style="opacity:0; width:0; height:0;"
                                    >
                                    <span id="otp-toggle-switch" style="position:absolute; top:0; left:0; right:0; bottom:0; background-color:<?= ($user['enable_otp'] ?? true) ? '#2563eb' : '#ccc'; ?>; border-radius:26px; transition:background-color 0.3s;">
                                        <span id="otp-toggle-knob" style="position:absolute; content:''; height:20px; width:20px; left:3px; bottom:3px; background-color:white; border-radius:50%; transition:transform 0.3s; transform:translateX(<?= ($user['enable_otp'] ?? true) ? '22px' : '0'; ?>); box-shadow:0 2px 4px rgba(0,0,0,0.2);"></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <?php if (in_array($user['role'] ?? '', ['Admin', 'Staff'], true)): ?>
                        <div class="totp-section" style="margin-bottom:1.5rem; padding:1rem; background:#f8f9fa; border-radius:8px; border:1px solid #e1e7f0;">
                            <label style="display:block; font-weight:600; color:#1b1b1f; margin-bottom:0.25rem;">Google Authenticator</label>
                            <?php if ($user['totp_enabled'] ?? 0): ?>
                                <p style="color:#5b6888; font-size:0.85rem; margin:0 0 0.75rem;">You can enter the 6-digit code from your authenticator app at login instead of the email OTP.</p>
                                <form method="POST" style="margin:0;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="totp_disable" value="1">
                                    <button type="submit" class="btn-outline" style="padding:0.5rem 1rem;">Disable Authenticator</button>
                                </form>
                            <?php elseif ($totpQrUri): ?>
                                <p style="color:#5b6888; font-size:0.85rem; margin:0 0 0.75rem;">Scan the QR code with your app, then enter the 6-digit code below to verify.</p>
                                <div id="totp-setup-modal" style="display:block;">
                                    <img src="<?= htmlspecialchars($totpQrUri); ?>" alt="TOTP QR" style="display:block; margin:0.75rem 0; max-width:200px;">
                                    <p style="font-size:0.8rem; color:#6b7280; margin:0.5rem 0;">Or enter manually: <code style="background:#eee; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($totpSecret); ?></code></p>
                                    <form method="POST" style="margin-top:0.75rem;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="totp_verify" value="1">
                                        <input type="text" name="totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" style="width:8em; padding:0.5rem; font-size:1.1rem; letter-spacing:0.2em; text-align:center;" required>
                                        <button type="submit" class="btn-primary" style="margin-left:0.5rem; padding:0.5rem 1rem;">Verify and enable</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <p style="color:#5b6888; font-size:0.85rem; margin:0 0 0.75rem;">Use an authenticator app (e.g. Google Authenticator) for 2FA. At login you can enter the app code instead of the email OTP.</p>
                                <form method="POST" style="margin:0;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="totp_enable_request" value="1">
                                    <button type="submit" class="btn-primary" style="padding:0.5rem 1rem;">Enable Google Authenticator</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display:flex; flex-direction:column; gap:1rem;">
                            <button type="button" class="btn-primary" onclick="closeSecurityModal(); setTimeout(openPasswordModal, 300);" style="width:100%; padding:0.85rem; font-size:1rem; font-weight:600; display:inline-flex; align-items:center; justify-content:center; gap:0.5rem;">
                                <span>🔑</span>
                                <span>Change Password</span>
                            </button>
                            
                            <button type="button" class="btn-outline" onclick="closeSecurityModal(); setTimeout(openExportModal, 300);" style="width:100%; padding:0.85rem; font-size:1rem; font-weight:600; display:inline-flex; align-items:center; justify-content:center; gap:0.5rem;">
                                <span>📥</span>
                                <span>Data Export</span>
                            </button>
                        </div>

                        <div style="margin-top:2rem; padding-top:1.5rem; border-top:2px solid #e1e7f0;">
                            <h3 style="font-size:0.95rem; color:#1b1b1f; margin-bottom:0.75rem;">Security Tips</h3>
                            <ul class="audit-list" style="margin:0;">
                                <li style="color:#5b6888; font-size:0.85rem; line-height:1.6;"><strong>OTP Security:</strong> We recommend keeping OTP enabled for better account protection.</li>
                                <li style="color:#5b6888; font-size:0.85rem; line-height:1.6;"><strong>Password tip:</strong> Avoid reusing passwords from other systems.</li>
                                <li style="color:#5b6888; font-size:0.85rem; line-height:1.6;"><strong>Account access:</strong> Contact the LGU IT office if you suspect unauthorized activity.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Change Password Modal -->
        <div id="passwordModal" class="facility-modal">
            <div class="facility-modal-backdrop" onclick="closePasswordModal()"></div>
            <div class="facility-modal-dialog">
                <div class="facility-modal-content">
                    <div class="facility-modal-header">
                        <h2>Change Password</h2>
                        <button type="button" class="facility-modal-close" onclick="closePasswordModal()" aria-label="Close">×</button>
                    </div>
                    <div class="facility-modal-body">
                        <p style="color:#5b6888; font-size:0.9rem; margin-bottom:1.5rem; line-height:1.6;">
                            Update your password regularly to help keep your account secure. Use a strong password with at least 8 characters.
                        </p>
                        <form method="POST" class="booking-form" id="passwordForm">
                            <label class="profile-form-label">
                                <span class="profile-label-text">Current Password</span>
                                <div class="input-wrapper">
                                    <span class="input-icon">🔒</span>
                                    <input type="password" name="current_password" placeholder="Enter current password" class="profile-input" required>
                                </div>
                            </label>
                            <label class="profile-form-label">
                                <span class="profile-label-text">New Password</span>
                                <div class="input-wrapper">
                                    <span class="input-icon">🔑</span>
                                    <input type="password" name="new_password" placeholder="Enter new password (min 8 characters)" class="profile-input" required>
                                </div>
                            </label>
                            <label class="profile-form-label">
                                <span class="profile-label-text">Confirm New Password</span>
                                <div class="input-wrapper">
                                    <span class="input-icon">✅</span>
                                    <input type="password" name="confirm_password" placeholder="Re-enter new password" class="profile-input" required>
                                </div>
                            </label>
                            <div style="display:flex; gap:0.75rem; margin-top:1.5rem; padding-top:1.5rem; border-top:2px solid #e1e7f0;">
                                <button class="btn-primary" type="submit" style="flex:1; padding:0.85rem; font-size:1rem; font-weight:600;">Update Password</button>
                                <button class="btn-outline" type="button" onclick="closePasswordModal()" style="flex:1; padding:0.85rem; font-size:1rem; font-weight:600;">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Data Export Modal -->
        <div id="exportModal" class="facility-modal">
            <div class="facility-modal-backdrop" onclick="closeExportModal()"></div>
            <div class="facility-modal-dialog" style="max-width:700px;">
                <div class="facility-modal-content">
                    <div class="facility-modal-header">
                        <h2>Data Export</h2>
                        <button type="button" class="facility-modal-close" onclick="closeExportModal()" aria-label="Close">×</button>
                    </div>
                    <div class="facility-modal-body">
                        <p style="color:#5b6888; font-size:0.9rem; margin-bottom:1.5rem; line-height:1.6;">
                            Under the Data Privacy Act, you have the right to request a copy of your personal data. Export files expire after 7 days for security.
                        </p>
                        <form method="POST" id="exportForm" style="display:flex; flex-direction:column; gap:1rem;">
                            <input type="hidden" name="export_data" value="1">
                            <label class="profile-form-label">
                                <span class="profile-label-text">Export Type</span>
                                <select name="export_type" required style="width:100%; padding:0.75rem; border:2px solid #e1e7f0; border-radius:6px; font-size:0.95rem; font-family:inherit;">
                                    <option value="full">Full Data Export (All Information)</option>
                                    <option value="profile">Profile Information Only</option>
                                    <option value="reservations">Reservations Only</option>
                                    <option value="documents">Documents List Only</option>
                                </select>
                            </label>
                            <div style="display:flex; gap:0.75rem; margin-top:0.5rem; padding-top:1rem; border-top:2px solid #e1e7f0;">
                                <button type="submit" class="btn-primary" style="flex:1; padding:0.85rem; font-size:1rem; font-weight:600;">Generate Export</button>
                                <button type="button" class="btn-outline" onclick="closeExportModal()" style="flex:1; padding:0.85rem; font-size:1rem; font-weight:600;">Cancel</button>
                            </div>
                        </form>
                        
                        <?php if (!empty($exportHistory)): ?>
                            <div style="margin-top:2rem; padding-top:1.5rem; border-top:2px solid #e1e7f0;">
                                <h4 style="margin:0 0 0.75rem; color:#1b1b1f; font-size:1rem; font-weight:600;">Recent Exports</h4>
                                <div style="display:flex; flex-direction:column; gap:0.5rem; max-height:300px; overflow-y:auto;">
                                    <?php foreach (array_slice($exportHistory, 0, 10) as $export): ?>
                                        <?php
                                        $isExpired = strtotime($export['expires_at']) < time();
                                        $fileExists = file_exists(app_root_path() . '/' . $export['file_path']);
                                        $canDownload = !$isExpired && $fileExists;
                                        ?>
                                        <div style="padding:0.75rem; background:#f8f9fa; border-radius:6px; font-size:0.85rem;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                                                <div>
                                                    <strong><?= ucfirst(htmlspecialchars($export['export_type'])); ?></strong>
                                                    <span style="color:#5b6888; margin-left:0.5rem;">
                                                        <?= date('M d, Y H:i', strtotime($export['created_at'])); ?>
                                                    </span>
                                                </div>
                                                <div style="display:flex; gap:0.5rem; align-items:center;">
                                                    <?php if ($canDownload): ?>
                                                        <a href="<?= base_path() . '/dashboard/export-pdf?id=' . $export['id']; ?>" 
                                                           target="_blank"
                                                           class="btn-primary" 
                                                           style="padding:0.4rem 0.75rem; font-size:0.85rem; text-decoration:none;">📄 View PDF</a>
                                                        <a href="<?= base_path() . '/resources/views/pages/dashboard/download_export.php?id=' . $export['id']; ?>" 
                                                           class="btn-outline" 
                                                           style="padding:0.4rem 0.75rem; font-size:0.85rem; text-decoration:none;">📥 JSON</a>
                                                    <?php else: ?>
                                                        <span style="color:#8b95b5; font-size:0.8rem;">
                                                            <?= $isExpired ? 'Expired' : 'Unavailable'; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if (!$isExpired): ?>
                                                <div style="margin-top:0.25rem; color:#5b6888; font-size:0.8rem;">
                                                    Expires: <?= date('M d, Y', strtotime($export['expires_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Account Deactivation Section (Residents only) -->
        <?php if ($user && !in_array($user['role'], ['Admin', 'Staff'])): ?>
        <section class="booking-card" style="grid-column: 1 / -1; border: 2px solid #fee2e2; background: linear-gradient(135deg, rgba(254, 226, 226, 0.3), rgba(254, 242, 242, 0.5));">
            <h2 style="color: #991b1b; display: flex; align-items: center; gap: 0.5rem;">
                <span>⚠️</span>
                <span>Account Deactivation</span>
            </h2>
            <div style="background: #fff; padding: 1.25rem; border-radius: 8px; margin-top: 1rem; border: 1px solid #fecaca;">
                <p style="color: #7f1d1d; line-height: 1.7; margin: 0 0 1rem; font-size: 0.95rem;">
                    <strong>Important:</strong> Deactivating your account will immediately disable your ability to log in and access the system. 
                    However, in compliance with LGU record-keeping requirements and the Data Privacy Act:
                </p>
                <ul style="color: #7f1d1d; line-height: 1.8; margin: 0 0 1.25rem 1.25rem; font-size: 0.9rem; padding-left: 0.75rem;">
                    <li>Your reservation history will be <strong>retained</strong> as public records</li>
                    <li>Your uploaded documents (IDs, certificates) will be <strong>restricted from your access</strong> but retained for audit/compliance purposes</li>
                    <li>Your audit trail entries will be <strong>preserved</strong> for accountability</li>
                    <li>Personally identifiable information will be <strong>minimized</strong> but not completely deleted</li>
                    <li>Only authorized LGU administrators will have access to your data after deactivation</li>
                </ul>
                <p style="color: #991b1b; font-weight: 600; margin: 0 0 1rem; font-size: 0.95rem;">
                    This action cannot be undone by you. To restore access, contact the LGU IT office.
                </p>
                <form method="POST" id="deactivate-form" onsubmit="return confirmDeactivation(event);">
                    <input type="hidden" name="deactivate_account" value="1">
                    <input type="hidden" name="csrf_token" value="<?= bin2hex(random_bytes(32)); ?>">
                    <label style="display: block; margin-bottom: 0.75rem;">
                        <span style="display: block; font-weight: 600; color: #7f1d1d; margin-bottom: 0.5rem; font-size: 0.9rem;">
                            Reason for Deactivation (Optional)
                        </span>
                        <textarea 
                            name="deactivation_reason" 
                            rows="3" 
                            placeholder="Please let us know why you're deactivating your account (optional, helps us improve our services)"
                            style="width: 100%; padding: 0.75rem; border: 2px solid #fecaca; border-radius: 6px; font-family: inherit; font-size: 0.9rem; resize: vertical;"
                        ></textarea>
                    </label>
                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                        <button 
                            type="submit" 
                            class="btn-outline" 
                            style="padding: 0.85rem 1.5rem; font-size: 0.95rem; font-weight: 600; border-color: #dc2626; color: #dc2626; background: #fff;"
                            onmouseover="this.style.background='#fee2e2';" 
                            onmouseout="this.style.background='#fff';"
                        >
                            Request Account Deactivation
                        </button>
                    </div>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
// OTP Toggle Handler - AJAX update without page refresh
function toggleOTPPreference(checkbox) {
    const isEnabled = checkbox.checked;
    const toggleSwitch = document.getElementById('otp-toggle-switch');
    const toggleKnob = document.getElementById('otp-toggle-knob');
    const statusText = document.getElementById('otp-status-text');
    const statusMessage = document.getElementById('otp-status-message');
    
    // Immediately update UI for instant feedback
    if (isEnabled) {
        toggleSwitch.style.backgroundColor = '#2563eb';
        toggleKnob.style.transform = 'translateX(22px)';
        statusText.innerHTML = 'OTP is currently <strong>enabled</strong>. You\'ll receive a code via email each time you log in.';
    } else {
        toggleSwitch.style.backgroundColor = '#ccc';
        toggleKnob.style.transform = 'translateX(0)';
        statusText.innerHTML = 'OTP is currently <strong>disabled</strong>. Your account will log in directly with just your password.';
    }
    
    // Disable checkbox during request
    checkbox.disabled = true;
    statusMessage.style.display = 'none';
    
    // Prepare form data
    const formData = new FormData();
    formData.append('enable_otp', isEnabled ? '1' : '0');
    formData.append('<?= CSRF_TOKEN_NAME; ?>', '<?= csrf_token(); ?>');
    
    // Send AJAX request
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        // Re-enable checkbox
        checkbox.disabled = false;
        
        // Show success message
        statusMessage.style.display = 'block';
        statusMessage.style.color = '#0d7a43';
        statusMessage.style.padding = '0.5rem';
        statusMessage.style.backgroundColor = '#e3f8ef';
        statusMessage.style.borderRadius = '4px';
        statusMessage.textContent = isEnabled 
            ? '✓ OTP has been enabled. You will receive a code via email on each login.' 
            : '✓ OTP has been disabled. You can now log in with just your password.';
        
        // Hide message after 3 seconds
        setTimeout(() => {
            statusMessage.style.display = 'none';
        }, 3000);
    })
    .catch(error => {
        // Revert UI on error
        checkbox.checked = !isEnabled;
        if (isEnabled) {
            toggleSwitch.style.backgroundColor = '#ccc';
            toggleKnob.style.transform = 'translateX(0)';
            statusText.innerHTML = 'OTP is currently <strong>disabled</strong>. Your account will log in directly with just your password.';
        } else {
            toggleSwitch.style.backgroundColor = '#2563eb';
            toggleKnob.style.transform = 'translateX(22px)';
            statusText.innerHTML = 'OTP is currently <strong>enabled</strong>. You\'ll receive a code via email each time you log in.';
        }
        checkbox.disabled = false;
        
        // Show error message
        statusMessage.style.display = 'block';
        statusMessage.style.color = '#b23030';
        statusMessage.style.padding = '0.5rem';
        statusMessage.style.backgroundColor = '#fdecee';
        statusMessage.style.borderRadius = '4px';
        statusMessage.textContent = '✗ Failed to update OTP preference. Please try again.';
        
        // Hide error message after 5 seconds
        setTimeout(() => {
            statusMessage.style.display = 'none';
        }, 5000);
    });
}

function openPasswordModal() {
    const modal = document.getElementById('passwordModal');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closePasswordModal() {
    const modal = document.getElementById('passwordModal');
    modal.classList.remove('open');
    document.body.style.overflow = '';
    // Reset form
    document.getElementById('passwordForm').reset();
}

function openSecurityModal() {
    const modal = document.getElementById('securityModal');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeSecurityModal() {
    const modal = document.getElementById('securityModal');
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

function openExportModal() {
    const modal = document.getElementById('exportModal');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeExportModal() {
    const modal = document.getElementById('exportModal');
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePasswordModal();
        closeSecurityModal();
        closeExportModal();
    }
});

function confirmDeactivation(event) {
    event.preventDefault();
    
    const form = document.getElementById('deactivate-form');
    const reason = form.querySelector('[name="deactivation_reason"]').value.trim();
    
    const confirmMsg = '⚠️ ACCOUNT DEACTIVATION CONFIRMATION\n\n' +
        'Are you sure you want to deactivate your account?\n\n' +
        'This will:\n' +
        '• Immediately disable your login access\n' +
        '• Restrict access to your documents (retained for compliance)\n' +
        '• Preserve your reservation history and audit logs\n\n' +
        'To restore access, you must contact the LGU IT office.\n\n' +
        'This action cannot be undone by you.\n\n' +
        'Type "DEACTIVATE" (in all caps) to confirm:';
    
    const userInput = prompt(confirmMsg);
    
    if (userInput === 'DEACTIVATE') {
        // Double confirmation
        const finalConfirm = confirm(
            'Final confirmation: This will immediately deactivate your account and log you out. Continue?'
        );
        
        if (finalConfirm) {
            form.submit();
        }
    } else if (userInput !== null) {
        alert('Confirmation text did not match. Account deactivation cancelled.');
    }
    
    return false;
}

(function() {
    const base = (typeof window !== 'undefined' && window.APP_BASE_PATH) ? window.APP_BASE_PATH : '';
    const addressEl = document.getElementById('profile-address');
    const latEl = document.getElementById('profile-latitude');
    const lngEl = document.getElementById('profile-longitude');
    const statusEl = document.getElementById('profile-geocode-status');
    if (!addressEl || !latEl || !lngEl) return;

    let geocodeTimer = null;
    function showGeocodeStatus(msg, isError) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.display = msg ? 'block' : 'none';
        statusEl.style.color = isError ? '#c00' : '#0d7a43';
    }

    function fetchGeocode() {
        const addr = (addressEl.value || '').trim();
        if (addr.length < 5) {
            showGeocodeStatus('', false);
            return;
        }
        showGeocodeStatus('Looking up coordinates…', false);
        const form = new URLSearchParams();
        form.append('address', addr);
        fetch(base + '/resources/views/pages/dashboard/geocode_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form
        })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.lat != null && data.lng != null) {
                    latEl.value = data.lat;
                    lngEl.value = data.lng;
                    showGeocodeStatus('✓ Coordinates updated from address', false);
                    setTimeout(function() { showGeocodeStatus('', false); }, 3000);
                } else {
                    showGeocodeStatus(data.error || 'Could not find coordinates for this address', true);
                }
            })
            .catch(function() {
                showGeocodeStatus('Geocoding unavailable. Enter coordinates manually.', true);
            });
    }

    addressEl.addEventListener('blur', fetchGeocode);
    addressEl.addEventListener('input', function() {
        if (geocodeTimer) clearTimeout(geocodeTimer);
        geocodeTimer = setTimeout(fetchGeocode, 800);
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
