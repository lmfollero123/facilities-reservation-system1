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
            $facStmt = $pdo->query("SELECT id, name, status, capacity, amenities, location FROM facilities ORDER BY name LIMIT 50");
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
            $prompt = buildGeminiChatbotPrompt($facilities, $userBookings, $userName, $userId);

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
            $reply = "Booking Policies:\n\n" .
                    "â€¢ All facilities are FREE for residents\n" .
                    "â€¢ Maximum of 3 active reservations per user\n" .
                    "â€¢ Bookings require administrator approval\n" .
                    "â€¢ Only one booking per day is allowed\n" .
                    "â€¢ Rescheduling is allowed up to 3 days before the event";
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
| CHATBOT UI (GET REQUEST) â€” page removed; widget handles chat in-dashboard
|--------------------------------------------------------------------------
*/
if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

header('Location: ' . base_path() . '/dashboard');
exit;
