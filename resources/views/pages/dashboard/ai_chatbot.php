<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';
require_once __DIR__ . '/../../../../config/chatbot_responses.php';
// Load Gemini config explicitly (gemini_config.php is gitignored)
$geminiConfigPath = dirname(__DIR__, 4) . '/config/gemini_config.php';
if (file_exists($geminiConfigPath)) {
    require_once $geminiConfigPath;
}
require_once __DIR__ . '/../../../../config/gemini_chatbot.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';

/*
|--------------------------------------------------------------------------
| CHATBOT BACKEND (POST REQUEST)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json; charset=UTF-8');
    @set_time_limit(45);

    if (!($_SESSION['user_authenticated'] ?? false) || empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'reply' => 'Please log in to use the AI assistant.',
            'error' => 'session_expired',
        ]);
        exit;
    }

    try {
        $pdo = db();
    $userId   = (int)$_SESSION['user_id'];
    $userName = $_SESSION['name'] ?? 'User';
    $message  = trim($_POST['message'] ?? '');

    if ($message === '') {
        echo json_encode(['reply' => getRandomResponse(getEmptyMessageResponses())]);
        exit;
    }

    if (!checkGeminiChatbotRateLimit($userId)) {
        http_response_code(429);
        echo json_encode([
            'reply' => 'You have sent many messages in a short time. Please wait a few minutes before using the AI assistant again.',
            'error' => 'rate_limited',
        ]);
        exit;
    }

    // --- Gemini AI (try first when available) ---
    if (function_exists('geminiChatbotResponse') && function_exists('buildGeminiChatbotPrompt')) {
        try {
            $facStmt = $pdo->query("SELECT id, name, status, capacity, amenities, location, operating_hours FROM facilities WHERE status != 'deleted' ORDER BY name LIMIT 50");
            $facilities = $facStmt ? $facStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $userBookings = [];
            if ($userId) {
                $bStmt = $pdo->prepare("
                    SELECT r.reservation_date, r.time_slot, f.name AS facility_name
                    FROM reservations r
                    JOIN facilities f ON r.facility_id = f.id
                    WHERE r.user_id = :uid
                    ORDER BY r.reservation_date DESC
                    LIMIT 5
                ");
                $bStmt->execute(['uid' => $userId]);
                $userBookings = $bStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Same Live Occupancy engine as the dashboard strip / monitor
            $liveOccupancy = null;
            if (function_exists('frs_build_operational_occupancy_snapshot')) {
                try {
                    $liveOccupancy = frs_build_operational_occupancy_snapshot($pdo);
                    if (function_exists('frs_sanitize_occupancy_snapshot_for_public')) {
                        $liveOccupancy = frs_sanitize_occupancy_snapshot_for_public($liveOccupancy);
                    }
                } catch (Throwable $occErr) {
                    error_log('Chatbot live occupancy snapshot error: ' . $occErr->getMessage());
                    $liveOccupancy = null;
                }
            }

            $prompt = buildGeminiChatbotPrompt($facilities, $userBookings, $userName, $userId, $liveOccupancy);

            // Conversation memory (last 10 messages = 5 exchanges)
            $historyKey = 'chatbot_history_' . ($userId ?? 'guest');
            $history = $_SESSION[$historyKey] ?? [];
            if (!is_array($history)) $history = [];
            $history = array_slice($history, -10);

            $geminiResult = geminiChatbotResponse($prompt, $message, $history);
            if ($geminiResult && !empty($geminiResult['reply'])) {
                $reply = $geminiResult['reply'];
                $out = ['reply' => $reply];

                // Save to conversation history
                $history[] = ['role' => 'user', 'parts' => [['text' => $message]]];
                $history[] = ['role' => 'model', 'parts' => [['text' => $reply]]];
                $_SESSION[$historyKey] = array_slice($history, -10);

                if (!empty($geminiResult['booking']) && is_array($geminiResult['booking'])) {
                    $b = $geminiResult['booking'];
                    if (empty($b['facility_id']) && !empty($b['facility_name'])) {
                        foreach ($facilities as $f) {
                            if (stripos($f['name'], $b['facility_name']) !== false || stripos($b['facility_name'], $f['name']) !== false) {
                                $b['facility_id'] = (int)$f['id'];
                                break;
                            }
                        }
                    }
                    // Allow prefill with any partial data (facility, date, time, purpose, etc.)
                    $hasUsefulData = isset($b['facility_id']) || isset($b['reservation_date']) || isset($b['start_time']) || isset($b['end_time']) || isset($b['time_slot']) || isset($b['purpose']);
                    if ($hasUsefulData) {
                        $out['action'] = 'prefill_booking';
                        $out['data'] = $b;
                    }
                }
                echo json_encode($out);
                exit;
            }
        } catch (Throwable $e) {
            error_log('Gemini chatbot error: ' . $e->getMessage());
        }
    }

    $geminiConfigured = defined('GEMINI_API_KEY')
        && GEMINI_API_KEY !== ''
        && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';
    if ($geminiConfigured && function_exists('geminiChatbotResponse')) {
        $bookingLike = preg_match(
            '/\b(book|reserve|reservation|pa book|mag-book|mag book|risk score|conflict|availability)\b/i',
            $message
        );
        if ($bookingLike) {
            echo json_encode([
                'reply' => 'The AI assistant could not reach Gemini right now (invalid API key, quota, or network). '
                    . 'Booking prefill and smart answers need a working GEMINI_API_KEY. '
                    . 'Please use Book Facility in the dashboard for now, or ask an admin to update GEMINI_API_KEY in .env or ~/private/cprf.env.',
                'error' => 'gemini_unavailable',
            ]);
            exit;
        }
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
                        $reply .= "â€¢ " . htmlspecialchars($facility['name']);
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
                            $statusIcon = $res['status'] === 'approved' ? 'âœ…' : ($res['status'] === 'pending' ? 'â³' : ($res['status'] === 'denied' ? 'âŒ' : 'ðŸš«'));
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
            if (!function_exists('frs_resident_booking_limits_policy_bullets')) {
                require_once __DIR__ . '/../../../../config/reservation_helpers.php';
            }
            $reply = "Booking Policies:\n\n" .
                    "• All facilities are FREE for residents\n" .
                    "• Resident limits:\n" . frs_resident_booking_limits_policy_bullets() . "\n" .
                    "• Bookings require administrator approval\n" .
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
                        $statusIcon = $r['status'] === 'approved' ? 'âœ…' : ($r['status'] === 'pending' ? 'â³' : ($r['status'] === 'denied' ? 'âŒ' : 'ðŸš«'));
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
    } catch (Throwable $e) {
        error_log('Chatbot POST error: ' . $e->getMessage());
        echo json_encode([
            'reply' => 'Something went wrong while processing your message. Please try again in a moment.',
            'error' => 'server_error',
        ]);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| CHATBOT UI (GET REQUEST) — full-screen Culiat Assistant
|--------------------------------------------------------------------------
*/
if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

$pageTitle = 'Culiat Assistant | LGU Facilities Reservation';
$userName = function_exists('frs_session_display_name')
    ? frs_session_display_name('User')
    : ($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'User');
$chatUserId = (string)($_SESSION['user_id'] ?? 'guest');

ob_start();
?>

<div class="ai-chat-page" id="aiChatPage" data-user-id="<?= htmlspecialchars($chatUserId, ENT_QUOTES, 'UTF-8'); ?>">
    <aside class="ai-chat-shell">
        <header class="ai-chat-header">
            <a href="<?= htmlspecialchars(base_path()); ?>/dashboard" class="ai-chat-back" title="Back to dashboard" aria-label="Back to dashboard">←</a>
            <div class="ai-chat-header-brand">
                <div class="ai-chat-avatar" aria-hidden="true">💬</div>
                <div class="ai-chat-header-text">
                    <h1>Culiat Assistant</h1>
                    <p class="chatbot-online"><span class="chatbot-online-dot"></span> Online now</p>
                </div>
            </div>
        </header>

        <div class="ai-chat-messages chatbot-messages" id="aiChatMessages" role="log" aria-live="polite">
            <div class="message bot-message">
                <div class="message-avatar">💬</div>
                <div class="message-body">
                    <div class="message-sender">Culiat Assistant</div>
                    <div class="message-content">
                        <p>Hi <?= htmlspecialchars($userName); ?>! I can help you find facilities, check policies, view reservations, or start a booking.</p>
                        <p class="message-note">Try: &quot;Book the Convention Hall tomorrow from 2pm to 4pm for a meeting.&quot;</p>
                    </div>
                    <small class="message-meta">Just now</small>
                </div>
            </div>
        </div>

        <footer class="ai-chat-footer chatbot-input-area">
            <form id="aiChatForm" autocomplete="off">
                <div class="chatbot-composer">
                    <textarea id="aiChatInput" placeholder="Reply to Culiat Assistant…" rows="1"></textarea>
                    <button type="button" class="chatbot-voice-btn" id="aiChatVoiceBtn" aria-label="Voice input" title="Speak your message">🎤</button>
                    <button type="submit" class="chatbot-send-btn" id="aiChatSendBtn" aria-label="Send"><span>Send</span></button>
                </div>
                <p class="chatbot-voice-status" id="aiChatVoiceStatus" hidden></p>
            </form>
            <div class="chatbot-quick-actions">
                <button type="button" class="chatbot-quick-btn" data-action="available-facilities">Available Facilities</button>
                <button type="button" class="chatbot-quick-btn" data-action="booking-policy">Booking Policy</button>
                <button type="button" class="chatbot-quick-btn" data-action="my-reservations">My Reservations</button>
                <button type="button" class="chatbot-quick-btn" data-action="help">Help</button>
            </div>
        </footer>
    </aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('ai-chatbot-page');

    const page = document.getElementById('aiChatPage');
    const form = document.getElementById('aiChatForm');
    const input = document.getElementById('aiChatInput');
    const messagesContainer = document.getElementById('aiChatMessages');
    const sendBtn = document.getElementById('aiChatSendBtn');
    if (!page || !form || !input || !messagesContainer || !sendBtn) return;

    const userId = page.getAttribute('data-user-id') || 'guest';
    const CHAT_STORAGE_KEY = 'chatbot_messages_' + userId;
    const MAX_STORED_MESSAGES = 50;
    const basePath = <?= json_encode(base_path()); ?>;

    function loadStoredMessages() {
        try {
            const raw = localStorage.getItem(CHAT_STORAGE_KEY);
            if (!raw) return null;
            const arr = JSON.parse(raw);
            return Array.isArray(arr) ? arr : null;
        } catch (e) { return null; }
    }

    function saveMessages(messages) {
        try {
            localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages.slice(-MAX_STORED_MESSAGES)));
        } catch (e) {}
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTime(date) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function scrollToBottom() {
        requestAnimationFrame(function () {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        });
    }

    var chatMessages = [];

    function addMessage(text, type, skipSave) {
        const timeStr = formatTime(new Date());
        const wrapper = document.createElement('div');
        wrapper.className = 'message ' + (type === 'user' ? 'user-message' : 'bot-message');
        if (type === 'user') {
            wrapper.innerHTML = '<div class="message-body user-body"><div class="message-content"><p>' + escapeHtml(text) + '</p></div><small class="message-meta">' + timeStr + '</small></div>';
        } else {
            wrapper.innerHTML = '<div class="message-avatar">💬</div><div class="message-body"><div class="message-sender">Culiat Assistant</div><div class="message-content"><p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p></div><small class="message-meta">' + timeStr + '</small></div>';
        }
        messagesContainer.appendChild(wrapper);
        if (!skipSave) {
            chatMessages.push({ type: type, text: text, time: timeStr });
            saveMessages(chatMessages);
        }
        setTimeout(scrollToBottom, 50);
    }

    (function restoreChat() {
        const stored = loadStoredMessages();
        if (!stored || !stored.length) return;
        chatMessages = stored;
        messagesContainer.innerHTML = '';
        stored.forEach(function (m) {
            const w = document.createElement('div');
            w.className = 'message ' + (m.type === 'user' ? 'user-message' : 'bot-message');
            if (m.type === 'user') {
                w.innerHTML = '<div class="message-body user-body"><div class="message-content"><p>' + escapeHtml(m.text) + '</p></div><small class="message-meta">' + (m.time || '') + '</small></div>';
            } else {
                w.innerHTML = '<div class="message-avatar">💬</div><div class="message-body"><div class="message-sender">Culiat Assistant</div><div class="message-content"><p>' + escapeHtml(m.text).replace(/\n/g, '<br>') + '</p></div><small class="message-meta">' + (m.time || '') + '</small></div>';
            }
            messagesContainer.appendChild(w);
        });
        setTimeout(scrollToBottom, 50);
    })();

    function showTypingIndicator() {
        const id = 'typing-' + Date.now();
        const wrapper = document.createElement('div');
        wrapper.className = 'message bot-message typing';
        wrapper.id = id;
        wrapper.innerHTML = '<div class="message-avatar">💬</div><div class="message-body"><div class="message-content typing-dots"><span></span><span></span><span></span></div></div>';
        messagesContainer.appendChild(wrapper);
        setTimeout(scrollToBottom, 50);
        return id;
    }

    function removeTypingIndicator(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    page.querySelectorAll('.chatbot-quick-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const messages = {
                'available-facilities': 'What facilities are available for booking?',
                'booking-policy': 'What are the booking policies and rules?',
                'my-reservations': 'Show me my reservations',
                'help': 'I need help with the reservation system'
            };
            const action = this.getAttribute('data-action');
            if (messages[action]) {
                input.value = messages[action];
                form.dispatchEvent(new Event('submit'));
            }
        });
    });

    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const message = input.value.trim();
        if (!message) return;

        input.value = '';
        input.style.height = '38px';
        addMessage(message, 'user');

        const typingId = showTypingIndicator();
        sendBtn.disabled = true;

        const formData = new URLSearchParams();
        formData.append('message', message);

        fetch(basePath + '/dashboard/ai-chatbot', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: formData
        })
        .then(function (response) {
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                return response.text().then(function (body) {
                    throw new Error('non_json_response:' + response.status + ':' + body.slice(0, 120));
                });
            }
            return response.json().then(function (data) {
                if (!response.ok) {
                    data = data || {};
                    data.reply = data.reply || ('Request failed (' + response.status + '). Please refresh and try again.');
                }
                return data;
            });
        })
        .then(function (data) {
            removeTypingIndicator(typingId);
            addMessage(data.reply || 'I apologize, but I couldn\'t process your request. Please try again.', 'bot');
            if (data.action === 'prefill_booking' && data.data && typeof data.data === 'object') {
                const d = data.data;
                const params = new URLSearchParams();
                if (d.facility_id) params.set('facility_id', String(d.facility_id));
                if (d.reservation_date) params.set('reservation_date', d.reservation_date);
                const timeSlot = (d.start_time && d.end_time) ? (d.start_time + ' - ' + d.end_time) : (d.time_slot || '');
                if (timeSlot) params.set('time_slot', timeSlot);
                if (d.purpose) params.set('purpose', d.purpose);
                if (d.expected_attendees) params.set('expected_attendees', String(d.expected_attendees));
                if (params.toString()) {
                    params.set('open_booking', '1');
                    window.location.href = basePath + '/dashboard/book-facility?' + params.toString();
                }
            }
            setTimeout(function () { input.focus(); }, 150);
        })
        .catch(function (error) {
            console.error('Chatbot API error:', error);
            removeTypingIndicator(typingId);
            let errMsg = 'I apologize, but I\'m having trouble connecting right now. Please try again later.';
            if (String(error && error.message || '').indexOf('non_json_response:401') === 0) {
                errMsg = 'Your session has expired. Please refresh the page and log in again.';
            }
            addMessage(errMsg, 'bot');
        })
        .finally(function () {
            sendBtn.disabled = false;
        });
    });

    const voiceBtn = document.getElementById('aiChatVoiceBtn');
    const voiceStatus = document.getElementById('aiChatVoiceStatus');
    function setVoiceStatus(text, isError) {
        if (!voiceStatus) return;
        if (!text) {
            voiceStatus.hidden = true;
            voiceStatus.textContent = '';
            voiceStatus.classList.remove('is-error');
            return;
        }
        voiceStatus.hidden = false;
        voiceStatus.textContent = text;
        voiceStatus.classList.toggle('is-error', !!isError);
    }
    const SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition;
    const voiceSecure = window.isSecureContext || location.protocol === 'https:' || /^(localhost|127\.0\.0\.1)$/i.test(location.hostname);
    if (voiceBtn && SpeechRecognitionCtor && voiceSecure) {
        const recognition = new SpeechRecognitionCtor();
        recognition.lang = 'en-US';
        recognition.interimResults = false;
        recognition.continuous = false;
        recognition.maxAlternatives = 1;
        let isListening = false;

        voiceBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (isListening) {
                try { recognition.stop(); } catch (err) {}
                return;
            }
            setVoiceStatus('');
            try {
                recognition.start();
            } catch (err) {
                try {
                    recognition.stop();
                    setTimeout(function () {
                        try { recognition.start(); } catch (err2) {
                            setVoiceStatus('Could not start voice input. Try again.', true);
                        }
                    }, 200);
                } catch (err2) {
                    setVoiceStatus('Could not start voice input. Try again.', true);
                }
            }
        });
        recognition.addEventListener('start', function () {
            isListening = true;
            voiceBtn.classList.add('is-listening');
            setVoiceStatus('Listening… speak now');
        });
        recognition.addEventListener('result', function (event) {
            const transcript = (event.results[0] && event.results[0][0] && event.results[0][0].transcript) || '';
            if (transcript.trim()) {
                input.value = (input.value ? input.value + ' ' : '') + transcript.trim();
                input.dispatchEvent(new Event('input'));
                input.focus();
            }
        });
        recognition.addEventListener('end', function () {
            isListening = false;
            voiceBtn.classList.remove('is-listening');
            setVoiceStatus('');
        });
        recognition.addEventListener('error', function (event) {
            isListening = false;
            voiceBtn.classList.remove('is-listening');
            const code = (event && event.error) || '';
            if (code === 'aborted' || code === 'no-speech') {
                setVoiceStatus(code === 'no-speech' ? 'No speech detected. Tap the mic and try again.' : '');
                return;
            }
            if (code === 'not-allowed' || code === 'service-not-allowed') {
                setVoiceStatus('Microphone permission blocked. Allow mic access in your browser.', true);
                return;
            }
            setVoiceStatus('Voice input failed. Try typing instead.', true);
        });
    } else if (voiceBtn) {
        voiceBtn.addEventListener('click', function (e) {
            e.preventDefault();
            setVoiceStatus(voiceSecure
                ? 'Voice input is not supported in this browser. Try Chrome or Edge.'
                : 'Voice input needs HTTPS (or localhost).', true);
        });
    }

    setTimeout(function () { input.focus(); }, 200);
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

