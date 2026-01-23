<?php
session_start();

$INACTIVITY_LIMIT = 20 * 60; // seconds (20 minutes)

/* 🚫 Prevent browser caching of protected pages */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* 🔐 Strict session check */
if (
    !isset($_SESSION['employee_logged_in']) ||
    $_SESSION['employee_logged_in'] !== true
) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

require __DIR__ . '/db.php';

// Notification system (copied from employee.php)
function setNotification($type, $message) {
    $_SESSION['notification'] = [
        'type' => $type,
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
            setTimeout(closeNotif, 2200);
        </script>";
    }
}

$firstName = isset($_SESSION['employee_first_name']) ? $_SESSION['employee_first_name'] : 'User';

// Fetch schedules from database
$schedules = [];
$sql = "SELECT * FROM maintenance_schedule ORDER BY starting_date ASC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $today = new DateTime('today');

    while ($row = $result->fetch_assoc()) {
        $taskLower = strtolower($row['task'] ?? '');
        $autoCategory = false;
        if (empty($row['category']) || $row['category'] === "General Maintenance") {
            if (strpos($taskLower, 'aircon') !== false || strpos($taskLower, 'hvac') !== false) {
                $row['category'] = 'HVAC / Cooling';
                $autoCategory = true;
            } elseif (strpos($taskLower, 'generator') !== false || strpos($taskLower, 'power') !== false) {
                $row['category'] = 'Power & Electrical';
                $autoCategory = true;
            } elseif (strpos($taskLower, 'road') !== false || strpos($taskLower, 'pavement') !== false || strpos($taskLower, 'street') !== false) {
                $row['category'] = 'Roads & Pavements';
                $autoCategory = true;
            } elseif (strpos($taskLower, 'fire') !== false || strpos($taskLower, 'extinguisher') !== false || strpos($taskLower, 'safety') !== false) {
                $row['category'] = 'Safety & Compliance';
                $autoCategory = true;
            } else {
                $row['category'] = 'General Maintenance';
            }
        }

        if (empty($row['priority']) || $row['priority'] === 'Low') {
            if (strpos($taskLower, 'aircon') !== false || strpos($taskLower, 'hvac') !== false) {
                $row['priority'] = 'Medium';
            } elseif (strpos($taskLower, 'generator') !== false || strpos($taskLower, 'power') !== false) {
                $row['priority'] = 'Medium';
            } elseif (strpos($taskLower, 'fire') !== false || strpos($taskLower, 'safety') !== false) {
                $row['priority'] = 'High';
            }
        }
        if (empty($row['assigned_team']) || $row['assigned_team'] === 'General Maintenance Team') {
            if (strpos($taskLower, 'aircon') !== false || strpos($taskLower, 'hvac') !== false) {
                $row['assigned_team'] = 'Facilities - HVAC Team';
            } elseif (strpos($taskLower, 'generator') !== false || strpos($taskLower, 'power') !== false) {
                $row['assigned_team'] = 'Electrical Maintenance Team';
            } elseif (strpos($taskLower, 'fire') !== false || strpos($taskLower, 'safety') !== false) {
                $row['assigned_team'] = 'Safety & Compliance Team';
            }
        }

        $status_label = $row['status'];
        $priority_label = $row['priority'];
        if ($row['status'] == 'Completed') {
            $status_label = 'Completed';
        } else {
            if (!empty($row['starting_date'])) {
                try {
                    $dueDate = new DateTime($row['starting_date']);
                    $diffDays = (int)$today->diff($dueDate)->format('%r%a');
                    if ($diffDays < 0 && $row['status'] != 'Completed' && $row['status'] != 'In Progress') {
                        $status_label = 'Delayed';
                        $priority_label = 'Critical';
                    } elseif ($diffDays === 0 && $row['status'] != 'Completed') {
                        $status_label = 'In Progress';
                        $priority_label = 'High';
                    }
                } catch (Exception $e) {}
            }
        }

        $row['status_label'] = $status_label;
        $row['priority'] = $priority_label;
        // Add schedule_date alias for backward compatibility with JavaScript
        $row['schedule_date'] = date('Y-m-d', strtotime($row['starting_date']));

        $schedules[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maintenance Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* ...[UNCHANGED CSS CODE]... */
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
/* --- BEGIN: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

/* HIDE MOBILE TOP NAV ON DESKTOP */
.mobile-top-nav {
    display: none;
}

/* Z-INDEX LAYERING SAFETY: Ensures UI is above background blur for all key elements */
body {
    height: 100vh;
    background: url("cityhall.jpeg") center center / cover no-repeat fixed;
    position: relative;
    z-index: 0;
}
body::before {
    content: "";
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    backdrop-filter: blur(6px);
    background: rgba(0,0,0,0.35);
    z-index: 0;
}

body::-webkit-scrollbar {
  display: none;
}
.sidebar-nav,
.main-content,
.mobile-top-nav {
    position: relative;
    z-index: 1;
}

/* --- END: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */
/* PROFILE BUTTON */
/* ... (the rest of your existing CSS unchanged above this point) ... */

.sidebar-profile-btn {
    position: absolute;
    top: 18px;
    left: 15px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #3762c8;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    overflow: hidden;
    transition: all 0.3s ease;
    z-index: 1002;
}
.sidebar-profile-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
/* Hover */

.sidebar-profile-btn:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 14px rgba(55,98,200,0.35);
}

/* COLLAPSED SIDEBAR PROFILE POSITION FIX */
.sidebar-nav.collapsed .sidebar-profile-btn {
    position: relative;       /* removes overlap */
    top: auto;
    left: auto;
    margin: 52px auto 10px;   /* pushes profile BELOW toggle */
}

/* COLLAPSED SIDEBAR LAYOUT PUSH-DOWN */
.sidebar-nav.collapsed .sidebar-top {
    padding-top: 10px;
}

.sidebar-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: rgba(255, 255, 255, 0.795);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 1000;
    transition: width 0.3s ease, left 0.3s ease;
}
.sidebar-nav.collapsed {
    width: 70px;
}
/* Toggle Button */
.sidebar-toggle {
    position: absolute;
    top: 20px;
    right: 15px;
    width: 32px;
    height: 32px;
    background: #3762c8;
    border: none;
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
    z-index: 1003;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.sidebar-toggle:hover {
    background: #2851b3;
    transform: scale(1.08);
}
.sidebar-nav.collapsed .sidebar-toggle {
    right: 19px;
}
.toggle-icon {
    transition: transform 0.3s ease;
}
.sidebar-nav.collapsed .toggle-icon {
    transform: rotate(180deg);
}

.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    padding: 20px 0;
    overflow-y: auto;
    position: relative;
}

/* --------- FIX FOR: "navlinks move at the top of the side bar fix it" ---------
   We will enforce that .sidebar-top always stretches to fill remaining height,
   and position nav-list at the correct vertical position below the logo,
   not at the top after collapse.
   Use a spacer div after .site-logo, then let nav-list and the rest flex naturally.
*/
.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    min-height: 0;
    height: 100%;
    /* Ensure that .sidebar-top always fills sidebar height */
}

/* Add a flex spacer below .site-logo to enforce consistent space above nav-list */
.sidebar-logo-spacer {
    height: 16px;
    flex-shrink: 0;
}

/* --------- END FIX --------- */

/* Divider just UNDER the toggle button */
.sidebar-toggle-divider {
    border-bottom: 2px solid rgba(0,0,0,0.18);
    width: 60%;
    margin: 15px auto 9px auto;
    transition: opacity 0.3s, height 0.3s, margin 0.3s;
    opacity: 0;
    height: 0;
    pointer-events: none;
}
.sidebar-nav.collapsed .sidebar-toggle-divider {
    opacity: 1;
    height: 0;
    margin: 15px auto 14px auto;
    pointer-events: auto;
}
/* There is no need for a duplicate .collapse-toggle-divider, replaced with .sidebar-toggle-divider for clear intent */

.sidebar-divider.collapse-toggle-divider { display:none; } /* Remove the old divider line for collapsed */

/* Other existing styles unchanged ... */
/* -- LOGO VISIBILITY ON COLLAPSE -- */
.sidebar-nav .site-logo {
    margin-top: 5px;
    flex-direction: column;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding-bottom: 5px;
    width: calc(100% - 50px);
    margin-left: 25px;
    margin-right: 25px;
    box-sizing: border-box;
    margin-bottom: 20px;
    color: #fff;
    transition: all 0.3s ease;
    overflow: hidden;
}
.sidebar-nav .site-logo img {
    width: 120px;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
    transition: all 0.3s ease, opacity 0.3s ease;
}
.sidebar-nav.collapsed .site-logo {
    margin-left: auto;
    margin-right: auto;
    width: 100%;
    margin-bottom: 0px;
}
.sidebar-nav.collapsed .site-logo img {
    opacity: 1;
    visibility: visible;
    width: 40px;
    height: auto;
}
/* --------- MODIFIED: Make logo-divider visible when collapsed --------- */
.sidebar-divider.logo-divider {
    transition: opacity 0.3s ease, width 0.3s ease, margin 0.3s ease;
    /* always display the divider; style changes below */
    opacity: 1;
    width: calc(100% - 50px);
    margin: 18px 25px 0 25px;
}
.sidebar-nav.collapsed .sidebar-divider.logo-divider {
    opacity: 1;
    width: 40px;
    margin: 5px 25px 0 25px;
}
/* --------- END MODIFICATION --------- */

/* Navigation Links */
/* Move nav-links a bit more to the left (reduce left/right padding) to fit maintenance schedule */
.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 15px; /* changed from 0 20px to 0 10px */
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 0;
    flex-shrink: 0;
    /* Ensures the nav-list stays together and never stretches vertically */
    transition: padding 0.3s ease;
}
.sidebar-nav.collapsed .nav-list {
    padding: 0 10px;
}
.sidebar-nav .nav-list li {
    width: 100%;
    margin: 3px 0;
}
.sidebar-nav .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #000000;
    text-decoration: none;
    padding: 12px 20px;
    transition: all 0.3s ease;
    border-radius: 8px;
    white-space: nowrap;
    overflow: hidden;
    position: relative;
    /* The default size for nav links (14px font, 12px top/bottom, some left/right) */
}

.sidebar-nav .nav-link.active,
.sidebar-nav .nav-link.active:hover {
    background: #3762c8;
    color: #fff;
    transform: translateX(2px);
}
.sidebar-nav .nav-link:hover {
    background: #97a4c2;
    transform: translateX(8px) scale(1.02);
}

/* Collapsed sidebar nav link style */
.sidebar-nav.collapsed .nav-link {
    justify-content: center;
    padding: 12px 10px;  /* This is the nav-link size in collapse: 14px font, 12px top/bottom, 10px left/right */
    position: relative;
}
.sidebar-nav.collapsed .nav-link span:last-child {
    display: none;
}
.sidebar-nav.collapsed .nav-link:hover {
    transform: translateX(0) scale(1.08);
}

/* SIDEBAR HOVER TOOLTIP: Shows navlink name as pop-up at side upon hover/collapsed */
.sidebar-tooltip-pop {
    position: fixed;
    z-index: 5555;
    left: 85px;
    top: 0;
    background: #3762c8;
    color: #fff;
    border-radius: 8px;
    padding: 9px 18px;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 6px 24px rgba(41,87,179,0.13);
    white-space: nowrap;
    pointer-events: auto;
    opacity: 0;
    transition: opacity 0.24s, transform 0.23s;
    transform: translateY(-50%) scale(0.97);
    display:none;
    letter-spacing: 0.03em;
}

/* Show/animate tooltip */
.sidebar-tooltip-pop.active {
    opacity: 1;
    display: block;
    transform: translateY(-50%) scale(1.03);
}
/* Optional: Add a little arrow - visually aligns tooltip */
.sidebar-tooltip-pop::before {
    content:"";
    position:absolute;
    left:-8px;
    top:50%;
    transform:translateY(-50%);
    border-width:8px 8px 8px 0;
    border-style:solid;
    border-color:transparent #3762c8 transparent transparent;
}

.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
    transition: all 0.3s ease;
}
.sidebar-nav.collapsed .sidebar-divider {
    width: calc(100% - 20px);
    margin: 20px 10px 0 10px;
}

.sidebar-nav .user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.2);
    transition: all 0.3s ease;
}

.sidebar-nav .user-welcome,
.sidebar-nav .user-rights {
    text-align: center;
    color: #000000;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 5px;
    transition: all 0.3s ease;
    white-space: nowrap;
    overflow: hidden;
}
.sidebar-nav.collapsed .user-welcome {
    display: none;
}

/* --- LOGOUT BUTTON --- */
.sidebar-nav .logout-btn {
    background: #3762c8;
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s ease;
    white-space: nowrap;
    font-size: 16px;
    min-width: 0;
}
/* Expanded state is normal, below is collapsed state */
.sidebar-nav.collapsed .logout-btn {
    padding: 12px 4px !important;       /* Match tightened .nav-link in collapsed */
    width: 70%;                         /* Take full available width to match nav-links */
    border-radius: 8px;
    font-size: 0 !important;             /* Hide text like .nav-link */
    justify-content: center;
    align-items: center;
    min-width: 0;
    display: flex;
}
.sidebar-nav.collapsed .logout-btn::before {
    content: "🚪";
    font-size: 20px;
    margin-right: 0;
    display: inline-block;
    line-height: 1;
}
.sidebar-nav .logout-btn:hover {
    background: #3762c8;
    color: #fff;
    transform: translateY(-2px) scale(1.02);
}
.sidebar-nav.collapsed .logout-btn:hover {
    transform: scale(1.08);
    background: #2851b3;
}

/* Ensure the button does not shrink smaller than nav-link when collapsed */
.sidebar-nav.collapsed .logout-btn {
    box-sizing: border-box;
    max-width: 100%;
}

.sidebar-nav.collapsed .logout-btn::after {
    display: none;
}

/* END LOGOUT BUTTON */

#logoutAlertBackdrop {
    position: fixed;
    z-index: 5000;
    inset: 0;
    background: rgba(37, 59, 115, 0.20);
    display: none;
    align-items: center;
    justify-content: center;
    transition: background 0.18s;
}
#logoutAlertBackdrop.active {
    display: flex;
}
#logoutAlertModal {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 8px 42px rgba(17, 39, 77, 0.15);
    padding: 36px 28px 22px 28px;
    width: 340px;
    max-width: 95vw;
    animation: fadeIn 0.22s cubic-bezier(.6,-0.01,.52,1.23) 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}
@keyframes fadeIn {
    from{transform:translateY(34px) scale(.95); opacity:.24;}
    to  {transform:translateY(0) scale(1); opacity:1;}
}
#logoutAlertModal .icon-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 62px;
    height: 62px;
    background: #fdeeed;
    border-radius: 50%;
    margin: 0 auto 13px auto;
    box-shadow: 0 2px 8px 0 rgba(236,82,82,0.11);
}
#logoutAlertModal .icon-wrap .icon {
    color: #e94444;
    font-size: 2.1rem;
    line-height: 1;
}
#logoutAlertModal .alert-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: #23285c;
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}
#logoutAlertModal .alert-desc {
    color: #374565;
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
}
#logoutAlertModal .alert-btns {
    display: flex;
    gap: 15px;
    justify-content: center;
}
#logoutAlertModal .alert-btn {
    min-width: 95px;
    padding: 8px 0;
    border-radius: 7px;
    border: none;
    font-weight: bold;
    font-size: 1rem;
    cursor: pointer;
    transition: background .18s, color .18s;
    outline: none;
}
#logoutAlertModal .alert-btn.cancel {
    background: #f3f4fa;
    color: #353d52;
    border: 1px solid #e3e6f1;
}
#logoutAlertModal .alert-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}
#logoutAlertModal .alert-btn.logout {
    color: #fff;
    background: #e94444;
    border: none;
    box-shadow: 0 3px 14px 0 rgba(236,82,82,0.08);
}
#logoutAlertModal .alert-btn.logout:hover {
    background: #c82d2d;
}
.main-content {
    margin-left: 250px;
    padding: 20px 80px;
    position: relative;
    z-index: 1;
    padding-bottom: 0px;
    transition: margin-left 0.3s ease;
}
.main-content.expanded {
    margin-left: 70px;
}
.card {
    background:rgba(255,255,255,.92);
    border-radius:22px;
    padding:30px;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}

/* BUTTON ENHANCEMENT: Ensure .toggle-btn is min 40px wide, 38px tall, centered content */
.toggle-btn {
    min-width: 40px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top:20px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}

/* BUTTON ENHANCEMENT: Ensure .toggle-btn is min 40px wide, 38px tall, centered content */
.schedule-btn {
    width: 38%;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top:20px;
    margin-right: 5px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}

/* BUTTON ENHANCEMENT: Ensure .toggle-btn is min 40px wide, 38px tall, centered content */
.calendar-btn {
    width: 45px;
    height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}

/* ===== Arrow + counter wrapper ===== */
.more-tasks-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}

/* Arrow button (centered) */
.more-tasks-btn {
    width: 20px;
    height: 20px;
    border: none;
    background: transparent;
    cursor: pointer;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 14px;
    line-height: 1;
    color: #333;

    transition: transform 0.25s ease;
}

.more-tasks-btn.open {
    transform: rotate(180deg);
}

/* Counter badge (desktop only) */
.task-counter {
    font-size: 12px;
    padding: 2px 6px;
    border-radius: 10px;
    background: #e5e7eb;
    color: #111;
    font-weight: 600;
    white-space: nowrap;
}


/* --- UX Improvements for Dropdown & Arrow --- */
.more-tasks-btn {
    transition: transform 0.25s ease;
}
.more-tasks-btn.open {
    transform: rotate(180deg);
}
.task-dropdown {
    animation: dropdownFade 0.2s ease-out;
    z-index:999; /* stays above */
}
@keyframes dropdownFade {
    from {
        opacity: 0;
        transform: translateY(-6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ===== Calendar overflow task dropdown ===== */

.calendar-day {
    position: relative;
    overflow: visible;
}

/* Arrow button */
.more-tasks-btn {
    width: 100%;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    margin-top: 4px;
    color: #333;
}

/* Floating dropdown panel */
.task-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    width: 100%;
    background: #fff;
    z-index: 50;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    padding: 6px;
}

/* Task buttons inside dropdown */
.task-dropdown .task-btn {
    display: block;
    width: 100%;
    margin: 6px 0;
}

.schedule-item{
    display:flex;
    justify-content:space-between;
    padding:14px 0;
    border-bottom:1px solid rgba(0,0,0,.1);
}
.schedule-date{font-weight:600}

/* Badges for category / priority / status in list view */
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 4px;
}
.badge-category {
    background:#eef2ff;
    color:#1f3c88;
}
.badge-priority-low {
    background:#e8f5e9;
    color:#2e7d32;
}
.badge-priority-medium {
    background:#fff8e1;
    color:#f9a825;
}
.badge-priority-high {
    background:#ffebee;
    color:#c62828;
}
.badge-priority-critical {
    background:#ffebee;
    color:#b71c1c;
}
.badge-status-completed {
    background:#e8f5e9;
    color:#2e7d32;
}
.badge-status-in-progress {
    background:#e3f2fd;
    color:#1565c0;
}
.badge-status-delayed {
    background:#ffebee;
    color:#c62828;
}
.badge-status-planned,
.badge-status-scheduled {
    background:#eceff1;
    color:#37474f;
}

/* Global text color helpers for status (used in list, calendar number, and modal) */
.status-delayed-color {
    color:#c62828 !important;
}
.status-ongoing-color {
    color:#f9a825 !important;
}
.status-completed-color {
    color:#2e7d32 !important;
}
.status-upcoming-color {
    color:#1565c0 !important;
}
.calendar-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:15px;
    margin-top: -22px;
    font-weight:600;
}
.calendar-grid{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:8px;
}
.calendar-day {
    padding: 10px;
    text-align: center;
    border-radius: 8px;
    background: #f2f4f8;
    cursor: pointer;
    font-size: 13px;
    min-height: 80px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    gap: 5px;
}
.calendar-day .day-tasks {
    font-size: 11px;
    color: #333;
    margin-top: auto;
    text-align: left;
}
.task-btn {
    background: #3762c8;
    color: #fff;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    margin: 2px;
    cursor: pointer;
    font-size: 10px;
    font-weight: 600;
}
.task-btn:hover {
    background: #2a4fa3;
}

/* Status-based background colors for calendar buttons only */
.task-btn.status-delayed-bg {
    background:#c62828;
}
.task-btn.status-ongoing-bg {
    background:#fdd835;
    color:#000;
}
.task-btn.status-completed-bg {
    background:#2e7d32;
}
.task-btn.status-upcoming-bg {
    background:#1565c0;
}
.calendar-day.has-event{
    background:#e0e7ff;
    font-weight:600;
}
.calendar-day:hover{background:#dbe3ff}
.calendar-details{
    margin-top:15px;
    font-size:13px;
}
.hidden{display:none}
.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
    text-align: center;
}
.calendar-weekdays div {
    padding: 6px 0;
    font-size: 13px;
}
.modal {position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; justify-content:center; align-items:center; z-index:2000;}
.modal.hidden {display:none !important;}
.modal-content {background:#fff; padding:20px; border-radius:12px; width:90%; max-width:500px; max-height:80%; overflow-y:auto; position:relative;}
.modal-close {position:absolute; top:10px; right:15px; font-size:22px; cursor:pointer;}
.modal h3 {margin-bottom:15px;}
.modal-task-item {margin-bottom:10px; padding:8px; border-left:4px solid #3762c8; background:#f0f4ff; border-radius:4px;}


/* === Calendar Details Card === */
.calendar-details-card {
    position: relative;
    margin-top: 16px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
    border: 1px solid #000;
    padding: 12px 14px 30px;
    max-height: 180px;
    overflow: hidden;
}
/* Scrollable content */
.calendar-details {
    max-height: 140px;
    overflow-y: auto;
    padding-right: 8px;
    font-size: 0.95rem;
    line-height: 1.5;
}
/* Hide scrollbar (cross-browser) */
.calendar-details::-webkit-scrollbar {
    width: 0;
    height: 0;
}
.calendar-details {
    scrollbar-width: none; /* Firefox */
}
/* Scroll indicator (fade + arrow) */
.scroll-indicator {
    position: absolute;
    bottom: 6px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 18px;
    color: #444;
    opacity: 0.6;
    pointer-events: none;
    animation: scrollHint 1.6s infinite ease-in-out;
}

/* Arrow bounce animation */
@keyframes scrollHint {
    0%   { transform: translate(-50%, 0); opacity: 0.4; }
    50%  { transform: translate(-50%, 6px); opacity: 0.8; }
    100% { transform: translate(-50%, 0); opacity: 0.4; }
}
/* ===============================
   🧾 TASK CHOOSER BUTTON FIX
================================ */
#taskChooserBody .task-btn {
    width: 100%;
    min-height: 44px;          /*  touch-friendly height */
    padding: 10px 14px;
    font-size: 13px;
    border-radius: 10px;
    text-align: left;
    line-height: 1.35;
    display: flex;
    align-items: center;
    white-space: normal;      /* allow wrapping */
    word-break: break-word;
}

/* -- Start: ListView Search Styles -- */
#scheduleSearch {
    width: 100%;
    font-size: 1rem;
    padding: 9px 11px;
    border: 1px solid #b1b8d0;
    border-radius: 8px;
    margin-bottom: 18px;
    margin-top: 0;
    outline: none;
    background: #f8faff;
    color: #23285c;
    box-sizing: border-box;
    transition: border 0.19s, box-shadow 0.19s;
}
#scheduleSearch:focus {
    border: 1.5px solid #3762c8;
    box-shadow: 0 2px 8px rgba(55,98,200,0.06);
}
/* -- End: ListView Search Styles -- */
/* =========================
   MOBILE VIEW ONLY
========================= */
/* ===============================
    MONTH / YEAR PICKER
================================ */
.month-picker-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 6000;
}
.month-picker-overlay.hidden {
    display: none;
}

.month-picker {
    background: #fff;
    padding: 20px;
    border-radius: 16px;
    width: 320px;
    max-width: 90%;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.picker-header {
    font-weight: 600;
    text-align: center;
    font-size: 1rem;
}

.month-picker select {
    padding: 10px;
    font-size: 0.95rem;
    border-radius: 10px;
    border: 1px solid #b1b8d0;
    background: #f8faff;
}

.picker-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.picker-actions button {
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 600;
}

#pickerCancel {
    background: #f1f3f9;
}
#pickerApply {
    background: #3762c8;
    color: #fff;
}

/* ===============================
   📱 FIX: Center Month Picker on Mobile
================================ */
@media (max-width: 768px) {
    .month-picker-overlay {
        align-items: center;       /* ⬅ center vertically */
        justify-content: center;
        padding: 16px;
    }

    .month-picker {
        width: 100%;
        max-width: 360px;
        border-radius: 18px;       /* ⬅ normal modal shape */
        padding-bottom: 20px;
        animation: pickerPop 0.25s ease;
    }
}
/* subtle pop animation */
@keyframes pickerPop {
    from {
        transform: translateY(20px) scale(0.96);
        opacity: 0;
    }
    to {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

/* ===============================
    Clickable Month Label Indicator
================================ */
#monthLabel,
#mobileMonthLabel {
    cursor: pointer;
    position: relative;
    padding-right: 18px;
}

#monthLabel::after,
#mobileMonthLabel::after {
    content: "▾";
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.9em;
    opacity: 0.6;
    transition: transform 0.2s ease, opacity 0.2s ease;
}

#monthLabel:hover::after,
#mobileMonthLabel:hover::after {
    opacity: 1;
    transform: translateY(-50%) scale(1.2);
}

#monthLabel:hover,
#mobileMonthLabel:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {

    /* MOBILE CONTROLS (INSIDE CARD) */
    .mobile-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;        /* allow wrapping on tiny screens */
        gap: 6px;               /* smaller gap for tight screens */
        margin: 0 4px 12px 4px;
        padding: 10px 12px;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(12px);
        border-radius: 16px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    }

    /* LIST VIEW CONTROLS */
    #mobileListControls input {
        flex: 1 1 auto;                           /* grow and shrink */
        min-width: 100px;                         /* prevent too small */
        max-width: calc(100% - 50px);             /* slightly more room to reduce space */
        padding: 8px 8px;                         /* less horizontal padding */
        border-radius: 10px;
        border: 1px solid #b1b8d0;
        font-size: 0.9rem;
        margin-right: 4px;                        /* reduce space between input and button */
    }
    /* Mobile List → Calendar button matches calendar button style */
    #mobileListControls button.mobile-calendar-btn {
        flex: 0 0 38px;                   /* slightly less width */
        height: 38px;                     /* match input height if needed */
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        padding: 0;
        margin: 0;
        transition: transform 0.1s ease-in-out;
    }

    /* Active touch scale effect like calendar buttons */
    #mobileListControls button.mobile-calendar-btn:active {
        transform: scale(0.95);
    }

    /* CALENDAR CONTROLS */
    #mobileCalendarControls {
        display: flex;
        flex-wrap: wrap;           /* wrap if space is tight */
        align-items: center;
        justify-content: space-between;
        gap: 4px;
        padding: 8px 10px;
    }

    /* Month label centered & responsive */
    #mobileCalendarControls span#mobileMonthLabel {
        flex: 1 1 auto;           /* grow to fill space */
        min-width: 80px;
        text-align: center;
        font-weight: 600;
        font-size: 0.95rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Buttons responsive */
    #mobileCalendarControls button {
        flex: 0 0 auto;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        cursor: pointer;
        padding: 0;
        margin: 0;
    }
    #mobileToListBtn {
        flex: 0 0 auto;
        min-width: 36px;
        padding: 0 6px; /* allow icon+text to fit */
    }

    /* Active touch scale */
    #mobileCalendarControls button:active {
        transform: scale(0.95);
    }

    /* Hide desktop controls INSIDE card on mobile */
    #scheduleView > div:first-child,
    .calendar-header {
        display: none !important;
    }

    /* Show mobile top nav in mobile */
    .mobile-top-nav {
        display: flex;
    }

    /* Hide desktop sidebar initially */
    .sidebar-nav {
        left: -110%;
        width: calc(100% - 24px);
        height: calc(100% - 24px);
        top: 12px;
        bottom: 12px;
        border-radius: 18px;
        transition: left 0.35s ease;
        z-index: 4000;
    }

    /* Show sidebar when active */
    .sidebar-nav.mobile-active {
        left: 12px;
    }

    /* Disable desktop collapse behavior */
    .sidebar-nav.collapsed {
        width: calc(100% - 24px);
    }

    /* Main content always full width */
    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        padding-top: 90px;
    }

    /* MOBILE TOP NAV */
    .mobile-top-nav {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 64px;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(12px);
        align-items: center;
        justify-content: center;
        z-index: 5000;
        box-shadow: 0 4px 18px rgba(0,0,0,0.2);
    }

    .mobile-top-nav img {
        height: 42px;
        object-fit: contain;
    }

    .mobile-toggle {
        position: absolute;
        left: 16px;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        font-size: 20px;
        cursor: pointer;
    }

    /* Sidebar internal layout for mobile */
    .sidebar-top {
        padding-top: 30px;
    }

    .sidebar-profile-btn {
        position: relative;
        margin: 10px 0 0 15px;
    }

    .site-logo {
        margin: 10px auto 20px auto;
    }

    .nav-list {
        padding: 0 20px;
    }

    .sidebar-divider,
    .sidebar-toggle,
    .sidebar-toggle-divider {
        display: none !important;
    }

    /* Logout stays bottom */
    .user-info {
        padding-bottom: 20px;
    }

    /* Hide desktop toggle */
    .sidebar-toggle {
        display: none;
    }

    /* ---------- CALENDAR VIEW ---------- */

        /* Calendar wrapper spacing */
        #calendarView {
            padding: 14px;
            margin-top: 0px;
            border-radius: 18px;
            background: rgba(255,255,255,0.88);
            box-shadow: 0 6px 20px rgba(0,0,0,0.18);
        }

        /* Weekday labels – compact */
        .calendar-weekdays div {
            font-size: 11px;
            padding: 4px 0;
            letter-spacing: 0.04em;
        }

        /* Calendar grid spacing */
        .calendar-grid {
            gap: 6px;
        }

        /* Day cell compact layout */
        .calendar-day {
            min-height: 64px;
            padding: 6px 4px;
            font-size: 11px;
            border-radius: 10px;
        }

        /* Task buttons smaller */
        .calendar-day .task-btn {
            font-size: 9px;
            padding: 3px 6px;
            border-radius: 6px;
        }

        /* Calendar details spacing */
        .calendar-details {
            margin-top: 10px;
            padding: 10px;
            border-radius: 12px;
            background: rgba(255,255,255,0.9);
        }

        /* ---------- LIST VIEW ---------- */

        #scheduleView {
            padding: 14px;
            margin-top: 0px;;
            border-radius: 18px;
            background: rgba(255,255,255,0.88);
            box-shadow: 0 6px 20px rgba(0,0,0,0.18);
        }

        /* Search spacing */
        #scheduleSearch {
            margin-bottom: 14px;
        }

        /* Each schedule item becomes card-like */
        .schedule-item {
            padding: 14px;
            margin-bottom: 12px;
            border-radius: 14px;
            background: rgba(255,255,255,0.96);
            box-shadow: 0 4px 14px rgba(0,0,0,0.12);
            flex-direction: column;
            gap: 8px;
        }

        .schedule-date {
            font-size: 13px;
        }

        .calendar-details-card {
            padding: 4px 4px 14px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.2);
            max-height: 150px;
            border: 0.1px solid #000;
        }

        .calendar-details {
            font-size: 0.82rem;   /* smaller, readable */
            line-height: 1.35;
            max-height: 110px;
        }

        .scroll-indicator {
            font-size: 14px;
            bottom: 4px;
        }

    /* ===============================
       🚩 MOBILE-ONLY MAIN CONTENT FIXES
       =============================== */

    /* 1️ MAIN CONTENT SCROLLS (allow full height and scroll) */
    .main-content,
    .main-content.expanded {
        height: auto;
        min-height: 100vh;
        overflow-y: auto;           /* allow scrolling */
        padding: 14px;
        -webkit-overflow-scrolling: touch;
        margin-top: 70px;
    }

    /* 3⃣ HIDE SCROLLBARS (still scrollable!) */
    .main-content::-webkit-scrollbar {
        display: none;
    }
    .main-content {
        scrollbar-width: none; /* Firefox */
    }

    /* 🧪 OPTIONAL: mobile card tighter padding for small screens */
    .card {
        padding: 22px;
    }
    
}
/* ============================= */
/* SCROLLING FOR CALENDAR DETAILS */
/* ============================= */

.calendar-details {
    max-height: calc(5 * 1.6em); /* ~5 lines */
    overflow-y: auto;
    padding-right: 6px;
    scroll-behavior: smooth;
}

/* ============================= */
/* SCROLLING FOR TASK DROPDOWN */
/* ============================= */

.task-dropdown {
    max-height: calc(3 * 38px); /* ~5 task buttons */
    overflow-y: auto;
    overscroll-behavior: contain;
    padding-right: 4px;
}

/* ============================= */
/* HIDE SCROLLBARS (ALL BROWSERS) */
/* ============================= */

/* Chrome, Edge, Safari */
.calendar-details::-webkit-scrollbar,
.task-dropdown::-webkit-scrollbar {
    width: 0;
    height: 0;
}

/* Firefox */
.calendar-details,
.task-dropdown {
    scrollbar-width: none;
}

/* IE / Legacy Edge */
.calendar-details,
.task-dropdown {
    -ms-overflow-style: none;
}

/* ============================= */
/* MOBILE SAFETY ADJUSTMENTS */
/* ============================= */

@media (max-width: 768px) {
    .calendar-details {
        max-height: calc(5 * 1.8em);
    }

    .task-dropdown {
        max-height: calc(3 * 42px);
    }
}
</style>
</head>
<body>
<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <img src="logocityhall.png" alt="LGU Logo">
</div>

<?php showNotification(); ?>

<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>

    <div class="sidebar-top">
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile">
            <img src="profile.png" alt="Profile" id="profileImg">
            <span class="profile-fallback-icon" id="profileFallbackIcon">👤</span>
        </div>
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><span>📊</span><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><span>📋</span><span>Requests</span></a></li>
            <li><a href="reports.php" class="nav-link" data-tooltip="Reports"><span>📄</span><span>Reports</span></a></li>
            <li><a href="#" class="nav-link active" data-tooltip="Maintenance Schedule"><span>📅</span><span>Maintenance Schedule</span></a></li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>
    <div class="sidebar-divider"></div>
    <div class="user-info">
        <div class="user-welcome">Welcome, <?= htmlspecialchars($firstName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">Logout</button>
    </div>
</div>

<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<div class="main-content">

    <div class="card">

        <!-- MOBILE CONTROLS (MOBILE ONLY, INSIDE CARD) -->
        <div class="mobile-controls" id="mobileListControls" style="display:none;">
            <input id="mobileScheduleSearch" type="text"
                   placeholder="Search schedules...">
            <button id="mobileToCalendarBtn" class="mobile-calendar-btn">📅</button>
        </div>
        <div class="mobile-controls" id="mobileCalendarControls" style="display:none;">
            <button id="mobilePrevMonth" class="mobile-toggle-btn">&#8592;</button>
            <span id="mobileMonthLabel" title="Click to jump date"></span>
            <button id="mobileToListBtn" class="mobile-schedule-btn">📋</button>
            <button id="mobileNextMonth" class="mobile-toggle-btn">&#8594;</button>
        </div>

        <!-- CALENDAR VIEW -->
        <div id="calendarView">
            <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel" title="Click to jump date"></span>
                <div style="display:flex; gap:8px;">
                    <button id="toListBtn" class="schedule-btn" title="Schedule List">
                        📋
                    </button>
                    <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">
                        &#8594;
                    </button>
                </div>
            </div>
            <div class="calendar-weekdays">
                <div>Sunday</div>
                <div>Monday</div>
                <div>Tuesday</div>
                <div>Wednesday</div>
                <div>Thursday</div>
                <div>Friday</div>
                <div>Saturday</div>
            </div>
            <div class="calendar-grid" id="calendarGrid"></div>
            <div class="calendar-details-card">
                <div class="calendar-details" id="calendarDetails">
                    Select a date to view schedule.
                </div>
                <div class="scroll-indicator">⌄</div>
            </div>
        </div>
        <!-- LIST VIEW -->
        <div id="scheduleView" class="hidden">
            <div style="display:flex; gap:10px; align-items:center;">
                <input id="scheduleSearch" type="text"
                       placeholder="Search by task, location, category, status, or date..."
                       style="flex:1;">
                <button id="toCalendarBtn" class="calendar-btn" title="Calendar View">
                    📅
                </button>
            </div>
            <div id="scheduleListHolder">
            <?php if (empty($schedules)): ?>
                <p id="noScheduleMsg">No scheduled maintenance.</p>
            <?php else: foreach ($schedules as $row): ?>
                <div class="schedule-item"
                    data-task="<?= htmlspecialchars(strtolower($row['task'])) ?>"
                    data-location="<?= htmlspecialchars(strtolower($row['location'])) ?>"
                    data-category="<?= htmlspecialchars(strtolower($row['category'] ?? '')) ?>"
                    data-status="<?= htmlspecialchars(strtolower($row['status_label'] ?? '')) ?>"
                    data-priority="<?= htmlspecialchars(strtolower($row['priority'] ?? '')) ?>"
                    data-date="<?= htmlspecialchars(strtolower(date("F d, Y", strtotime($row['schedule_date']))) . '|' . strtolower($row['schedule_date'])) ?>">
                    <div>
                        <strong><?= htmlspecialchars($row['task']) ?></strong><br>
                        <?= htmlspecialchars($row['location']) ?><br>
                        <?php if (!empty($row['category'])): ?>
                            <span class="badge badge-category"><?= htmlspecialchars($row['category']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="schedule-date">
                        <?= date("F d, Y", strtotime($row['schedule_date'])) ?><br>
                        <?php
                            $priorityClass = 'badge-priority-low';
                            $priorityLower = strtolower($row['priority'] ?? '');
                            if ($priorityLower === 'medium') {
                                $priorityClass = 'badge-priority-medium';
                            } elseif ($priorityLower === 'high') {
                                $priorityClass = 'badge-priority-high';
                            } elseif ($priorityLower === 'critical') {
                                $priorityClass = 'badge-priority-critical';
                            }

                            $statusClass = 'badge-status-planned';
                            $statusLower = strtolower($row['status_label'] ?? '');
                            if ($statusLower === 'completed') {
                                $statusClass = 'badge-status-completed';
                            } elseif ($statusLower === 'in progress') {
                                $statusClass = 'badge-status-in-progress';
                            } elseif ($statusLower === 'delayed') {
                                $statusClass = 'badge-status-delayed';
                            } elseif ($statusLower === 'scheduled') {
                                $statusClass = 'badge-status-scheduled';
                            }
                        ?>
                        <?php if (!empty($row['status_label'])): ?>
                            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($row['status_label']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($row['priority'])): ?>
                            <span class="badge <?= $priorityClass ?>"><?= htmlspecialchars($row['priority']) ?> priority</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
                <p id="noResultMsg" style="display:none;">No matching data or result.</p>
            <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal -->
<div id="taskModal" class="modal hidden">
    <div class="modal-content">
        <span id="modalClose" class="modal-close">&times;</span>
        <h3>Scheduled Tasks</h3>
        <div id="modalBody"></div>
    </div>
</div>

<!-- NEW: Multi-Task Chooser Modal (for date with >1 task) -->
<div id="taskChooserModal" class="modal hidden">
  <div class="modal-content">
    <span class="modal-close" onclick="closeTaskChooser()">&times;</span>
    <h3>Select a Task</h3>
    <div id="taskChooserBody"></div>
  </div>
</div>

<!-- Logout Confirmation Alert Modal (Redesigned based on reports.php) -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="icon-wrap">
            <span class="icon">&#9888;</span>
        </div>
        <div class="alert-title">Log out of your account?</div>
        <div class="alert-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" id="logoutCancelBtn">Cancel</button>
            <button class="alert-btn logout" id="logoutConfirmBtn">Log out</button>
        </div>
    </div>
</div>

<!-- Native Date Picker Element (hidden, no overlay/modal) -->
<input
  type="date"
  id="pickerDate"
  style="
    position: fixed;
    opacity: 0;
    pointer-events: none;
    width: 1px;
    height: 1px;
  "
>

<!-- Custom Date Picker Overlay -->
<style>
#customDatePickerOverlay {
    position: absolute;
    background: #fff;
    border: 1px solid #ccc;
    z-index: 2000; /* Increased for UX on mobile */
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    display: none;
}
#customDatePickerOverlay input[type="date"] {
    width: 180px;
    padding: 6px 8px;
    background: #f7faff; /* Match desktop calendar background */
    border: 1px solid #b1b8d0;
    color: #222;
    font-size: 1rem;
    border-radius: 7px;
    transition: background 0.15s;
}
#customDatePickerOverlay input[type="date"]:focus {
    background: #e8f1ff;
    outline: 2px solid #3762c8;
    outline-offset: 0;
}
@media (max-width: 768px) {
    #customDatePickerOverlay {
        position: fixed !important;
        width: 150px;
        z-index: 2500;
    }
    #customDatePickerOverlay input[type="date"] {
        width: 100%;
        font-size: 1rem;
        background: #f7faff; /* Ensure mobile also matches */
    }
}


</style>
<div id="customDatePickerOverlay">
    <input type="date" id="overlayDatePicker">
</div>

<!-- =============== SCHEDULE DATA PATCH =============== -->
<script>
window.scheduleData = <?= json_encode($schedules ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<!-- ============ END SCHEDULE DATA PATCH ============== -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Helper for query selector with error
    function getSafeElem(id) {
        const el = document.getElementById(id);
        if (!el) {
            console.warn('[sched.php] Missing element for:', id);
        }
        return el;
    }

    // Grab all required elements safely (with null fallback)
    const sidebarToggle = getSafeElem('sidebarToggle');
    const sidebar = getSafeElem('sidebarNav');
    const mainContent = document.querySelector('.main-content');
    const sidebarNav = getSafeElem('sidebarNav');
    const sidebarNavTooltip = getSafeElem('sidebarNavTooltip');
    const profileIconBtn = getSafeElem('profileIconBtn');
    const logoutBtn = getSafeElem('logoutBtn');
    const logoutAlertBackdrop = getSafeElem('logoutAlertBackdrop');
    const logoutCancelBtn = getSafeElem('logoutCancelBtn');
    const logoutConfirmBtn = getSafeElem('logoutConfirmBtn');
    const mobileToggle = getSafeElem('mobileToggle');
    const taskModal = getSafeElem('taskModal');
    const modalBody = getSafeElem('modalBody');
    const modalClose = getSafeElem('modalClose');
    const taskChooserModal = getSafeElem('taskChooserModal');
    const taskChooserBody = getSafeElem('taskChooserBody');
    const calendarGrid = getSafeElem('calendarGrid');
    const calendarDetails = getSafeElem('calendarDetails');
    const monthLabel = getSafeElem('monthLabel');
    const mobileMonthLabel = getSafeElem('mobileMonthLabel');
    const calendarView = getSafeElem('calendarView');
    const scheduleView = getSafeElem('scheduleView');
    const scheduleSearch = getSafeElem('scheduleSearch');
    const scheduleListHolder = getSafeElem('scheduleListHolder');
    const noResultMsg = getSafeElem('noResultMsg');
    const toCalendarBtn = getSafeElem('toCalendarBtn');
    const toListBtn = getSafeElem('toListBtn');
    const mobileListControls = getSafeElem('mobileListControls');
    const mobileCalendarControls = getSafeElem('mobileCalendarControls');
    const mobileToCalendarBtn = getSafeElem('mobileToCalendarBtn');
    const mobileToListBtn = getSafeElem('mobileToListBtn');
    const mobilePrevMonth = getSafeElem('mobilePrevMonth');
    const mobileNextMonth = getSafeElem('mobileNextMonth');
    const mobileScheduleSearch = getSafeElem('mobileScheduleSearch');
    const prevMonthBtn = getSafeElem('prevMonth');
    const nextMonthBtn = getSafeElem('nextMonth');

    // Date Picker (Native input only, no overlay)
    const pickerDate = getSafeElem('pickerDate');

    // Defensive fallback (should never be needed with PATCH above)
    if (typeof window.scheduleData === "undefined") window.scheduleData = [];

    // --- MOBILE VIEW DETECTOR (Canonical, one function only) ---
    function isMobileView() {
        return window.innerWidth <= 768;
    }

    // --- Sidebar collapse state logic (unchanged) ---
    if (sidebar && mainContent) {
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
        let lastMobileState = isMobileView();
        window.addEventListener('resize', () => {
            const isNowMobile = isMobileView();
            if (isNowMobile && !lastMobileState && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
                localStorage.setItem('sidebarCollapsed', 'false');
            }
            lastMobileState = isNowMobile;
        });
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                const isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('sidebarCollapsed', isCollapsed);
                if (sidebarNavTooltip) {
                    sidebarNavTooltip.classList.remove('active');
                    sidebarNavTooltip.style.display = 'none';
                }
            });
        }
    }

    // --- Sidebar tooltips and nav (unchanged) ---
    // ... no changes, copy as before ...
    let tooltipActiveLink = null;
    let tooltipHideTimeout = null;
    function hideNavTooltipImmediate() {
        if (!sidebarNavTooltip) return;
        sidebarNavTooltip.classList.remove('active', 'logout-pop');
        sidebarNavTooltip.style.display = 'none';
        tooltipActiveLink = null;
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function hideNavTooltip() {
        if (!sidebarNavTooltip) return;
        sidebarNavTooltip.classList.remove('active', 'logout-pop');
        setTimeout(function() {
            sidebarNavTooltip.style.display = 'none';
            tooltipActiveLink = null;
        }, 150);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function showLogoutTooltip(e) {
        if (!sidebarNavTooltip || !logoutBtn || !sidebar) return;
        const tooltipText = logoutBtn.getAttribute('data-tooltip') || "Log out";
        tooltipActiveLink = logoutBtn;
        sidebarNavTooltip.textContent = tooltipText;
        sidebarNavTooltip.classList.add('logout-pop');
        sidebarNavTooltip.style.display = 'block';
        const rect = logoutBtn.getBoundingClientRect();
        const sidebarRect = sidebar.getBoundingClientRect();
        const x = sidebarRect.right + 5;
        const y = rect.top + rect.height / 2 + window.scrollY;
        sidebarNavTooltip.style.left = (x + 10) + 'px';
        sidebarNavTooltip.style.top = y + 'px';
        setTimeout(function(){ sidebarNavTooltip.classList.add('active'); }, 5);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function navTooltipHandler(e) {
        if (!sidebarNavTooltip || !sidebar) return;
        if (!sidebar.classList.contains('collapsed')) {
            hideNavTooltip();
            return;
        }
        let tooltipText = this.getAttribute('data-tooltip');
        if (!tooltipText && this.id === "profileIconBtn") tooltipText = "Profile";
        if (!tooltipText) return;
        tooltipActiveLink = this;
        sidebarNavTooltip.textContent = tooltipText;
        sidebarNavTooltip.classList.remove('logout-pop');
        sidebarNavTooltip.style.display = 'block';
        const rect = this.getBoundingClientRect();
        const sidebarRect = sidebar.getBoundingClientRect();
        const x = sidebarRect.right + 5;
        const y = rect.top + rect.height / 2 + window.scrollY;
        sidebarNavTooltip.style.left = (x + 10) + 'px';
        sidebarNavTooltip.style.top = y + 'px';
        setTimeout(function(){ sidebarNavTooltip.classList.add('active'); }, 5);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function navLinkMouseLeaveHandler(e) {
        if (!sidebarNavTooltip) return;
        if (
            e.relatedTarget === sidebarNavTooltip ||
            (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
        ) {
            return;
        }
        tooltipHideTimeout = setTimeout(() => {
            hideNavTooltip();
            tooltipActiveLink = null;
        }, 60);
    }
    if (sidebarNavTooltip) {
        sidebarNavTooltip.addEventListener('mouseleave', function() {
            tooltipHideTimeout = setTimeout(() => {
                hideNavTooltip();
                tooltipActiveLink = null;
            }, 60);
        });
        sidebarNavTooltip.addEventListener('mouseenter', function() {
            if (tooltipHideTimeout) {
                clearTimeout(tooltipHideTimeout);
                tooltipHideTimeout = null;
            }
        });
    }

    if (sidebarNav) {
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
            link.addEventListener('mouseenter', navTooltipHandler);
            link.addEventListener('focus', navTooltipHandler);
            link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
            link.addEventListener('blur', hideNavTooltip);
        });
    }
    if (profileIconBtn) {
        profileIconBtn.addEventListener('mouseenter', navTooltipHandler);
        profileIconBtn.addEventListener('focus', navTooltipHandler);
        profileIconBtn.addEventListener('mouseleave', navLinkMouseLeaveHandler);
        profileIconBtn.addEventListener('blur', hideNavTooltip);
    }
    if (logoutBtn) {
        logoutBtn.addEventListener('mouseenter', function(e) {
            if (!sidebar || !sidebar.classList.contains('collapsed')) {
                hideNavTooltipImmediate();
                return;
            }
            showLogoutTooltip(e);
        });
        logoutBtn.addEventListener('focus', function(e) {
            if (!sidebar || !sidebar.classList.contains('collapsed')) {
                hideNavTooltipImmediate();
                return;
            }
            showLogoutTooltip(e);
        });
        logoutBtn.addEventListener('mouseleave', function(e) {
            if (
                sidebarNavTooltip && 
                (e.relatedTarget === sidebarNavTooltip ||
                (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget)))
            ) { return; }
            sidebarNavTooltip && sidebarNavTooltip.classList.remove('active', 'logout-pop');
            sidebarNavTooltip && (sidebarNavTooltip.style.display = 'none');
            tooltipActiveLink = null;
            if (tooltipHideTimeout) {
                clearTimeout(tooltipHideTimeout);
                tooltipHideTimeout = null;
            }
        });
        logoutBtn.addEventListener('blur', hideNavTooltip);
        logoutBtn.addEventListener('keydown', function(e) {
            if (sidebar && sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
                e.preventDefault();
                this.focus();
            }
        });
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (logoutAlertBackdrop) logoutAlertBackdrop.classList.add("active");
            hideNavTooltipImmediate();
        });
    }
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            if (sidebarNavTooltip) {
                sidebarNavTooltip.classList.remove('active', 'logout-pop');
                sidebarNavTooltip.style.display = 'none';
            }
            tooltipActiveLink = null;
            if (tooltipHideTimeout) {
                clearTimeout(tooltipHideTimeout);
                tooltipHideTimeout = null;
            }
        });
    }
    document.querySelectorAll('.nav-link, #profileIconBtn').forEach(function(link) {
        link.addEventListener('keydown', function(e) {
            if (sidebar && sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
                e.preventDefault();
                this.focus();
            }
        });
    });

    if (logoutAlertBackdrop && logoutCancelBtn && logoutConfirmBtn) {
        logoutCancelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logoutAlertBackdrop.classList.remove("active");
        });
        logoutConfirmBtn.addEventListener('click', (e) => {
            e.preventDefault();
            window.location.href = 'logout.php';
        });
        logoutAlertBackdrop.addEventListener('mousedown', (e) => {
            if (e.target === logoutAlertBackdrop) {
                logoutAlertBackdrop.classList.remove("active");
            }
        });
    }

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-active');
        });
    }

    // Enforce calendar/form reload on bfcache
    window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    // === Calendar & Schedule Logic ===

    // Defensive: ensure calendar elements exist
    if (!calendarGrid || !calendarDetails || !monthLabel || !calendarView || !scheduleView) return;

    let currentDate = new Date();
    let showingCalendar = true;

    // --- Helper: status mapping
    function getStatusKey(statusLabel) {
        const s = (statusLabel || '').toLowerCase();
        if (!s) return 'upcoming';
        if (s.indexOf('delay') !== -1) return 'delayed';
        if (s.indexOf('progress') !== -1 || s.indexOf('on-going') !== -1 || s.indexOf('ongoing') !== -1) return 'ongoing';
        if (s.indexOf('completed') !== -1) return 'completed';
        return 'upcoming';
    }
    function applyStatusClassesToList() {
        document.querySelectorAll('.schedule-item').forEach(item => {
            const statusLabel = item.getAttribute('data-status') || '';
            const key = getStatusKey(statusLabel);
            item.classList.add('status-' + key + '-color');
        });
    }

    // --- Modal Logic ---
    if (taskModal && modalBody && modalClose && taskChooserModal && taskChooserBody) {
        modalClose.onclick = ()=>taskModal.classList.add('hidden');
        window.onclick = (e)=>{
            if(e.target===taskModal) taskModal.classList.add('hidden');
            if(e.target===taskChooserModal) taskChooserModal.classList.add('hidden');
        };
    }
    function openModal(tasks){
        if (!modalBody || !taskModal) return;
        modalBody.innerHTML='';
        tasks.forEach(t=>{
            const div=document.createElement('div');
            div.className='modal-task-item';
            const category   = t.category      || 'General Maintenance';
            const priority   = t.priority      || 'Low';
            const statusLbl  = t.status_label  || 'Planned';
            const team       = t.assigned_team || 'General Maintenance Team';

            const statusKey  = getStatusKey(statusLbl);
            if (statusKey) {
                div.classList.add('status-' + statusKey + '-color');
            }

            div.innerHTML=`<strong>Task:</strong> ${t.task}<br>
                           <strong>Location:</strong> ${t.location}<br>
                           <strong>Scheduled Date:</strong> ${t.schedule_date}<br>
                           <strong>Category:</strong> ${category}<br>
                           <strong>Priority:</strong> ${priority}<br>
                           <strong>Status:</strong> ${statusLbl}<br>
                           <strong>Assigned Team:</strong> ${team}`;
            modalBody.appendChild(div);
        });
        taskModal.classList.remove('hidden');
    }
    function openTaskChooser(date, tasks) {
        if (!taskChooserBody || !taskChooserModal) return;
        taskChooserBody.innerHTML = '';
        tasks.forEach(t => {
            const btn = document.createElement('button');
            btn.className = 'task-btn';
            btn.style.margin = '8px 0';
            btn.style.width = '100%';
            btn.textContent = `${t.task} – ${t.location}`;
            const key = getStatusKey(t.status_label || '');
            if (key) btn.classList.add('status-' + key + '-bg');
            btn.onclick = () => {
                taskChooserModal.classList.add('hidden');
                openModal([t]);
            };
            taskChooserBody.appendChild(btn);
        });
        taskChooserModal.classList.remove('hidden');
    }
    window.closeTaskChooser = function() {
        if (taskChooserModal) taskChooserModal.classList.add('hidden');
    };

    // --- Calendar render & dropdown logic ---
    let openDropdown = null;
    let openDropdownDay = null;
    function closeDropdown(){
        if (openDropdown) {
            openDropdown.remove();
            openDropdown = null;
            openDropdownDay = null;
            document.querySelectorAll('.more-tasks-btn.open').forEach(b => b.classList.remove('open'));
        }
    }
    function toggleTaskDropdown(dayDiv, events, arrowBtn) {
        if (openDropdown && openDropdownDay === dayDiv) {
            closeDropdown();
            return;
        }
        closeDropdown();

        const dropdown = document.createElement('div');
        dropdown.className = 'task-dropdown';
        dropdown.setAttribute('role','menu');

        // FIX 2: Stop dropdown auto-closing by stopping propagation
        dropdown.addEventListener('click', ev => {
            ev.stopPropagation();
        });

        events.slice(1).forEach((e, i) => {
            const btn = document.createElement('button');
            btn.className = 'task-btn';
            btn.setAttribute('role','menuitem');
            if (isMobileView()) {
                btn.textContent = i + 2;
            } else {
                btn.textContent = e.task;
            }
            const key = getStatusKey(e.status_label || '');
            if (key) btn.classList.add('status-' + key + '-bg');
            btn.onclick = (ev) => {
                ev.stopPropagation();
                closeDropdown();
                openModal([e]);
            };
            dropdown.appendChild(btn);
        });
        dayDiv.appendChild(dropdown);
        openDropdown = dropdown;
        openDropdownDay = dayDiv;
        if (arrowBtn) arrowBtn.classList.add('open');
    }
    // Clicking anywhere closes dropdown (still ok with new fix)
    document.addEventListener('click', () => {
        closeDropdown();
    });

    // == Calendar Render ==
    function renderCalendar(){
        closeDropdown && closeDropdown();
        if (!calendarGrid || !calendarDetails) return;
        calendarGrid.innerHTML='';
        calendarDetails.innerHTML='Select a date to view schedule.';

        const year=currentDate.getFullYear();
        const month=currentDate.getMonth();
        const monthText=currentDate.toLocaleString('default',{month:'long', year:'numeric'});
        if (monthLabel) monthLabel.textContent=monthText;
        if(mobileMonthLabel) mobileMonthLabel.textContent=monthText;

        const firstDay=new Date(year, month,1).getDay();
        const daysInMonth=new Date(year,month+1,0).getDate();
        for(let i=0;i<firstDay;i++) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = "calendar-day";
            calendarGrid.appendChild(emptyDiv);
        }
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const events = Array.isArray(window.scheduleData) && window.scheduleData.length
                ? window.scheduleData.filter(e => e.schedule_date === dateStr)
                : []; // Ensure always an array

            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');

            // Use 'data-date' for debugging/lookup
            dayDiv.setAttribute('data-date', dateStr);

            // Day number
            const dayNumDiv = document.createElement('div');
            dayNumDiv.textContent = d;
            dayDiv.appendChild(dayNumDiv);

            // Show tasks in day box if present
            if (events.length) {
                // Create a tasks holder, always visible if day has events
                const tasksDiv = document.createElement('div');
                tasksDiv.className = 'day-tasks';

                if (events.length === 1) {
                    const e = events[0];
                    const btn = document.createElement('button');
                    btn.className = 'task-btn';
                    btn.textContent = isMobileView() ? '1' : e.task;
                    btn.title = `${e.task} (${e.status_label || ''})`;
                    const key = getStatusKey(e.status_label || '');
                    if (key) btn.classList.add('status-' + key + '-bg');
                    btn.onclick = function(ev) {
                        ev.stopPropagation();
                        openModal([e]);
                    };
                    tasksDiv.appendChild(btn);
                } else if (events.length > 1) {
                    // --- FIX: correct render for >1 tasks in a day ---
                    const first = events[0];

                    // First visible task
                    const firstBtn = document.createElement('button');
                    firstBtn.className = 'task-btn';
                    firstBtn.textContent = isMobileView() ? '1' : first.task;
                    firstBtn.title = `${first.task} (${first.status_label || ''})`;
                    const firstKey = getStatusKey(first.status_label || '');
                    if (firstKey) firstBtn.classList.add('status-' + firstKey + '-bg');
                    firstBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        openModal([first]);
                    };
                    tasksDiv.appendChild(firstBtn);

                    // Arrow + counter wrapper
                    const moreWrap = document.createElement('div');
                    moreWrap.className = 'more-tasks-wrap';

                    const arrowBtn = document.createElement('button');
                    arrowBtn.className = 'more-tasks-btn';
                    arrowBtn.innerHTML = '▾';
                    arrowBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        toggleTaskDropdown(dayDiv, events, arrowBtn);
                    };

                    // Start MODIFIED BLOCK
                    if (isMobileView()) {
                        // On mobile, show only the arrow, no counter.
                        moreWrap.appendChild(arrowBtn);
                    } else {
                        // On desktop, show arrow and counter.
                        moreWrap.appendChild(arrowBtn);
                        const counter = document.createElement('span');
                        counter.className = 'task-counter';
                        counter.textContent = `+${events.length - 1}`;
                        moreWrap.appendChild(counter);
                    }
                    // End MODIFIED BLOCK

                    tasksDiv.appendChild(moreWrap);
                }
                dayDiv.appendChild(tasksDiv);
            } // end events.length

            dayDiv.addEventListener('click', function() {
                // Show all event details in calendarDetails if exists
                if (events.length) {
                    let detailsHtml = `<strong>${dateStr}</strong><br>`;
                    detailsHtml += events.map(e =>
                        `• ${e.task} – ${e.location ? e.location : ''}`
                    ).join('<br>');
                    calendarDetails.innerHTML = detailsHtml;
                } else {
                    calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>No scheduled maintenance.`;
                }
            });

            calendarGrid.appendChild(dayDiv);
        }
    }

    // Optional: Auto-hide scroll indicator when not scrollable
    function updateCalendarDetailsScrollHint() {
        const details = document.getElementById('calendarDetails');
        const indicator = document.querySelector('.scroll-indicator');
        if (!details || !indicator) return;

        indicator.style.display =
            details.scrollHeight > details.clientHeight ? 'block' : 'none';
    }

    // Make sure to call renderCalendar on load and when month/view changes
    if (typeof prevMonthBtn !== "undefined" && prevMonthBtn && nextMonthBtn) {
        prevMonthBtn.onclick = ()=>{
            currentDate.setMonth(currentDate.getMonth()-1);
            renderCalendar();
        };
        nextMonthBtn.onclick = ()=>{
            currentDate.setMonth(currentDate.getMonth()+1);
            renderCalendar();
        };
    }
    // Patch renderCalendar to auto-update scroll indicator
    const originalRenderCalendar = renderCalendar;
    renderCalendar = function () {
        originalRenderCalendar();
        setTimeout(updateCalendarDetailsScrollHint, 0);
    };

    renderCalendar();
    applyStatusClassesToList();

    // --- Schedule search (desktop) ---
    if (scheduleSearch && scheduleListHolder) {
        scheduleSearch.addEventListener('input', function() {
            const searchVal = this.value.trim().toLowerCase();
            const items = scheduleListHolder.querySelectorAll('.schedule-item');
            let shownCount = 0;
            if (!searchVal.length) {
                items.forEach(i => { i.style.display = ''; });
                if (noResultMsg) noResultMsg.style.display = 'none';
                return;
            }
            items.forEach(item => {
                const task = item.getAttribute('data-task') || '';
                const loc = item.getAttribute('data-location') || '';
                const date = item.getAttribute('data-date') || '';
                const cat = item.getAttribute('data-category') || '';
                const stat = item.getAttribute('data-status') || '';
                const prio = item.getAttribute('data-priority') || '';
                if (
                    task.includes(searchVal) ||
                    loc.includes(searchVal) ||
                    date.includes(searchVal) ||
                    cat.includes(searchVal) ||
                    stat.includes(searchVal) ||
                    prio.includes(searchVal)
                ) {
                    item.style.display = '';
                    shownCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            if (noResultMsg) {
                if (shownCount === 0) {
                    noResultMsg.style.display = '';
                } else {
                    noResultMsg.style.display = 'none';
                }
            }
        });
    }

    // --- Show calendar view/list view logic ---
    function showCalendarView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.remove('hidden');
        scheduleView.classList.add('hidden');
        showingCalendar = true;
        updateMobileControls();
        updateWeekdayLabels();
    }
    function showListView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.add('hidden');
        scheduleView.classList.remove('hidden');
        showingCalendar = false;
        updateMobileControls();
        updateWeekdayLabels();
    }
    if (toCalendarBtn) toCalendarBtn.onclick = showCalendarView;
    if (toListBtn) toListBtn.onclick = showListView;

    // -- Mobile controls
    function updateMobileControls() {
        if (!mobileListControls || !mobileCalendarControls) return;
        if (!isMobileView()) {
            mobileListControls.style.display = "none";
            mobileCalendarControls.style.display = "none";
            return;
        }
        if (showingCalendar) {
            mobileCalendarControls.style.display = "";
            mobileListControls.style.display = "none";
            if (mobileMonthLabel && monthLabel) {
                mobileMonthLabel.textContent = monthLabel.textContent;
            }
        } else {
            mobileListControls.style.display = "";
            mobileCalendarControls.style.display = "none";
        }
    }
    function syncMobileControls() {
        if (!isMobileView()) return;
        updateMobileControls();
    }

    // Responsive calendar re-render
    let lastMobileState = isMobileView();
    window.addEventListener('resize', () => {
        updateMobileControls();
        updateWeekdayLabels && updateWeekdayLabels();
        const nowMobile = isMobileView();
        if (nowMobile !== lastMobileState) {
            lastMobileState = nowMobile;
            closeDropdown();
            renderCalendar();
        }
    });

    // MOBILE BUTTONS
    if (mobileToCalendarBtn) mobileToCalendarBtn.onclick = showCalendarView;
    if (mobileToListBtn) mobileToListBtn.onclick = showListView;
    if (mobilePrevMonth) mobilePrevMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
        updateMobileControls();
    };
    if (mobileNextMonth) mobileNextMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
        updateMobileControls();
    };

    // Mobile search sync
    if (mobileScheduleSearch && scheduleSearch) {
        mobileScheduleSearch.addEventListener('input', e => {
            scheduleSearch.value = e.target.value;
            scheduleSearch.dispatchEvent(new Event('input'));
        });
    }

    // INITIAL state
    updateMobileControls();

    // Weekday label helper (exported globally for resize use below)
    window.updateWeekdayLabels = function updateWeekdayLabels() {
        const desktopDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const shortDays = ['S','M','T','W','T','F','S'];
        const weekdayDivs = document.querySelectorAll('.calendar-weekdays div');
        if (!weekdayDivs.length) return;
        if (window.innerWidth <= 768) {
            weekdayDivs.forEach((el, i) => { el.textContent = shortDays[i]; });
        } else {
            weekdayDivs.forEach((el, i) => { el.textContent = desktopDays[i]; });
        }
    };

    window.addEventListener('load', updateWeekdayLabels);
    window.addEventListener('resize', updateWeekdayLabels);

    // =====================
    // Custom Floating Date Picker Overlay - PATCHED PER PROMPT
    // =====================
    const overlayPicker = document.getElementById('customDatePickerOverlay');
    const overlayInput  = document.getElementById('overlayDatePicker');
    // Retain reference for legacy picker (should not be shown)
    // const pickerDate = getSafeElem('pickerDate');

    function openDatePicker(event) {
        if (!overlayPicker || !overlayInput) return;

        // Sync with current calendar date
        const y = currentDate.getFullYear();
        const m = String(currentDate.getMonth() + 1).padStart(2, '0');
        const d = String(currentDate.getDate()).padStart(2, '0');
        overlayInput.value = `${y}-${m}-${d}`;

        // Position overlay below clicked label with mobile-aware logic
        const rect = event.target.getBoundingClientRect();
        let top = rect.bottom + window.scrollY + 4;
        let left = rect.left + window.scrollX;

        // MOBILE: fixed positioning below mobileMonthLabel, not floating/awkward (and prevent offscreen right)
        const overlayWidth = overlayPicker.offsetWidth || 180;
        if (window.innerWidth <= 768) {
            // position: fixed, so we use rect relative to viewport (no scrollY)
            top = rect.bottom + 4;
            left = rect.left;
            if (left + overlayWidth > window.innerWidth - 8) {
                left = window.innerWidth - overlayWidth - 8;
            }
            if (top + overlayPicker.offsetHeight > window.innerHeight - 8) {
                top = window.innerHeight - overlayPicker.offsetHeight - 8;
            }
            overlayPicker.style.position = 'fixed';
        } else {
            // desktop: classic absolute + scroll
            if (left + overlayWidth > window.innerWidth - 8) {
                left = window.innerWidth - overlayWidth - 8;
            }
            overlayPicker.style.position = 'absolute';
        }

        overlayPicker.style.top = top + "px";
        overlayPicker.style.left = left + "px";
        overlayPicker.style.display = "block";

        overlayInput.focus();
        // Highlight all for immediate typing UX
        if (overlayInput.setSelectionRange) {
            overlayInput.setSelectionRange(0, overlayInput.value.length);
        }
    }

    // Close overlay if clicked outside
    document.addEventListener('click', function(e) {
        if (!overlayPicker.contains(e.target) && e.target !== monthLabel && e.target !== mobileMonthLabel) {
            overlayPicker.style.display = 'none';
        }
    });

    // Prevent click bubbling (so picker stays open if clicking on overlay)
    if (overlayPicker) {
        overlayPicker.addEventListener('click', function(e) { e.stopPropagation(); });
    }

    // Restrict typing in the year part to 4 digits only
    if (overlayInput) {
        // Prevent typing more than 4 numbers in the year part
        overlayInput.addEventListener('beforeinput', function(e) {
            // Only for direct text input (not deletes, not navigation)
            if (
                e.inputType.startsWith('insert') &&
                typeof e.data === 'string' &&
                e.data.match(/[0-9]/)
            ) {
                // Get the value as it will be after addition
                let inputValue = overlayInput.value;
                const selectionStart = overlayInput.selectionStart;
                const selectionEnd = overlayInput.selectionEnd;
                // Simulate insertion
                inputValue = inputValue.slice(0, selectionStart) + e.data + inputValue.slice(selectionEnd);

                // Only validate if insertion is in the year part
                // year: index 0-3
                if (selectionStart <= 4) {
                    const yearPart = inputValue.slice(0, 4);
                    // Count only digit characters in year part
                    const yearDigits = (yearPart.match(/\d/g) || []).join('');
                    if (yearDigits.length > 4) {
                        e.preventDefault();
                        return;
                    }
                }
            }
        });

        // Handle typing/date preview as user types (day → month → year typing flow)
        overlayInput.addEventListener('input', function(e) {
            const val = overlayInput.value;
            if (!val) return;
            // Enforce year part to have a maximum of 4 digits
            let [y, m, d] = val.split('-');
            if (y && y.length > 4) {
                y = y.slice(0, 4);
                // Set the trimmed value (without triggering another input)
                const newVal = [y,m,d].filter(Boolean).join('-');
                overlayInput.value = newVal;
            }

            // Only proceed if valid parts
            const parts = overlayInput.value.split('-').map(Number);
            if (parts.length === 3) {
                const [yy, mm, dd] = parts;
                if (!isNaN(yy) && !isNaN(mm) && !isNaN(dd)) {
                    currentDate = new Date(yy, mm - 1, dd);
                    renderCalendar();
                }
            }
        });
        // Apply date only when Enter (or Escape to cancel)
        overlayInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const val = overlayInput.value;
                if (val) {
                    let [y, m, d] = val.split('-');
                    // Don't allow more than 4 digits in year
                    if (y && y.length > 4) {
                        y = y.slice(0, 4);
                    }
                    currentDate = new Date(Number(y), Number(m) - 1, Number(d));
                    renderCalendar();

                    const tasks = window.scheduleData.filter(
                        t => t.schedule_date === `${y}-${m}-${d}`
                    );
                    if (tasks.length === 1) openModal(tasks);
                    else if (tasks.length > 1) openTaskChooser(`${y}-${m}-${d}`, tasks);
                }
                overlayPicker.style.display = 'none';
            } else if (e.key === 'Escape') {
                overlayPicker.style.display = 'none';
            }
        });
    }

    // Wire labels to open our overlay picker
    if (monthLabel) {
        monthLabel.title = "Click to jump date";
        monthLabel.style.cursor = "pointer";
        monthLabel.addEventListener('click', openDatePicker);
    }
    if (mobileMonthLabel) {
        mobileMonthLabel.title = "Click to jump date";
        mobileMonthLabel.style.cursor = "pointer";
        mobileMonthLabel.addEventListener('click', openDatePicker);
    }
}); // --- END DOMContentLoaded ---

// --- Profile Picture safety ---
function handleProfilePicture() {
    const img = document.getElementById('profileImg');
    const fallback = document.getElementById('profileFallbackIcon');
    if (!img) return;
    img.onerror = () => { img.style.display = 'none'; fallback && (fallback.style.display = 'flex'); };
    img.onload = () => { img.style.display = 'block'; fallback && (fallback.style.display = 'none'); };
    if (!img.src || img.src.endsWith('profile.png')) {
        img.style.display = 'none';
        fallback && (fallback.style.display = 'flex');
    }
}
document.addEventListener('DOMContentLoaded', handleProfilePicture);
</script>

<script>
let inactivityTime = 20 * 60 * 1000; // 20 minutes
let inactivityTimer;

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        // Silent logout (no notification)
        window.location.href = 'logout.php';
    }, inactivityTime);
}

// Events that count as activity
['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer, true);
});

// Start timer on load
resetInactivityTimer();
</script>

</body>
</html>