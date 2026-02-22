<?php
/**
 * Gemini-powered chatbot helper.
 * Handles AI responses and booking prefill extraction for the LGU Facilities Reservation system.
 */

if (!defined('GEMINI_API_KEY') && file_exists(__DIR__ . '/gemini_config.php')) {
    require_once __DIR__ . '/gemini_config.php';
}

/**
 * Call Gemini API and return text response.
 *
 * @param string $systemPrompt System/context prompt
 * @param string $userMessage User message
 * @param array $conversationHistory Previous messages [['role'=>'user'|'model','parts'=>[['text'=>'...']]], ...]
 * @return array{success: bool, reply: string, booking?: array}|null
 */
function geminiChatbotResponse(string $systemPrompt, string $userMessage, array $conversationHistory = []): ?array {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        return null;
    }

    $models = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-flash-latest', 'gemini-flash-lite-latest', 'gemini-2.0-flash'];
    $raw = false;
    $httpCode = 0;
    $apiKey = GEMINI_API_KEY;

    $contents = [];
    foreach ($conversationHistory as $msg) {
        $role = ($msg['role'] ?? '') === 'model' ? 'model' : 'user';
        $text = $msg['parts'][0]['text'] ?? '';
        if ($text !== '') {
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 4096,
                'topP' => 0.95
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw !== false && $httpCode === 200) {
            break;
        }
        $lastError = $curlErr ?: substr(is_string($raw) ? $raw : json_encode($raw), 0, 300);
        error_log("Gemini API model {$model} failed: HTTP {$httpCode}, {$lastError}");
    }

    if ($raw === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($raw, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text || !is_string($text)) {
        return null;
    }

    $text = trim($text);

    // Check for booking prefill JSON block (Gemini may wrap in ```json ... ```)
    $booking = null;
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?"action"\s*:\s*"prefill_booking"[\s\S]*?\})\s*```/', $text, $m)) {
        $json = json_decode(trim($m[1]), true);
        if (is_array($json) && isset($json['action']) && $json['action'] === 'prefill_booking' && isset($json['data'])) {
            $booking = $json['data'];
        }
    }
    if (!$booking && preg_match('/\{\s*"action"\s*:\s*"prefill_booking"[\s\S]*\}/', $text, $m)) {
        $json = json_decode($m[0], true);
        if (is_array($json) && isset($json['action']) && $json['action'] === 'prefill_booking' && isset($json['data'])) {
            $booking = $json['data'];
        }
    }

    $reply = $text;
    if ($booking) {
        // Remove the JSON block from the displayed reply; use human-readable part if present
        $reply = preg_replace('/```(?:json)?\s*\{[\s\S]*?"action"\s*:\s*"prefill_booking"[\s\S]*?\}\s*```/', '', $reply);
        $reply = preg_replace('/\{\s*"action"\s*:\s*"prefill_booking"[\s\S]*\}/', '', $reply);
        $reply = trim($reply);
        if ($reply === '') {
            $reply = "Isesetup ko na ang booking form para sa iyo. Paki-review at i-submit kapag ready ka na.";
        }
    }

    return [
        'success' => true,
        'reply' => $reply,
        'booking' => $booking
    ];
}

/**
 * Build system prompt for the chatbot with facilities, rules, and user context.
 */
function buildGeminiChatbotPrompt(array $facilities, array $userBookings, string $userName, ?int $userId): string {
    $facList = [];
    foreach ($facilities as $f) {
        $facList[] = sprintf('- ID %d (internal only): %s (status: %s, capacity: %s)', (int)$f['id'], $f['name'], $f['status'] ?? 'available', $f['capacity'] ?? 'N/A');
    }
    $facilitiesText = implode("\n", $facList);

    $bookingsText = 'None';
    if (!empty($userBookings)) {
        $lines = [];
        foreach ($userBookings as $b) {
            $lines[] = sprintf('- %s on %s (%s)', $b['facility_name'] ?? $b['facility'], $b['reservation_date'], $b['time_slot'] ?? '');
        }
        $bookingsText = implode("\n", $lines);
    }

    $today = date('Y-m-d');

    return <<<PROMPT
You are a helpful AI assistant for an LGU (Local Government Unit) Facilities Reservation System in Barangay Culiat.

LANGUAGE: Always respond in Tagalog (Filipino). You may mix in English words when natural (Taglish), as commonly used in the Philippines. Be warm and helpful (magalang at matulungin).

## FACILITIES (IDs are for internal prefill_booking JSON only; do NOT show or mention facility IDs to the user)
{$facilitiesText}

When listing or describing facilities in your reply, use ONLY facility names and details (e.g. capacity, status). Never write "(id=N)" or "id=N" or similar in the message. Example: say "Bernardo Court - capacity 200" not "Bernardo Court (id=4)".

## USER'S RECENT BOOKINGS
{$bookingsText}

## BOOKING RULES (strict - always enforce)
1. Operating hours: 8:00 AM - 9:00 PM (08:00 - 21:00)
2. Minimum duration: 30 minutes. Maximum: 12 hours
3. Only future dates allowed. Today or later.
4. Maximum 1 booking per user per day
5. Maximum 3 active (pending + approved) reservations per user within 30 days
6. Bookings allowed up to 60 days in advance

## MEMORY
Remember the full conversation. If the user mentioned a facility in an earlier message and now adds date/time/purpose, combine all details. Build on previous context.

## YOUR TASKS

### General questions
Answer in Tagalog about:
- Available facilities, amenities, capacity, location
- Schedules and availability for specific dates
- Booking policies and rules
- User's reservations
- Upcoming events (you don't have real-time events; say they can check the calendar)

### Booking setup (IMPORTANT)
When the user wants to BOOK or RESERVE, output the prefill_booking JSON. Include ONLY the fields the user explicitly provided in this message or earlier in the conversation. Use null for any field they never mentioned—do NOT guess or infer.

Format - include this JSON block in your reply:
\`\`\`json
{"action":"prefill_booking","data":{"facility_id":N or null,"reservation_date":"YYYY-MM-DD" or null,"start_time":"HH:MM" or null,"end_time":"HH:MM" or null,"purpose":"..." or null,"expected_attendees":N or null}}
\`\`\`

Rules for the JSON (STRICT - no guessing):
- facility_id: Integer from facilities list ONLY if user explicitly named a facility. Otherwise null.
- reservation_date: YYYY-MM-DD ONLY if user explicitly gave a date (e.g. "Jan 25", "tomorrow", "Saturday"). Otherwise null.
- start_time, end_time: 24-hour HH:MM ONLY if user explicitly gave times. Otherwise null. Within 08:00-21:00, 30-min increments.
- purpose: Short description ONLY if user explicitly stated the event purpose. Otherwise null.
- expected_attendees: number ONLY if user explicitly said a number. Otherwise null.

CRITICAL: Do NOT infer, assume, or guess any value. If the user only provided a date, put ONLY reservation_date in the JSON and null for everything else. If they only said a facility name, put ONLY facility_id and null for the rest. Never fill in fields the user did not mention.

User name: {$userName}
PROMPT;
}
