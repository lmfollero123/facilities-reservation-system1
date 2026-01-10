<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/db.php'; // Make sure your db.php connects $conn
require __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../vendor/PHPMailer/Exception.php';

session_start();

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

// Reset OTP if user reloads
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
}

// OTP verification
if (isset($_POST['otp_submit'])) {
    $entered_otp = trim($_POST['otp']);
    $current_time = time();

    // Initialize or get current attempts
    if (!isset($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = 0;
    }

    // Check if OTP and time are set and valid
    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_time'])) {
        setNotification('error', 'OTP expired or not generated. Please log in again.');
        unset($_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
    } elseif ($current_time - $_SESSION['otp_time'] > 300) {
        setNotification('warning', 'OTP expired. Please log in again.');
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
    } elseif ($_SESSION['otp_attempts'] >= 3) {
        // Block after 3 wrong attempts
        setNotification('error', 'Too many wrong attempts. This OTP is now expired. Please log in again and request a new OTP.');
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
    } elseif ($entered_otp == $_SESSION['otp']) {
        $_SESSION['employee_logged_in'] = true;
        $_SESSION['otp_verified'] = true;
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);

        setNotification('success', 'Login successful! Redirecting to Employee Portal...');
        // Redirect after slight delay so notification shows
        echo "<script>
            setTimeout(function(){ window.location.href = 'employee.php'; }, 1100);
        </script>";
        // Do not exit here, allow notification display
    } else {
        $_SESSION['otp_attempts']++;
        if ($_SESSION['otp_attempts'] >= 3) {
            setNotification('error', 'You have entered the wrong code 3 times. This OTP is now expired. Please log in again and request a new OTP.');
            unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
        } else {
            $remaining = 3 - $_SESSION['otp_attempts'];
            setNotification('error', 'Invalid OTP. You have ' . $remaining . ' attempt' . ($remaining > 1 ? 's' : '') . ' left.');
        }
    }
}

// Handle login submission & OTP resend logic
if (isset($_POST['login_submit']) || isset($_POST['resend_otp'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? '';

    // Validate Gmail format
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
        setNotification('warning', 'Only @gmail.com email addresses are allowed');
        header("Location: login.php");
        exit;
    }

    // Fetch user from DB
    $stmt = $conn->prepare("SELECT first_name, password FROM employees WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        setNotification('error', 'Email not found');
        header("Location: login.php");
        exit;
    }

    $user = $result->fetch_assoc();

    $_SESSION['employee_first_name'] = $user['first_name'];

    // Only check password if not resending OTP
    if (isset($_POST['login_submit'])) {
        if (!password_verify($password, $user['password'])) {
            setNotification('error', 'Incorrect password');
            header("Location: login.php");
            exit;
        }
    }

    $_SESSION['login_email'] = $email;

    // Generate new OTP and attempts only on login OR resend
    $otp = rand(100000, 999999);
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_time'] = time();
    $_SESSION['show_otp_form'] = true;
    $_SESSION['otp_attempts'] = 0;

    // Send OTP email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lguportalph@gmail.com';
        $mail->Password   = 'zsozvbpsggclkcno';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('lguportalph@gmail.com', 'LGU Portal');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Identity: LGU Portal OTP Code';

        $pictureCid = 'cityhallimg'; // Embedded image cid
        $mail->addEmbeddedImage(__DIR__.'/cityhall.jpeg', $pictureCid);

        $mail->Body = '
                <div style="min-height: 100vh;
                    background: #fff;
                    position: relative;
                    padding: 44px 0;
                    font-family: \'Poppins\', Arial, sans-serif;
                    ">
                    <!-- Semi-transparent overlay with blur -->
                    <div style="
                        position: absolute;
                        top: 0; left: 0; width: 100%; height: 100%;
                        background: rgba(24,32,54,0.14);
                        backdrop-filter: blur(8px);
                        -webkit-backdrop-filter: blur(8px);
                        z-index: 0;
                        border-radius: 0;
                    "></div>
                    <div style="
                        position: relative;
                        max-width: 430px;
                        margin: 60px auto;
                        background: rgb(247, 243, 243);
                        border-radius: 18px;
                        box-shadow: 0 10px 38px rgba(66,93,135,0.15);
                        border: 1.8px solid #c8ddf9;
                        padding: 48px 44px 36px 44px;
                        z-index: 1;">
                        <div style="text-align: center;">
                            <img src="cid:'.$pictureCid.'" style="margin-top:-65px;margin-bottom:16px;width:80px;height:80px;object-fit:cover;border-radius:50%;box-shadow: 0 2px 22px rgba(69,104,181,0.09);background:#ecf3fc; border:3.5px solid #e2e8f6; display:inline-block;" alt="City Hall">
                            <div style="font-size: 32px; color: #27417b; font-weight: 800; letter-spacing: 0.03em; margin-bottom: 12px; text-shadow: 0 2px 11px #fff, 0 1px 0 #d3e6ff;">LGU Portal</div>
                            <div style="font-size: 18px; color: #4e627f; margin-bottom: 25px; font-weight: 500; letter-spacing:0.015em;">OTP Verification</div>
                            <div style="background: linear-gradient(104deg, #eaf4fe 85%, #e9f6fd 100%);
                                        display: inline-block; 
                                        border-radius: 8.5px; 
                                        margin-bottom: 28px; 
                                        padding: 18px 38px 17px 38px; 
                                        box-shadow: 0 3px 16px rgba(133,168,194,0.11);
                                        min-width: 170px;">
                                <div style="font-size: 20px; color: #233; font-weight: 500; margin-bottom: 8px; letter-spacing: 0.01em;">Your authentication code is</div>
                                <div style="
                                    font-size: 39px;
                                    font-family: \'Courier New\', monospace;
                                    letter-spacing: 0.22em;
                                    color: #1f66b1;
                                    font-weight: 800;
                                    margin: 0 0 6px 0;
                                    letter-spacing: 0.22em;
                                    letter-spacing: 0.18em;
                                    padding-bottom: 2px;
                                    ">'.$otp.'</div>
                            </div>
                            <div style="color: #305176; font-size: 15.5px; margin-bottom: 17px;">
                                This code is valid for <span style="font-weight:700;color:#174c86;">5 minutes</span> and can only be used once.
                            </div>
                            <div style="color: #ca173f; font-size: 15px; margin-bottom: 18px;">
                                <span style="font-weight: 700;">Never share this code with anyone.</span><br>
                                LGU Portal staff will <span style="text-decoration: underline;">never</span> ask for this code.
                            </div>
                            <div style="color: #9b9eaa; font-size: 13px; margin-top: 16px; line-height:1.5;">
                                Didn\'t request this OTP? You may safely ignore this email.<br>
                                For extra security, do not forward this message.
                            </div>
                        </div>
                    </div>
                    <div style="text-align:center; color:#b7bcca; font-size:12.6px; margin-top:28px; position: relative; z-index:2;">
                        &copy; '.date('Y').' LGU Portal
                    </div>
                </div>
        ';
        $mail->send();
        setNotification('success', 'OTP sent! Please check your email.');

    } catch (Exception $e) {
        setNotification('error', 'Failed to send OTP: ' . $mail->ErrorInfo);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LGU | Login</title>
<link rel="stylesheet" href="style - Copy.css">
<style>
/* ...styles omitted for brevity (left unchanged from before)... */
body 
    { height: 100vh; 
    display:flex; flex-direction:column; 
    background: url("cityhall.jpeg") center/cover no-repeat fixed; 
    position: relative; 
    overflow: hidden; }
body::before 
    { content:""; 
    position:absolute; 
    top:0; 
    left:0; 
    width:100%; 
    height:100%; 
    backdrop-filter: blur(6px); 
    background: rgba(0,0,0,0.35); 
    z-index:0;}

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

/* NAVBAR */
.nav {
    width: 100%;
    padding: 16px 60px;
    display: flex;
    justify-content: space-between;
    align-items: center;

    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);

    border-bottom: 1px solid rgba(255, 255, 255, 0.25);
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);

    position: fixed;
    top: 0;
    left: 0;
    z-index: 100;
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

.nav-links a:hover {
    opacity: 1;
}

.nav-links a.active {
    opacity: 1;
    font-weight: 600;
}

.nav,  .wrapper, .footer 
    { 
    position: relative; 
    z-index:1; }
    
#timer     
    {font-size: 16px;
    font-weight: 600;
    color: #d9534f; /* red for urgency */
    margin-bottom: 15px;
    text-align: center;}

/* OTP Verification Form Styles */
.otp-instruction {
    font-size: 14px;
    color: #666;
    margin-bottom: 15px;
    text-align: center;
}

.otp-inputs-container {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-bottom: 20px;
}

.otp-input {
    width: 45px;
    height: 45px;
    text-align: center;
    font-size: 22px;
    font-weight: 600;
    border: 2px solid rgba(99, 132, 210, 0.3);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.9);
    outline: none;
    transition: all 0.2s ease;
}

.otp-input:focus {
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
    background: #fff;
}

.otp-input.active {
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.15);
}

.verify-code-btn,
.resend-code-btn {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 10px;
    transition: 0.25s ease;
}

.verify-code-btn:hover,
.resend-code-btn:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(43, 91, 222, 0.45);
}

.verify-code-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Resend OTP Button */
.btn-secondary {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;

    /* smoother, premium feel */
    transition: 0.25s ease;
}

.btn-secondary:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(43, 91, 222, 0.45);
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
        <h2 class="title">LGU Login</h2>

        <?php if(isset($_SESSION['show_otp_form']) && $_SESSION['show_otp_form'] === true): ?>
            <div class="otp-icon-container">
                <div class="otp-icon-wrapper">
                </div>
            </div>

            <p class="otp-instruction">Enter Verify Code Below</p>
            <?php
            // Time remaining and wrong attempts shown in UI
            $remaining_seconds = 0;
            $expired = false;
            if (isset($_SESSION['otp_time'])) {
                $now = time();
                $elapsed = $now - $_SESSION['otp_time'];
                $remaining_seconds = max(0, 300 - $elapsed);
                if ($remaining_seconds <= 0) {
                    $expired = true;
                }
            }
            $attempts_left = 3 - ($_SESSION['otp_attempts'] ?? 0);
            ?>
            <p id="timer">
                <?php
                if ($expired) {
                    echo 'OTP expired. Please resend OTP.';
                } else {
                    $min = str_pad(floor($remaining_seconds / 60), 2, "0", STR_PAD_LEFT);
                    $sec = str_pad($remaining_seconds % 60, 2, "0", STR_PAD_LEFT);
                    echo "Time remaining: {$min}:{$sec}";
                }
                ?>
            </p>
            <div class="otp-attempts-msg" style="text-align:center;color:#ca173f;font-size: 14px;margin-bottom:10px;">
                <?php if(!$expired): ?>
                    <?php if($attempts_left === 1): ?>
                        You have <strong>1</strong> attempt left.
                    <?php elseif($attempts_left < 3): ?>
                        You have <strong><?php echo $attempts_left; ?></strong> attempts left.
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <form method="post" id="otpForm" action="">
                <div class="otp-inputs-container">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                </div>
                <input type="hidden" name="otp" id="otpValue">
                <button type="submit" name="otp_submit" class="verify-code-btn" <?php if($expired || $attempts_left <= 0): ?>disabled<?php endif; ?>>Verify Code</button>
            </form>

            <form method="post" action="">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_SESSION['login_email'] ?? '', ENT_QUOTES); ?>">
                <button type="submit" name="resend_otp" class="resend-code-btn" <?php if ($attempts_left <= 0): ?>disabled<?php endif; ?>>Resend Code</button>
            </form>

            <script>
                // OTP Input handling
                const otpInputs = document.querySelectorAll('.otp-input');
                const otpForm = document.getElementById('otpForm');
                const otpValueInput = document.getElementById('otpValue');
                const verifyBtn = document.querySelector('.verify-code-btn');

                // Focus first input on load, only if not disabled
                if (!verifyBtn.disabled) {
                    otpInputs[0].focus();
                }

                // Handle input
                otpInputs.forEach((input, index) => {
                    input.addEventListener('input', (e) => {
                        const value = e.target.value.replace(/[^0-9]/g, '');
                        e.target.value = value;

                        if (value && index < otpInputs.length - 1) {
                            otpInputs[index + 1].focus();
                        }
                        updateOTPValue();
                    });

                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Backspace' && !e.target.value && index > 0) {
                            otpInputs[index - 1].focus();
                        }
                    });

                    input.addEventListener('paste', (e) => {
                        e.preventDefault();
                        const paste = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                        paste.split('').forEach((char, i) => {
                            if (otpInputs[i]) {
                                otpInputs[i].value = char;
                            }
                        });
                        updateOTPValue();
                        if (otpInputs[paste.length]) {
                            otpInputs[paste.length].focus();
                        } else {
                            otpInputs[otpInputs.length - 1].focus();
                        }
                    });

                    input.addEventListener('focus', () => {
                        input.classList.add('active');
                    });

                    input.addEventListener('blur', () => {
                        input.classList.remove('active');
                    });
                });

                function updateOTPValue() {
                    const otp = Array.from(otpInputs).map(input => input.value).join('');
                    otpValueInput.value = otp;
                    verifyBtn.disabled = (otp.length !== 6) || verifyBtn.hasAttribute('data-expired') || <?php echo ($expired || $attempts_left <= 0) ? 'true' : 'false'; ?>;
                }

                // Countdown timer - continue timer, do NOT reset on failed attempts
                let totalTime = <?php echo (int)$remaining_seconds; ?>;
                const timerEl = document.getElementById('timer');
                let timerExpired = <?php echo $expired ? 'true': 'false'; ?>;

                const countdown = setInterval(() => {
                    if (timerExpired) return;
                    let minutes = Math.floor(totalTime / 60);
                    let seconds = totalTime % 60;
                    if (totalTime >= 0) {
                        timerEl.textContent = `Time remaining: ${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}`;
                    } 
                    totalTime--;

                    if (totalTime < 0) {
                        clearInterval(countdown);
                        timerEl.textContent = "OTP expired. Please resend OTP.";
                        verifyBtn.disabled = true;
                        otpInputs.forEach(input => {
                            input.disabled = true;
                            input.style.opacity = '0.5';
                        });
                        // Mark form as expired so updateOTPValue disables verifyBtn even if fields filled later
                        verifyBtn.setAttribute('data-expired','1');
                    }
                }, 1000);

                // Disable verify button initially
                updateOTPValue();

                // Also disable OTP fields if expired or attempts exceeded (just in case)
                if (<?php echo ($expired || $attempts_left <= 0) ? 'true' : 'false'; ?>) {
                    otpInputs.forEach(input => {
                        input.disabled = true;
                        input.style.opacity = '0.5';
                    });
                }
            </script>
        <?php else: ?>
            <p class="subtitle">Secure access to community maintenance services.</p>
            <form method="post" action="">
                <div class="input-box">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="yourname@gmail.com" required>
                    <span class="icon">📧</span>
                </div>
                <div class="input-box" style="position: relative;">
                    <label>Password</label>
                    <input type="password" name="password" id="passwordInput" placeholder="••••••••" required>
                    <button type="button" id="togglePassword" 
                            style="
                                position: absolute;
                                right: 10px;
                                top: 30px;
                                background: none;
                                border: none;
                                cursor: pointer;
                                font-size: 1.2em;
                                color: #888;"
                            tabindex="-1"
                            aria-label="Show password">
                        <span id="togglePwdIcon" aria-hidden="true">👁️</span>
                    </button>
                </div>
                <button type="submit" name="login_submit" class="btn-primary">Sign In</button>
            </form>
            <script>
                // Password toggle logic
                const pwdInput = document.getElementById('passwordInput');
                const toggleBtn = document.getElementById('togglePassword');
                const toggleIcon = document.getElementById('togglePwdIcon');

                // Unicode for eye (open) and closed eye for a more professional LGU setting
                // 🕶️ (modern closed-eye icon), or use SVG for best look
                const iconShow = '👁‍🗨'; // professional eye-inside-box style
                const iconHide = '🛡️';    // shield to signify secure/hidden

                toggleBtn.addEventListener('click', function() {
                    if (pwdInput.type === 'password') {
                        pwdInput.type = 'text';
                        toggleIcon.textContent = iconHide;
                        toggleBtn.setAttribute('aria-label', 'Hide password');
                    } else {
                        pwdInput.type = 'password';
                        toggleIcon.textContent = iconShow;
                        toggleBtn.setAttribute('aria-label', 'Show password');
                    }
                });

                // Set initial icon on page load
                toggleIcon.textContent = iconShow;
            </script>
        <?php endif; ?>
    </div>
</div>

<footer class="footer">
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">About</a>
        <a href="#">Help</a>
    </div>
    <div class="footer-logo">© 2025 LGU Citizen Portal · All Rights Reserved</div>
</footer>

</body>
</html>
