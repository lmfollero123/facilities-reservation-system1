<?php
/**
 * Gemini-generated copy for CIMM maintenance public announcements.
 */
declare(strict_types=1);

require_once __DIR__ . '/gemini_chatbot.php';
require_once __DIR__ . '/public_facility_announcements.php';

/**
 * @param array<string, mixed> $context
 * @return array{title: string, message: string}|null
 */
function geminiGenerateMaintenanceAnnouncementText(array $context): ?array
{
    if (!frs_gemini_api_configured()) {
        return null;
    }

    $facilityName = trim((string)($context['facility_name'] ?? 'Facility'));
    $location = trim((string)($context['location'] ?? ''));
    $maintenanceType = trim((string)($context['maintenance_type'] ?? 'Maintenance'));
    $startLabel = trim((string)($context['start_label'] ?? ''));
    $endLabel = trim((string)($context['end_label'] ?? ''));
    $duration = trim((string)($context['duration'] ?? ''));
    $description = trim((string)($context['description'] ?? ''));
    $statusLabel = trim((string)($context['status_label'] ?? 'Scheduled'));

    $facts = <<<FACTS
Facility: {$facilityName}
Location: {$location}
Maintenance type: {$maintenanceType}
Status: {$statusLabel}
Start date: {$startLabel}
End date: {$endLabel}
Estimated duration: {$duration}
Additional details: {$description}
FACTS;

    $systemPrompt = <<<'PROMPT'
You write official public announcements for Barangay Culiat, Quezon City (LGU Facilities Reservation System).

Write ONE announcement for a scheduled facility maintenance notice.

Rules:
- Professional, clear, resident-friendly English (light Taglish only if natural).
- Title: max 120 characters, specific (include facility name and maintenance).
- Message: 2–4 sentences covering what, when, where, and what residents should do (e.g. book other dates/facilities).
- Do NOT invent dates, times, or facility names — use only the facts provided.
- Do NOT mention internal systems (CIMM, CPRF, API, Gemini).
- Return ONLY valid JSON with keys "title" and "message" (no markdown).
PROMPT;

    return frs_gemini_json_announcement_request($systemPrompt, $facts);
}

/**
 * @param array<string, mixed> $context
 * @return array{title: string, message: string}
 */
function frs_fallback_maintenance_announcement_text(array $context): array
{
    $facilityName = trim((string)($context['facility_name'] ?? 'Facility'));
    $startLabel = trim((string)($context['start_label'] ?? ''));
    $endLabel = trim((string)($context['end_label'] ?? ''));
    $maintenanceType = trim((string)($context['maintenance_type'] ?? 'maintenance'));
    $location = trim((string)($context['location'] ?? ''));

    $when = $startLabel;
    if ($endLabel !== '' && $endLabel !== $startLabel) {
        $when = $startLabel . ' to ' . $endLabel;
    }

    $where = $location !== '' ? " at {$location}" : '';

    return [
        'title' => "{$facilityName} Scheduled for {$maintenanceType}",
        'message' => "Please be advised that {$facilityName}{$where} will undergo {$maintenanceType} from {$when}. "
            . 'The facility cannot be reserved during this period. '
            . 'We encourage residents to plan ahead and choose alternative dates or facilities for their activities.',
    ];
}

/**
 * @param array<string, mixed> $context
 * @return array{title: string, message: string}|null
 */
function geminiGenerateBlackoutAnnouncementText(array $context): ?array
{
    if (!frs_gemini_api_configured()) {
        return null;
    }

    $facilityName = trim((string)($context['facility_name'] ?? 'Facility'));
    $location = trim((string)($context['location'] ?? ''));
    $reason = trim((string)($context['reason'] ?? 'Facility unavailable'));
    $startLabel = trim((string)($context['start_label'] ?? ''));
    $endLabel = trim((string)($context['end_label'] ?? ''));
    $windowLabel = trim((string)($context['window_label'] ?? ''));
    $dayCount = (int)($context['day_count'] ?? 1);

    $facts = <<<FACTS
Facility: {$facilityName}
Location: {$location}
Reason / activity: {$reason}
Blocked dates: {$windowLabel}
Start date: {$startLabel}
End date: {$endLabel}
Number of days blocked: {$dayCount}
FACTS;

    $systemPrompt = <<<'PROMPT'
You write official public announcements for Barangay Culiat, Quezon City (LGU Facilities Reservation System).

Write ONE announcement informing residents that a facility cannot be booked on specific dates due to a CPRF staff blackout (event, maintenance, LGU activity, etc.).

Rules:
- Professional, clear, resident-friendly English (light Taglish only if natural).
- Title: max 120 characters, specific (include facility name and that bookings are blocked).
- Message: 2–4 sentences covering what, when, why (use the reason provided), and what residents should do.
- Do NOT invent dates, times, or facility names — use only the facts provided.
- Do NOT mention internal systems (CPRF, API, Gemini, database).
- Return ONLY valid JSON with keys "title" and "message" (no markdown).
PROMPT;

    return frs_gemini_json_announcement_request($systemPrompt, $facts);
}

/**
 * @param array<string, mixed> $context
 * @return array{title: string, message: string}
 */
function frs_fallback_blackout_announcement_text(array $context): array
{
    $facilityName = trim((string)($context['facility_name'] ?? 'Facility'));
    $reason = trim((string)($context['reason'] ?? 'an LGU activity'));
    $windowLabel = trim((string)($context['window_label'] ?? ''));
    $location = trim((string)($context['location'] ?? ''));
    $where = $location !== '' ? " ({$location})" : '';

    return [
        'title' => "{$facilityName} Unavailable for Booking",
        'message' => "Please be advised that {$facilityName}{$where} will not accept reservations on {$windowLabel} due to {$reason}. "
            . 'Residents with planned activities are encouraged to choose alternative dates or other barangay facilities. '
            . 'Thank you for your understanding.',
    ];
}

/**
 * @return array{title: string, message: string}|null
 */
function frs_gemini_json_announcement_request(string $systemPrompt, string $facts): ?array
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        return null;
    }

    $models = ['gemini-flash-latest', 'gemini-3.5-flash', 'gemini-3-flash-preview', 'gemini-2.0-flash'];
    $apiKey = GEMINI_API_KEY;
    $payloadBase = [
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => "Create the announcement using these facts:\n\n" . $facts]]],
        ],
        'generationConfig' => [
            'temperature' => 0.45,
            'maxOutputTokens' => 600,
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['title', 'message'],
            ],
        ],
    ];

    $raw = false;
    $httpCode = 0;
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payloadBase),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw !== false && $httpCode === 200) {
            break;
        }
        error_log("Gemini announcement model {$model} failed: HTTP {$httpCode}");
    }

    if ($raw === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode((string)$raw, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        return null;
    }

    $parsed = json_decode(trim($text), true);
    if (!is_array($parsed) && preg_match('/\{[\s\S]*"title"[\s\S]*"message"[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed)) {
        return null;
    }

    $title = trim((string)($parsed['title'] ?? ''));
    $message = trim((string)($parsed['message'] ?? ''));
    if ($title === '' || $message === '') {
        return null;
    }
    if (mb_strlen($title) > 150) {
        $title = mb_substr($title, 0, 147) . '…';
    }

    return ['title' => $title, 'message' => $message];
}
