<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';
require_once __DIR__ . '/../../../../config/chatbot_responses.php';

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
        echo json_encode(['reply' => getRandomResponse(getEmptyMessageResponses())]);
        exit;
    }

    // Classify intent using ML model
    $intent = 'unknown';
    $confidence = 0.0;
    $reply = '';
    
    if (function_exists('classifyChatbotIntent')) {
        try {
            $intentResult = classifyChatbotIntent($message);
            $intent = $intentResult['intent'] ?? 'unknown';
            $confidence = $intentResult['confidence'] ?? 0.0;
        } catch (Exception $e) {
            error_log("Chatbot ML error: " . $e->getMessage());
        }
    }

    $msg = strtolower(trim($message));
    $ML_CONFIDENCE_THRESHOLD = 0.5; // Use ML if confidence is above 50%

    // Handle simple greetings that might not be caught by ML
    $simpleGreetings = ['hi', 'hello', 'hey', 'greetings', 'good morning', 'good afternoon', 'good evening'];
    if (in_array($msg, $simpleGreetings) || in_array($msg, array_map(function($g) { return $g . '!'; }, $simpleGreetings))) {
        $reply = getRandomResponse(getGreetingResponses($userName));
        echo json_encode(['reply' => $reply]);
        exit;
    }

    // Use ML intent classification if confidence is high enough
    if ($confidence >= $ML_CONFIDENCE_THRESHOLD) {
        switch ($intent) {
            case 'greeting':
                $reply = getRandomResponse(getGreetingResponses($userName));
                break;
                
            case 'goodbye':
                $reply = getRandomResponse(getGoodbyeResponses());
                break;
                
            case 'help':
                $reply = getRandomResponse(getHelpResponses());
                break;
                
            case 'list_facilities':
                $stmt = $pdo->query("SELECT name, location FROM facilities WHERE status = 'available' ORDER BY name LIMIT 10");
                $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($facilities)) {
                    $reply = "Here are the available facilities:\n\n";
                    foreach ($facilities as $facility) {
                        $reply .= "• " . htmlspecialchars($facility['name']);
                        if ($facility['location']) {
                            $reply .= " (" . htmlspecialchars($facility['location']) . ")";
                        }
                        $reply .= "\n";
                    }
                    $reply .= getRandomResponse(getFacilitiesFoundResponses());
                } else {
                    $reply = getRandomResponse(getNoFacilitiesResponses());
                }
                break;
                
            case 'check_availability':
                $reply = getRandomResponse(getCheckAvailabilityResponses());
                break;
                
            case 'book_facility':
                $reply = getRandomResponse(getBookFacilityResponses());
                break;
                
            case 'booking_rules':
                $reply = getRandomResponse(getBookingRulesResponses());
                break;
                
            case 'my_bookings':
                if (!$userId) {
                    $reply = "I'd love to show you your reservations, but you'll need to log in first. Once you're logged in, I can help you see all your bookings!";
                } else {
                    $stmt = $pdo->prepare("
                        SELECT r.reservation_date, r.time_slot, r.status, f.name
                        FROM reservations r
                        JOIN facilities f ON r.facility_id = f.id
                        WHERE r.user_id = :uid
                        ORDER BY r.reservation_date DESC
                        LIMIT 5
                    ");
                    $stmt->execute(['uid' => $userId]);
                    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($reservations)) {
                        $reply = "Here are your recent reservations:\n\n";
                        foreach ($reservations as $res) {
                            $statusIcon = $res['status'] === 'approved' ? '✅' : ($res['status'] === 'pending' ? '⏳' : ($res['status'] === 'denied' ? '❌' : '🚫'));
                            $reply .= "{$statusIcon} " . htmlspecialchars($res['name']) . " - " . $res['reservation_date'] . 
                                     " (" . htmlspecialchars($res['time_slot']) . ") - " . ucfirst($res['status']) . "\n";
                        }
                        $reply .= getRandomResponse(getBookingsFoundResponses());
                    } else {
                        $reply = getRandomResponse(getNoBookingsResponses());
                    }
                }
                break;
                
            case 'cancel_booking':
                $reply = getRandomResponse(getCancelBookingResponses());
                break;
                
            case 'facility_details':
                $reply = "I'd be happy to help! Could you tell me which specific facility you're interested in? Or you can browse all facilities on the 'Book Facility' page to see details, capacity, amenities, and more.";
                break;
        }
    }

    // If ML didn't provide a response or confidence was too low, use rule-based matching
    if (empty($reply)) {
        /* ------------------------------
         | BOOKING POLICY
         |------------------------------*/
        if (str_contains($msg, 'policy') || str_contains($msg, 'rule')) {
            $reply = "Booking Policies:\n\n" .
                    "• All facilities are FREE for residents\n" .
                    "• Maximum of 3 active reservations per user\n" .
                    "• Bookings require administrator approval\n" .
                    "• Only one booking per day is allowed\n" .
                    "• Rescheduling is allowed up to 3 days before the event";
        }

        /* ------------------------------
         | MY RESERVATIONS
         |------------------------------*/
        else if (str_contains($msg, 'my reservation') || str_contains($msg, 'my booking')) {
            if (!$userId) {
                $reply = "I'd love to show you your reservations, but you'll need to log in first. Once you're logged in, I can help you see all your bookings!";
            } else {
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
                    $reply = getRandomResponse(getNoBookingsResponses());
                } else {
                    $reply = "Here are your recent reservations:\n\n";
                    foreach ($rows as $r) {
                        $statusIcon = $r['status'] === 'approved' ? '✅' : ($r['status'] === 'pending' ? '⏳' : ($r['status'] === 'denied' ? '❌' : '🚫'));
                        $reply .= "{$statusIcon} {$r['name']} - {$r['reservation_date']} ({$r['time_slot']}) - " . ucfirst($r['status']) . "\n";
                    }
                    $reply .= getRandomResponse(getBookingsFoundResponses());
                }
            }
        }
        /* ------------------------------
         | DATE AVAILABILITY (YYYY-MM-DD)
         |------------------------------*/
        else if (preg_match('/\d{4}-\d{2}-\d{2}/', $msg, $match)) {
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
                $reply = "No schedule found for {$date}.";
            } else {
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
            }
        }
        /* ------------------------------
         | GENERAL AVAILABILITY
         |------------------------------*/
        else if (str_contains($msg, 'available') || str_contains($msg, 'facility')) {
            $reply = getRandomResponse(getCheckAvailabilityResponses());
        }
        /* ------------------------------
         | FALLBACK
         |------------------------------*/
        else {
            $reply = getRandomResponse(getUnknownResponses($message));
        }
    }

    echo json_encode(['reply' => $reply]);
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
