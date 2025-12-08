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

$pdo = db();
$pageTitle = 'Profile | LGU Facilities Reservation';
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: /resources/views/pages/auth/login.php');
    exit;
}

$success = '';
$error = '';

// Load current user data
$stmt = $pdo->prepare('SELECT id, name, email, mobile, address, latitude, longitude, profile_picture, password_hash, role, status FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $error = 'Unable to load your profile information.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    // Check if this is a password-only update (password form) or profile update
    $hasPasswordFields = !empty($_POST['current_password']) || !empty($_POST['new_password']) || !empty($_POST['confirm_password']);
    $hasProfileFields = isset($_POST['name']) && isset($_POST['email']);
    $hasProfilePicture = !empty($_FILES['profile_picture']['name']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK;
    
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
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                // Set secure file permissions
                @chmod($targetPath, 0644);
                
                // Delete old profile picture if exists
                if ($profilePicture && file_exists(__DIR__ . '/../../../../public' . $profilePicture)) {
                    @unlink(__DIR__ . '/../../../../public' . $profilePicture);
                }
                $profilePicture = '/public/uploads/profile_pictures/' . $fileName;
            } else {
                $error = 'Failed to upload profile picture. Please try again.';
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
                    $updateStmt = $pdo->prepare(
                        'UPDATE users 
                         SET name = :name, email = :email, mobile = :mobile, address = :address, latitude = :latitude, longitude = :longitude, profile_picture = :profile_picture, password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP 
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
                        'id' => $userId,
                    ]);

                    // Refresh user data
                    $stmt->execute(['id' => $userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

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
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                            <?php if (!empty($user['role'])): ?>
                                <span class="status-badge <?= strtolower($user['role']); ?>" style="font-size:0.85rem; padding:0.35rem 0.85rem;"><?= htmlspecialchars($user['role']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($user['status'])): ?>
                                <span class="status-badge <?= $user['status'] === 'active' ? 'active' : 'pending'; ?>" style="font-size:0.85rem; padding:0.35rem 0.85rem;"><?= ucfirst(htmlspecialchars($user['status'])); ?></span>
                            <?php endif; ?>
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
                        <input type="text" name="address" value="<?= htmlspecialchars($user['address'] ?? ''); ?>" placeholder="e.g., Barangay Culiat, Quezon City" class="profile-input">
                    </div>
                    <small class="profile-help-text">Your address helps us recommend nearby facilities.</small>
                </label>
                
                <label class="profile-form-label">
                    <span class="profile-label-text">Latitude (Optional - for location-based recommendations)</span>
                    <div class="input-wrapper">
                        <span class="input-icon">🌐</span>
                        <input type="number" step="any" name="latitude" value="<?= htmlspecialchars($user['latitude'] ?? ''); ?>" placeholder="14.6760" class="profile-input">
                    </div>
                    <small class="profile-help-text">Will be auto-filled if Google Maps API is configured, or enter manually. Find coordinates: <a href="https://www.google.com/maps" target="_blank">Google Maps</a> (right-click location → coordinates)</small>
                </label>
                
                <label class="profile-form-label">
                    <span class="profile-label-text">Longitude (Optional - for location-based recommendations)</span>
                    <div class="input-wrapper">
                        <span class="input-icon">🌐</span>
                        <input type="number" step="any" name="longitude" value="<?= htmlspecialchars($user['longitude'] ?? ''); ?>" placeholder="121.0437" class="profile-input">
                    </div>
                    <small class="profile-help-text">Will be auto-filled if Google Maps API is configured, or enter manually.</small>
                </label>
                
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

        <aside class="booking-card">
            <h2>Change Password</h2>
            <p style="color:#5b6888; font-size:0.9rem; margin-bottom:1.5rem; line-height:1.6;">
                Update your password regularly to help keep your account secure. Use a strong password with at least 8 characters.
            </p>
            <form method="POST" class="booking-form">
                <label>
                    Current Password
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" name="current_password" placeholder="Enter current password" class="profile-input">
                    </div>
                </label>
                <label>
                    New Password
                    <div class="input-wrapper">
                        <span class="input-icon">🔑</span>
                        <input type="password" name="new_password" placeholder="Enter new password (min 8 characters)" class="profile-input">
                    </div>
                </label>
                <label>
                    Confirm New Password
                    <div class="input-wrapper">
                        <span class="input-icon">✅</span>
                        <input type="password" name="confirm_password" placeholder="Re-enter new password" class="profile-input">
                    </div>
                </label>

                <div style="margin-top:1.5rem; padding-top:1.5rem; border-top:2px solid #e1e7f0;">
                    <button class="btn-outline" type="submit" style="width:100%; padding:0.85rem; font-size:1rem; font-weight:600;">Update Password</button>
                </div>
            </form>

            <div style="margin-top:2rem; padding-top:1.5rem; border-top:2px solid #e1e7f0;">
                <h3 style="font-size:0.95rem; color:#1b1b1f; margin-bottom:0.75rem;">Security Tips</h3>
                <ul class="audit-list" style="margin:0;">
                    <li style="color:#5b6888; font-size:0.85rem; line-height:1.6;"><strong>Password tip:</strong> Avoid reusing passwords from other systems.</li>
                    <li style="color:#5b6888; font-size:0.85rem; line-height:1.6;"><strong>Account access:</strong> Contact the LGU IT office if you suspect unauthorized activity.</li>
                </ul>
            </div>
        </aside>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
