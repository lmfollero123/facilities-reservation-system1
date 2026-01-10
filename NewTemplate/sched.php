<?php
session_start();
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

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Fetch schedules
$schedules = [];
$sql = "SELECT * FROM maintenance_schedule ORDER BY schedule_date ASC";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $schedules = $result->fetch_all(MYSQLI_ASSOC);
}

// Logout
if (isset($_GET['logout'])) {
    // Set log out notification for next login page
    setNotification('info', 'Successfully logged out.');
    // Clear all session data (but preserve notification)
    $notif = $_SESSION['notification'];
    session_unset();
    session_destroy();
    // Session destroyed, start new to save notification
    session_start();
    $_SESSION['notification'] = $notif;
    header("Location: login.php");
    exit;
}
?>

<script>
const scheduleData = <?= json_encode($schedules, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maintenance Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
body {
    margin: 0;
    padding: 0;
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
}
body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.35);
    backdrop-filter: blur(6px);
    z-index: 0;
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
}
.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    padding: 20px 0;
    overflow-y: auto;
}
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
}
.sidebar-nav .site-logo img {
    width: 120px;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
}
.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 20px;
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
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
.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
}
.sidebar-nav .user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.2);
}
.sidebar-nav .user-welcome,
.sidebar-nav .user-rights {
    text-align: center;
    color: #000000;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 5px;
}
/* Logout Modal Custom Design (matching @reports.php) */
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
.sidebar-nav .logout-btn {
    background: #3762c8;
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s ease;
}
.sidebar-nav .logout-btn:hover {
    background: #3762c8;
    color: #fff;
    transform: translateY(-2px) scale(1.02);
}
.main-content {
    margin-left: 250px;
    padding: 20px 80px;
    position: relative;
    z-index: 1;
    padding-bottom: 0px;
}
.card {
    background:rgba(255,255,255,.92);
    border-radius:22px;
    padding:30px;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}
.toggle-btn{
    margin-top:20px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}
.schedule-item{
    display:flex;
    justify-content:space-between;
    padding:14px 0;
    border-bottom:1px solid rgba(0,0,0,.1);
}
.schedule-date{font-weight:600}
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
</style>
</head>

<body>
<div class="sidebar-nav">
    <div class="sidebar-top">
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
    <div class="sidebar-divider"></div>
        </div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link">Dashboard</a></li>
            <li><a href="requests.php" class="nav-link">Requests</a></li>
            <li><a href="reports.php" class="nav-link">Reports</a></li>
            <li><a href="#" class="nav-link active">Maintenance Schedule</a></li>
        </ul>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome">Welcome, <?= htmlspecialchars($firstName) ?></div>
        <button id="logoutBtn" class="logout-btn">Logout</button>
    </div>
</div>

<div class="main-content">

    <div class="card">

        <!-- CALENDAR VIEW -->
        <div id="calendarView">
                <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel"></span>
                <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">&#8594;</button>
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
            <div class="calendar-details" id="calendarDetails">
                Select a date to view schedule.
            </div>
        </div>
        <!-- LIST VIEW -->
        <div id="scheduleView" class="hidden">
            <!-- Search Input for Schedule List View -->
            <input id="scheduleSearch" type="text" placeholder="Search by task, location, or date...">
            <div id="scheduleListHolder">
            <?php if (empty($schedules)): ?>
                <p id="noScheduleMsg">No scheduled maintenance.</p>
            <?php else: foreach ($schedules as $row): ?>
                <div class="schedule-item" 
                    data-task="<?= htmlspecialchars(strtolower($row['task'])) ?>"
                    data-location="<?= htmlspecialchars(strtolower($row['location'])) ?>"
                    data-date="<?= htmlspecialchars(strtolower(date("F d, Y", strtotime($row['schedule_date']))) . '|' . strtolower($row['schedule_date'])) ?>">
                    <div>
                        <strong><?= htmlspecialchars($row['task']) ?></strong><br>
                        <?= htmlspecialchars($row['location']) ?>
                    </div>
                    <div class="schedule-date">
                        <?= date("F d, Y", strtotime($row['schedule_date'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
                <p id="noResultMsg" style="display:none;">No matching data or result.</p>
            <?php endif; ?>
            </div>
        </div>

        <button id="toggleBtn" class="toggle-btn">View Schedule</button>
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

<script>
const calendarGrid = document.getElementById('calendarGrid');
const calendarDetails = document.getElementById('calendarDetails');
const monthLabel = document.getElementById('monthLabel');
const toggleBtn = document.getElementById('toggleBtn');
const calendarView = document.getElementById('calendarView');
const scheduleView = document.getElementById('scheduleView');

let currentDate = new Date();
let showingCalendar = true;

toggleBtn.onclick = () => {
    showingCalendar = !showingCalendar;
    calendarView.classList.toggle('hidden');
    scheduleView.classList.toggle('hidden');
    toggleBtn.textContent = showingCalendar ? 'View Schedule' : 'View Calendar';
};

// Modal
const taskModal = document.getElementById('taskModal');
const modalBody = document.getElementById('modalBody');
const modalClose = document.getElementById('modalClose');
modalClose.onclick = ()=>taskModal.classList.add('hidden');
window.onclick = (e)=>{if(e.target===taskModal) taskModal.classList.add('hidden');};

function openModal(tasks){
    modalBody.innerHTML='';
    tasks.forEach(t=>{
        const div=document.createElement('div');
        div.className='modal-task-item';
        div.innerHTML=`<strong>Task:</strong> ${t.task}<br>
                       <strong>Location:</strong> ${t.location}<br>
                       <strong>Date:</strong> ${t.schedule_date}`;
        modalBody.appendChild(div);
    });
    taskModal.classList.remove('hidden');
}

function renderCalendar(){
    calendarGrid.innerHTML='';
    calendarDetails.innerHTML='Select a date to view schedule.';
    const year=currentDate.getFullYear();
    const month=currentDate.getMonth();
    monthLabel.textContent=currentDate.toLocaleString('default',{month:'long', year:'numeric'});
    const firstDay=new Date(year, month,1).getDay();
    const daysInMonth=new Date(year,month+1,0).getDate();
    for(let i=0;i<firstDay;i++) calendarGrid.innerHTML+='<div></div>';

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const events = scheduleData.filter(e => e.schedule_date === dateStr);

        const div = document.createElement('div');
        div.className = 'calendar-day' + (events.length ? ' has-event' : '');

        const dayNum = document.createElement('div');
        dayNum.textContent = d;
        div.appendChild(dayNum);

        if (events.length) {
            const tasksDiv = document.createElement('div');
            tasksDiv.className = 'day-tasks';
            events.forEach((e, i) => {
                const btn = document.createElement('button');
                btn.textContent = i + 1;
                btn.className = 'task-btn';
                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    openModal([e]);
                });
                tasksDiv.appendChild(btn);
            });
            div.appendChild(tasksDiv);
        }

        div.addEventListener('click', () => {
            if(events.length){
                calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>`;
                calendarDetails.innerHTML += events.map(e => `• ${e.task} – ${e.location}`).join('<br>');
            } else {
                calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>No scheduled maintenance.`;
            }
        });

        calendarGrid.appendChild(div);
    }
}

document.getElementById('prevMonth').onclick=()=>{currentDate.setMonth(currentDate.getMonth()-1); renderCalendar();}
document.getElementById('nextMonth').onclick=()=>{currentDate.setMonth(currentDate.getMonth()+1); renderCalendar();}
renderCalendar();

// Schedule LIST VIEW SEARCH functionality
const scheduleSearch = document.getElementById('scheduleSearch');
const scheduleListHolder = document.getElementById('scheduleListHolder');
const noResultMsg = document.getElementById('noResultMsg');

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
            if (
                task.includes(searchVal) ||
                loc.includes(searchVal) ||
                date.includes(searchVal)
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

// Logout Alert Modal Logic - REFERENCE DESIGN FROM reports.php
const logoutBtn = document.getElementById('logoutBtn');
const logoutAlertBackdrop = document.getElementById('logoutAlertBackdrop');
const logoutCancelBtn = document.getElementById('logoutCancelBtn');
const logoutConfirmBtn = document.getElementById('logoutConfirmBtn');

// Show modal on logout button click
logoutBtn.addEventListener('click', () => {
    logoutAlertBackdrop.classList.add("active");
});

// Hide modal on cancel
logoutCancelBtn.addEventListener('click', () => {
    logoutAlertBackdrop.classList.remove("active");
});

// Confirm logout
logoutConfirmBtn.addEventListener('click', () => {
    window.location.href = 'sched.php?logout=1';
});

// Click on backdrop (not the modal) closes modal
logoutAlertBackdrop.addEventListener('mousedown', (e) => {
    if (e.target === logoutAlertBackdrop) {
        logoutAlertBackdrop.classList.remove("active");
    }
});
</script>

</body>
</html>