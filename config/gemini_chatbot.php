<?php
/**
 * Gemini-powered chatbot helper.
 * Handles AI responses and booking prefill extraction for the LGU Facilities Reservation system.
 */

// Prefer .env / server env (e.g. ~/private/cprf.env) over local gemini_config.php
if (!defined('GEMINI_API_KEY')) {
    $envGeminiKey = function_exists('env_value') ? env_value('GEMINI_API_KEY', '') : (getenv('GEMINI_API_KEY') ?: '');
    if (is_string($envGeminiKey) && trim($envGeminiKey) !== '') {
        define('GEMINI_API_KEY', trim($envGeminiKey));
    }
}
if (!defined('GEMINI_API_KEY') && file_exists(__DIR__ . '/gemini_config.php')) {
    require_once __DIR__ . '/gemini_config.php';
}

/**
 * Resolve Gemini API key at call time.
 * Prefer ~/private/cprf.env / .env over a stale config/gemini_config.php constant.
 */
function frs_gemini_api_key(): string
{
    $fromEnv = function_exists('env_value')
        ? trim((string) env_value('GEMINI_API_KEY', ''))
        : trim((string) (getenv('GEMINI_API_KEY') ?: ''));
    if ($fromEnv !== '' && $fromEnv !== 'YOUR_GEMINI_API_KEY_HERE') {
        return $fromEnv;
    }
    if (defined('GEMINI_API_KEY')) {
        $k = trim((string) GEMINI_API_KEY);
        if ($k !== '' && $k !== 'YOUR_GEMINI_API_KEY_HERE') {
            return $k;
        }
    }
    return '';
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
    $apiKey = frs_gemini_api_key();
    if ($apiKey === '') {
        return null;
    }

    // Prefer models available to new AI Studio / auth (AQ.) keys.
    // gemini-2.5-* returns 404 for new projects; gemini-2.0-* often has free-tier limit 0.
    $models = ['gemini-flash-latest', 'gemini-3.5-flash', 'gemini-3-flash-preview', 'gemini-2.0-flash'];
    $raw = false;
    $httpCode = 0;
    $perRequestTimeout = 20;
    $connectTimeout = 5;
    $maxModelAttempts = 3;
    $attemptedModels = 0;

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
        if ($attemptedModels >= $maxModelAttempts) {
            break;
        }
        $attemptedModels++;
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
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $perRequestTimeout
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
    // Newer models may return multiple parts (text + metadata); join text parts.
    $text = null;
    $parts = $data['candidates'][0]['content']['parts'] ?? null;
    if (is_array($parts)) {
        $chunks = [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                $chunks[] = $part['text'];
            }
        }
        if ($chunks !== []) {
            $text = implode('', $chunks);
        }
    }
    if (!$text || !is_string($text)) {
        error_log('Gemini API 200 but no text in candidates: ' . substr((string) $raw, 0, 300));
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

    $reply = frs_gemini_strip_markdown((string) $reply);

    return [
        'success' => true,
        'reply' => $reply,
        'booking' => $booking
    ];
}

/**
 * Strip Markdown emphasis so chat UIs that show plain text don't display raw * / **.
 */
function frs_gemini_strip_markdown(string $text): string
{
    // Fenced code blocks → inner text only
    $text = preg_replace('/```[\w]*\s*([\s\S]*?)```/', '$1', $text) ?? $text;
    // Bold / italic (order: ** then *)
    $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text) ?? $text;
    $text = preg_replace('/__(.+?)__/s', '$1', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '$1', $text) ?? $text;
    $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '$1', $text) ?? $text;
    // Markdown bullets at line start
    $text = preg_replace('/^(\s*)[\*\-]\s+/m', '$1• ', $text) ?? $text;
    // Headers
    $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
    // Any leftover emphasis markers that confuse residents
    $text = str_replace(['**', '__'], '', $text);
    $text = preg_replace('/(?<![A-Za-z0-9])\*(?![A-Za-z0-9])/', '', $text) ?? $text;

    return trim(preg_replace("/[ \t]+\n/", "\n", $text) ?? $text);
}

/**
 * Format the Live Occupancy snapshot into plain text for the Gemini system prompt.
 *
 * @param array<string, mixed>|null $liveOccupancy from frs_build_operational_occupancy_snapshot()
 */
function formatGeminiLiveOccupancyContext(?array $liveOccupancy): string {
    if (empty($liveOccupancy) || empty($liveOccupancy['facilities']) || !is_array($liveOccupancy['facilities'])) {
        return "Live occupancy data is not available right now. If asked about open/occupied facilities \"now\", say you cannot confirm live status and suggest Live Facility Status / Occupancy on the dashboard.";
    }

    $asOf = (string)($liveOccupancy['as_of'] ?? date('Y-m-d H:i:s'));
    $date = (string)($liveOccupancy['date'] ?? date('Y-m-d'));
    $summary = is_array($liveOccupancy['summary'] ?? null) ? $liveOccupancy['summary'] : [];
    $available = (int)($summary['available'] ?? 0);
    $occupied = (int)($summary['occupied'] ?? 0);
    $unavailable = (int)($summary['unavailable'] ?? 0);
    $total = (int)($summary['total_facilities'] ?? count($liveOccupancy['facilities']));
    $disclaimer = (string)($liveOccupancy['disclaimer'] ?? 'Estimated operational status from bookings, check-in/out, and staff updates — not live headcount.');

    $groups = [
        'available_now' => [],
        'occupied' => [],
        'closed_or_unavailable' => [],
    ];
    $occupiedKeys = ['staff_in_use', 'checked_in', 'no_show_risk', 'booked', 'staff_event_ending'];
    $availableKeys = ['available', 'staff_vacant'];

    foreach ($liveOccupancy['facilities'] as $fac) {
        if (!is_array($fac)) {
            continue;
        }
        $name = (string)($fac['facility_name'] ?? 'Facility');
        $state = (string)($fac['aggregate_state'] ?? 'available');
        $label = (string)($fac['aggregate_display']['label'] ?? $state);
        $hoursRaw = trim((string)($fac['operating_hours'] ?? ''));
        if ($hoursRaw === '' && function_exists('frs_default_operating_hours_bounds')) {
            $bounds = frs_default_operating_hours_bounds();
            $hoursRaw = $bounds['start'] . '-' . $bounds['end'] . ' (default)';
        } elseif ($hoursRaw === '') {
            $hoursRaw = '08:00-21:00 (default)';
        }
        $within = !empty($fac['is_within_operating_hours']) ? 'yes' : 'no';
        $master = (string)($fac['facility_status'] ?? 'available');

        $slotBits = [];
        if (!empty($fac['reservations_today']) && is_array($fac['reservations_today'])) {
            foreach ($fac['reservations_today'] as $res) {
                if (!is_array($res)) {
                    continue;
                }
                $slot = (string)($res['time_slot'] ?? '');
                $resLabel = (string)($res['state_label'] ?? $res['operational_state'] ?? '');
                if ($slot !== '') {
                    $slotBits[] = $slot . ($resLabel !== '' ? " [{$resLabel}]" : '');
                }
            }
        }
        $slotsText = $slotBits !== [] ? implode('; ', $slotBits) : 'none';

        $line = sprintf(
            '- %s | Live status: %s | Operating hours: %s | Within hours now: %s | Catalog status: %s | Today\'s approved slots: %s',
            $name,
            $label,
            $hoursRaw,
            $within,
            $master,
            $slotsText
        );

        if (in_array($state, $availableKeys, true)) {
            $groups['available_now'][] = $line;
        } elseif (in_array($state, $occupiedKeys, true)) {
            $groups['occupied'][] = $line;
        } else {
            $groups['closed_or_unavailable'][] = $line;
        }
    }

    $section = static function (string $title, array $lines): string {
        if ($lines === []) {
            return "### {$title}\n(none)\n";
        }
        return "### {$title}\n" . implode("\n", $lines) . "\n";
    };

    $availableSection = $section('AVAILABLE / OPEN RIGHT NOW', $groups['available_now']);
    $occupiedSection = $section('OCCUPIED / IN USE RIGHT NOW', $groups['occupied']);
    $closedSection = $section('CLOSED OR UNAVAILABLE RIGHT NOW', $groups['closed_or_unavailable']);

    return trim(<<<TXT
Snapshot as of {$asOf} (date {$date}, Asia/Manila).
Summary: {$total} facilities — {$available} available/open now, {$occupied} occupied/in use, {$unavailable} closed/unavailable (maintenance, offline, outside hours, or staff-closed).
Note: {$disclaimer}

{$availableSection}
{$occupiedSection}
{$closedSection}
TXT);
}

/**
 * Build system prompt for the chatbot with facilities, rules, live occupancy, and user context.
 *
 * @param array<int, array<string, mixed>> $facilities
 * @param array<int, array<string, mixed>> $userBookings
 * @param array<string, mixed>|null $liveOccupancy optional snapshot from frs_build_operational_occupancy_snapshot()
 */
function buildGeminiChatbotPrompt(array $facilities, array $userBookings, string $userName, ?int $userId, ?array $liveOccupancy = null): string {
    $facList = [];
    foreach ($facilities as $f) {
        $hours = trim((string)($f['operating_hours'] ?? ''));
        if ($hours === '') {
            $hours = '08:00-21:00 (default)';
        }
        $extra = [];
        if (!empty($f['capacity'])) {
            $extra[] = 'capacity: ' . $f['capacity'];
        }
        if (!empty($f['location'])) {
            $extra[] = 'location: ' . $f['location'];
        }
        $extra[] = 'hours: ' . $hours;
        $facList[] = sprintf(
            '- ID %d (internal only): %s (catalog status: %s, %s)',
            (int)$f['id'],
            $f['name'],
            $f['status'] ?? 'available',
            implode(', ', $extra)
        );
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

    $nowLabel = date('Y-m-d H:i');
    $liveOccupancyText = formatGeminiLiveOccupancyContext($liveOccupancy);

    if (!function_exists('frs_resident_booking_limits_policy_bullets')) {
        require_once __DIR__ . '/reservation_helpers.php';
    }
    $bookingLimitsBullets = frs_resident_booking_limits_policy_bullets();

    return <<<PROMPT
You are a helpful AI assistant for an LGU (Local Government Unit) Facilities Reservation System in Barangay Culiat.

LANGUAGE: Always respond in Tagalog (Filipino). You may mix in English words when natural (Taglish), as commonly used in the Philippines. Be warm and helpful (magalang at matulungin).

FORMAT: Write plain text only. Do NOT use Markdown (no **bold**, no *italics*, no # headers, no ``` code fences). Use numbered lists (1. 2. 3.) or the • character for bullets.

Current local time: {$nowLabel} (Asia/Manila).

## FACILITIES (IDs are for internal prefill_booking JSON only; do NOT show or mention facility IDs to the user)
{$facilitiesText}

When listing or describing facilities in your reply, use ONLY facility names and details (e.g. capacity, status, hours). Never write "(id=N)" or "id=N" or similar in the message. Example: say "Bernardo Court - capacity 200" not "Bernardo Court (id=4)".

## LIVE FACILITY STATUS / OCCUPANCY (authoritative for "open / closed / occupied NOW")
Use this section whenever the user asks what is open now, available right now, occupied, closed tonight, operating hours right now, live status, or similar.
- "Available / open right now" means the facility is within its operating hours AND not occupied/maintenance/offline/staff-closed.
- "Closed" here usually means outside that facility's operating hours (or staff marked closed) — even if catalog status is still "available".
- Catalog status "maintenance" / "offline" always means unavailable for use.
- Do NOT say a facility is open just because it is bookable in the catalog. Prefer LIVE STATUS over catalog status for "right now" questions.
- If ALL facilities are closed/unavailable now, say so clearly and briefly explain (e.g. after operating hours). You may mention when typical hours resume tomorrow.

{$liveOccupancyText}

## USER'S RECENT BOOKINGS
{$bookingsText}

## BOOKING RULES (strict - always enforce)
1. Default operating hours when a facility has no custom hours: 8:00 AM - 9:00 PM (08:00 - 21:00). Many facilities have custom hours — use each facility's hours from the lists above.
2. Minimum duration: 30 minutes. Maximum: 12 hours
3. Only future dates allowed. Today or later.
4. Resident booking limits (Staff/Admin exempt):
{$bookingLimitsBullets}
5. Booking for a future slot is different from live occupancy "open now". A facility can be closed tonight but still bookable for tomorrow.

## MEMORY
Remember the full conversation. If the user mentioned a facility in an earlier message and now adds date/time/purpose, combine all details. Build on previous context.

## YOUR TASKS

### General questions
Answer in Tagalog about:
- Live occupancy / which facilities are open, occupied, closed, or under maintenance RIGHT NOW (use LIVE FACILITY STATUS)
- Available facilities, amenities, capacity, location, operating hours
- Schedules and availability for specific dates
- Booking policies and rules
- User's reservations
- Upcoming events (you don't have a full events feed; say they can check the calendar / Live Facility Status)

### Booking setup (IMPORTANT)
When the user wants to BOOK or RESERVE, output the prefill_booking JSON. Include ONLY the fields the user explicitly provided in this message or earlier in the conversation. Use null for any field they never mentioned—do NOT guess or infer.

Format - include this JSON block in your reply:
\`\`\`json
{"action":"prefill_booking","data":{"facility_id":N or null,"reservation_date":"YYYY-MM-DD" or null,"start_time":"HH:MM" or null,"end_time":"HH:MM" or null,"purpose":"..." or null,"expected_attendees":N or null}}
\`\`\`

Rules for the JSON (STRICT - no guessing):
- facility_id: Integer from facilities list ONLY if user explicitly named a facility. Otherwise null.
- reservation_date: YYYY-MM-DD ONLY if user explicitly gave a date (e.g. "Jan 25", "tomorrow", "Saturday"). Otherwise null.
- start_time, end_time: 24-hour HH:MM ONLY if user explicitly gave times. Otherwise null. Prefer times within that facility's operating hours; default window 08:00-21:00, 30-min increments.
- purpose: Short description ONLY if user explicitly stated the event purpose. Otherwise null.
- expected_attendees: number ONLY if user explicitly said a number. Otherwise null.

CRITICAL: Do NOT infer, assume, or guess any value. If the user only provided a date, put ONLY reservation_date in the JSON and null for everything else. If they only said a facility name, put ONLY facility_id and null for the rest. Never fill in fields the user did not mention.

User name: {$userName}
PROMPT;
}
