<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';

/*
|--------------------------------------------------------------------------
| CHATBOT BACKEND (POST REQUEST)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');

    $pdo = db();
    $userId   = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['name'] ?? 'User';
    $message  = trim($_POST['message'] ?? '');

    if ($message === '') {
        echo json_encode(['reply' => 'Please enter a message.']);
        exit;
    }

    $msg = strtolower($message);

    /* ------------------------------
     | BOOKING POLICY
     |------------------------------*/
    if (str_contains($msg, 'policy') || str_contains($msg, 'rule')) {
        echo json_encode([
            'reply' =>
                "Booking Policies:\n\n" .
                "• All facilities are FREE for residents\n" .
                "• Maximum of 3 active reservations per user\n" .
                "• Bookings require administrator approval\n" .
                "• Only one booking per day is allowed\n" .
                "• Rescheduling is allowed up to 3 days before the event"
        ]);
        exit;
    }

    /* ------------------------------
     | MY RESERVATIONS
     |------------------------------*/
    if (str_contains($msg, 'my reservation') || str_contains($msg, 'my booking')) {

        if (!$userId) {
            echo json_encode(['reply' => 'Please log in to view your reservations.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT r.reservation_date, r.time_slot, r.status, f.name
            FROM reservations r
            JOIN facilities f ON r.facility_id = f.id
            WHERE r.user_id = :uid
            ORDER BY r.reservation_date DESC
            LIMIT 5
        ");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            echo json_encode(['reply' => 'You have no reservations.']);
            exit;
        }

        $reply = "Your Recent Reservations:\n\n";
        foreach ($rows as $r) {
            $reply .=
                "- {$r['name']}\n" .
                "  Date: {$r['reservation_date']}\n" .
                "  Time: {$r['time_slot']}\n" .
                "  Status: " . ucfirst($r['status']) . "\n\n";
        }

        echo json_encode(['reply' => $reply]);
        exit;
    }

    /* ------------------------------
     | DATE AVAILABILITY (YYYY-MM-DD)
     |------------------------------*/
    if (preg_match('/\d{4}-\d{2}-\d{2}/', $msg, $match)) {

        $date = $match[0];

        $stmt = $pdo->prepare("
            SELECT 
                f.name AS facility,
                f.status AS facility_status,
                r.time_slot,
                r.status AS reservation_status
            FROM facilities f
            LEFT JOIN reservations r
                ON r.facility_id = f.id
                AND r.reservation_date = :date
            ORDER BY f.name
        ");
        $stmt->execute(['date' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            echo json_encode(['reply' => "No schedule found for {$date}."]);
            exit;
        }

        $reply = "Facility Availability for {$date}:\n\n";

        foreach ($rows as $row) {
            if ($row['facility_status'] === 'maintenance') {
                $reply .= "- {$row['facility']} : Under Maintenance\n";
            } else {
                $status = $row['reservation_status'] ?? 'Available';
                $slot   = $row['time_slot'] ?? 'All Time Slots';
                $reply .= "- {$row['facility']} ({$slot}) : {$status}\n";
            }
        }

        echo json_encode(['reply' => $reply]);
        exit;
    }

    /* ------------------------------
     | GENERAL AVAILABILITY
     |------------------------------*/
    if (str_contains($msg, 'available') || str_contains($msg, 'facility')) {
        echo json_encode([
            'reply' =>
                "I can check facility availability for you.\n\n" .
                "Please enter a date using this format:\n" .
                "YYYY-MM-DD\n\n" .
                "Example: 2025-01-15"
        ]);
        exit;
    }

    /* ------------------------------
     | FALLBACK
     |------------------------------*/
    echo json_encode([
        'reply' =>
            "Hello {$userName}.\n\n" .
            "You may ask about:\n" .
            "• Facility availability\n" .
            "• Booking policies\n" .
            "• Your reservations\n\n" .
            "Example: What facilities are available on 2025-01-15?"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| CHATBOT UI (GET REQUEST)
|--------------------------------------------------------------------------
*/
if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

$pageTitle = 'AI Assistant | LGU Facilities Reservation';
$userName  = $_SESSION['name'] ?? 'User';

ob_start();
?>

<!-- =======================
     MINIMAL GOVERNMENT UI
======================== -->

<style>
.gov-chat-container {
    max-width: 720px;
    margin: 0 auto;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-family: Arial, sans-serif;
    background: #ffffff;
}
.gov-chat-header {
    padding: 12px 16px;
    background: #1f3a5f;
    color: #ffffff;
    font-weight: bold;
}
.gov-chat-messages {
    padding: 16px;
    height: 420px;
    overflow-y: auto;
    background: #f9fafb;
}
.gov-message {
    margin-bottom: 12px;
    display: flex;
}
.gov-message.user {
    justify-content: flex-end;
}
.gov-message.bot {
    justify-content: flex-start;
}
.bubble {
    max-width: 75%;
    padding: 10px 12px;
    border-radius: 4px;
    font-size: 14px;
    line-height: 1.6;
}
.gov-message.bot .bubble {
    background: #ffffff;
    border: 1px solid #d1d5db;
}
.gov-message.user .bubble {
    background: #1f3a5f;
    color: #ffffff;
}
.gov-chat-input {
    display: flex;
    border-top: 1px solid #d1d5db;
}
.gov-chat-input input {
    flex: 1;
    padding: 12px;
    border: none;
}
.gov-chat-input button {
    padding: 0 20px;
    border: none;
    background: #1f3a5f;
    color: white;
    cursor: pointer;
}
</style>

<div class="gov-chat-container">
    <div class="gov-chat-header">
        LGU Virtual Assistant
    </div>

    <div class="gov-chat-messages" id="chatbot-messages">
        <div class="gov-message bot">
            <div class="bubble">
                Hello <?= htmlspecialchars($userName); ?>.<br><br>
                I can assist you with:
                <ul>
                    <li>Facility availability</li>
                    <li>Booking policies</li>
                    <li>Your reservations</li>
                </ul>
                Please type your question below.
            </div>
        </div>
    </div>

    <form id="chatbot-form" class="gov-chat-input">
        <input type="text" id="chatbot-input" placeholder="Type your message here..." required>
        <button type="submit">Send</button>
    </form>
</div>

<script>
const form = document.getElementById('chatbot-form');
const input = document.getElementById('chatbot-input');
const messages = document.getElementById('chatbot-messages');

form.addEventListener('submit', function(e) {
    e.preventDefault();
    const message = input.value.trim();
    if (!message) return;

    addMessage(message, 'user');
    input.value = '';

    fetch('ai_chatbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ message })
    })
    .then(res => res.json())
    .then(data => addMessage(data.reply, 'bot'))
    .catch(() => addMessage('Service unavailable. Please try again.', 'bot'));
});

function addMessage(text, type) {
    const msg = document.createElement('div');
    msg.className = 'gov-message ' + type;
    msg.innerHTML = `<div class="bubble">${escapeHtml(text)}</div>`;
    messages.appendChild(msg);
    messages.scrollTop = messages.scrollHeight;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/\n/g, '<br>');
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
