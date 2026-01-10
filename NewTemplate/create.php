<?php
session_start();
require __DIR__ . '/db.php';

// Notification system
function setNotification($type, $message) {
    $_SESSION['notification'] = [
        'type' => $type, // success, warning, error, info
        'message' => $message
    ];
}

function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $type = $_SESSION['notification']['type'];
        $message = htmlspecialchars($_SESSION['notification']['message']);
        $icon = ($type === 'success') ? '✔️' : (($type === 'error') ? '❌' : (($type === 'warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$type}' id='notifPopup'>
                <span class='notif-icon'>{$icon}</span>
                <span class='notif-message'>{$message}</span>
                <button class='notif-close' onclick=\"closeNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif() {
                var n = document.getElementById('notifPopup'); 
                if(n) n.style.opacity='0';
                setTimeout(()=>{if(n)n.remove();}, 400);
            }
            setTimeout(closeNotif, 4500);
        </script>";
    }
}

// Password generator function
function generateTempPassword($length = 10) {
    // Mix of upper, lower, digit, and special
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $charsLength = strlen($chars);
    $pass = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, $charsLength - 1)];
    }
    return $pass;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_account'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');

    // Always generate a random temp password
    $tempPassword = generateTempPassword();

    // Validation
    if (empty($firstName) || empty($lastName) || empty($email) || empty($role)) {
        setNotification('error', 'All fields are required.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setNotification('error', 'Please enter a valid email address.');
    } else {
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id FROM employees WHERE email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            setNotification('error', 'Email already exists in the system.');
        } else {
            // Hash the password
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            // Insert into database
            $stmt = $conn->prepare("INSERT INTO employees (first_name, last_name, email, role, password) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $firstName, $lastName, $email, $role, $hashedPassword);
            
            if ($stmt->execute()) {
                setNotification('success', 'Employee account created successfully! Temporary password: ' . htmlspecialchars($tempPassword));
                // Clear form data
                $firstName = $lastName = $email = $role = '';
            } else {
                setNotification('error', 'Error creating account: ' . $conn->error);
            }
            $stmt->close();
        }
        $checkStmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account | LGU Portal</title>
<link rel="stylesheet" href="style - Copy.css">
<style>
body {
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    height: 100vh;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
}

body::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    backdrop-filter: blur(6px);
    background: rgba(0, 0, 0, 0.35);
    z-index: 0;
}

.nav {
    position: relative;
    z-index: 1;
    /* Ensure header height is defined for offset calculations */
    height: 80px;
    display: flex;
    align-items: center;
    background: transparent;
}

.wrapper {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    box-sizing: border-box;
    min-height: 0;
    padding: 30px 20px 20px 20px;
    position: relative;
    z-index: 1;
    margin-top: 0; /* Fix the space between header and wrapper, remove 80px margin */
    min-height: calc(100vh - 80px);
}

@media (max-width: 600px) {
    .card {
        padding: 22px 8px;
    }
    .wrapper {
        margin-top: 0;
    }
}

/* Card styling */
.card {
    width: 100%;
    max-width: 500px;
<<<<<<< HEAD
=======
    background: rgba(231, 222, 222, 0.95); /* soft white with opacity */
>>>>>>> 2e6a3e8753bb88f4c00d03ffd496b38197156c03
    backdrop-filter: blur(8px);
    border-radius: 20px;
    padding: 30px 25px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
    box-sizing: border-box;
    animation: fadeIn 0.5s ease-in-out;
    /* Instead of overflow-y: auto, allow card to grow naturally and wrapper to scroll */
    margin-top: 5px; /* Ensure card always sits just below header */
}

/* To allow main section to scroll, but keep header visible */
html, body {
    height: 100%;
}
body {
    min-height: 100vh;
}

.wrapper {
    /* overlay scroll if needed, so the card never overlaps header */
    overflow-y: auto;
    max-height: calc(100vh - 80px);
}

/* Custom scrollbar for card */
.card::-webkit-scrollbar {
    width: 8px;
}

.card::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.05);
    border-radius: 4px;
}

.card::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 4px;
}

.card::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.3);
}

/* LOGO AREA */
.site-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #fff;
    font-weight: 600;
    font-size: 18px;
}

/* LOGO IMAGE */
.site-logo img {
    width: 40px;
    height: auto;
    border-radius: 8px;
}

/* NAV LINKS */
.nav-links {
    display: flex;
    align-items: center;
}

.nav-links a {
    margin-left: 25px;
    text-decoration: none;
    color: #fff;
    opacity: 0.85;
    font-weight: 500;
    padding: 8px 14px;
    border-radius: 10px;
    transition: 0.25s ease;
}

.nav-links a:hover,
.nav-links a.active {
    opacity: 1;
    font-weight: 600;
}

/* Notification popup styles */
.notif-popup {
    position: fixed;
    top: 40px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 260px;
    max-width: 90vw;
    background: #fff;
    color: #11294d;
    padding: 18px 32px 18px 22px;
    border-radius: 16px;
    box-shadow: 0 7px 30px rgba(44,66,133,0.19);
    display: flex;
    align-items: center;
    gap: 14px;
    z-index: 9999;
    font-size: 16.5px;
    opacity: 1;
    transition: opacity 0.4s cubic-bezier(.4,.9,.1,1.1);
    border-left: 6.5px solid #2c64d7;
    font-family: 'Poppins', Arial, sans-serif;
}
.notif-success { border-color: #10b759 !important; }
.notif-warning { border-color: #fdc13f !important; }
.notif-error { border-color: #de3f4a !important; color: #b0212a !important; }
.notif-info { border-color: #2c64d7 !important; }
.notif-icon {
    font-size: 23px;
    margin-right: 2px;
}
.notif-message {
    flex: 1;
    font-weight: 500;
    letter-spacing: 0.01em;
}
.notif-close {
    background: none;
    border: none;
    font-size: 21px;
    color: #aaa;
    cursor: pointer;
    margin-left: 12px;
    padding: 0;
    transition: color 0.2s;
}
.notif-close:hover { color: #536ae2; }

/* Role Select Styling */
.role-select {
    width: 100%;
    padding: 10px 38px 10px 12px;
    border-radius: 10px;
    border: none;
    background: rgba(255,255,255,0.7);
    outline: none;
    font-size: 14px;
    appearance: none;
    background-image: url('data:image/svg+xml;charset=UTF-8,<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12"><path fill="%23333" d="M6 9L1 4h10z"/></svg>');
    background-repeat: no-repeat;
    background-position: right 12px center;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
}

/* Name fields side by side */
.name-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}

.name-row .input-box {
    margin-bottom: 0;
}
</style>
</head>

<body>

<?php showNotification(); ?>

<header class="nav">
    <div class="site-logo">
        <img src="logocityhall.png" alt="LGU Logo">
        <span>Local Government Unit Portal</span>
    </div>

    <div class="nav-links">
        <a href="#" class="active">Home</a>
    </div>
</header>

<div class="wrapper">
    <div class="card">

        <img src="logocityhall.png" class="icon-top">

        <h2 class="title">Create Employee Account</h2>
        <p class="subtitle">Register a new employee to access the LGU maintenance system.</p>

        <form method="POST" action="">
            <div class="name-row">
                <div class="input-box">
                    <label>First Name</label>
                    <input type="text" name="first_name" placeholder="Juan" value="<?= htmlspecialchars($firstName ?? '') ?>" required>
                    <span class="icon">👤</span>
                </div>

                <div class="input-box">
                    <label>Last Name</label>
                    <input type="text" name="last_name" placeholder="Dela Cruz" value="<?= htmlspecialchars($lastName ?? '') ?>" required>
                    <span class="icon">👤</span>
                </div>
            </div>

            <div class="input-box">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@lgu.gov.ph" value="<?= htmlspecialchars($email ?? '') ?>" required>
                <span class="icon">📧</span>
            </div>

            <div class="input-box">
                <label>Role</label>
                <select name="role" required class="role-select">
                    <option value="">Select Role</option>
                    <option value="Manager" <?= (isset($role) && $role === 'Manager') ? 'selected' : '' ?>>Manager</option>
                    <option value="Engineer" <?= (isset($role) && $role === 'Engineer') ? 'selected' : '' ?>>Engineer</option>
                    <option value="Office Staff" <?= (isset($role) && $role === 'Office Staff') ? 'selected' : '' ?>>Office Staff</option>
                    <option value="Super Admin" <?= (isset($role) && $role === 'Super Admin') ? 'selected' : '' ?>>Super Admin</option>
                </select>
                <span class="icon">👔</span>
            </div>

            <div class="input-box">
                <label>Temporary Password</label>
                <input type="text" name="temp_password" placeholder="Will be generated automatically" value="<?= isset($tempPassword) ? htmlspecialchars($tempPassword) : '' ?>" readonly style="background-color:#f2f2f2; color:#666;">
                <span class="icon">🔒</span>
                <span class="small-text" style="color:#555;font-size:12px;display:block;margin-top:4px;">Temporary password will be generated and shown after creation.</span>
            </div>

            <button type="submit" name="create_account" class="btn-primary">Create Account</button>

            <p class="small-text">
                Already registered?
                <a href="login.php" class="link">Sign In</a>
            </p>

        </form>
    </div>
</div>

</body>
</html>
