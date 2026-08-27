<?php
/**
 * Gemini-generated plain-English explanations of a facility's maintenance
 * pressure score (Maintenance Insights tab). On-demand, per-facility, not
 * eagerly generated for every row on page load - see
 * maintenance-insight-explain-api.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/gemini_chatbot.php';
require_once __DIR__ . '/public_facility_announcements.php';

/**
 * @param array<string, mixed> $context
 */
function geminiExplainMaintenancePressure(array $context): ?string
{
    if (!frs_gemini_api_configured()) {
        return null;
    }

    $facts = frs_maintenance_pressure_facts($context);

    $systemPrompt = <<<'PROMPT'
You help barangay facility staff (non-technical) understand a facility maintenance pressure score.

Rules:
- Plain English, 1-3 short sentences, no jargon.
- Explain WHY the score is what it is, using only the numbers given (usage pressure, growth pressure, status pressure, booking counts).
- If risk is Medium or High, end with a brief practical suggestion (e.g. what to inspect, or that a request should be sent).
- If risk is Low, reassure briefly - no fabricated urgency.
- Do NOT invent facts not given. Do NOT mention "CIMM", "CPRF", "Gemini", or internal system names.
- Return ONLY valid JSON with a single key "explanation" (no markdown).
PROMPT;

    return frs_gemini_text_maintenance_explanation_request($systemPrompt, $facts);
}

/**
 * Same explanation, backed by Groq's free tier instead - used as a fallback
 * when Gemini is unavailable (quota/rate limit/network). Mirrors the
 * Gemini -> Groq -> null pattern already used by frs_check_purpose_gate().
 *
 * @param array<string, mixed> $context
 */
function groqExplainMaintenancePressure(array $context): ?string
{
    if (frs_groq_api_key() === '') {
        return null;
    }

    $facts = frs_maintenance_pressure_facts($context);

    $systemPrompt = <<<'PROMPT'
You help barangay facility staff (non-technical) understand a facility maintenance pressure score.
Respond with ONLY raw JSON, no markdown fences, no extra commentary: {"explanation": "..."}

Rules:
- Plain English, 1-3 short sentences, no jargon.
- Explain WHY the score is what it is, using only the numbers given (usage pressure, growth pressure, status pressure, booking counts).
- If risk is Medium or High, end with a brief practical suggestion (e.g. what to inspect, or that a request should be sent).
- If risk is Low, reassure briefly - no fabricated urgency.
- Do NOT invent facts not given. Do NOT mention "CIMM", "CPRF", "Groq", or internal system names.
PROMPT;

    $json = frs_groq_chat_json([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Explain this facility's maintenance pressure using these facts:\n\n" . $facts],
    ], 250);

    $explanation = trim((string)($json['explanation'] ?? ''));
    return $explanation !== '' ? $explanation : null;
}

/**
 * Orchestrates the full explanation fallback chain: Gemini -> Groq -> null
 * (caller falls back further to frs_fallback_maintenance_pressure_explanation()).
 * Each stage only runs if the previous one was unavailable (returned null).
 *
 * @param array<string, mixed> $context
 * @return array{explanation: string, source: string}|null
 */
function frs_ai_explain_maintenance_pressure(array $context): ?array
{
    $explanation = geminiExplainMaintenancePressure($context);
    if ($explanation !== null) {
        return ['explanation' => $explanation, 'source' => 'gemini'];
    }

    $explanation = groqExplainMaintenancePressure($context);
    if ($explanation !== null) {
        return ['explanation' => $explanation, 'source' => 'groq'];
    }

    return null;
}

/**
 * @param array<string, mixed> $context
 */
function frs_maintenance_pressure_facts(array $context): string
{
    $facilityName = trim((string)($context['facility_name'] ?? 'Facility'));
    $riskScore = (int)($context['risk_score'] ?? 0);
    $riskBand = trim((string)($context['risk_band'] ?? 'Low'));
    $usage90 = (int)($context['usage_90d'] ?? 0);
    $usage30 = (int)($context['usage_30d'] ?? 0);
    $usagePressure = (int)($context['usage_pressure'] ?? 0);
    $growthPressure = (int)($context['growth_pressure'] ?? 0);
    $statusPressure = (int)($context['status_pressure'] ?? 0);
    $seasonalPressure = (int)($context['seasonal_pressure'] ?? 0);
    $seasonalIndex = (float)($context['seasonal_index'] ?? 1.0);
    $currentMonthName = trim((string)($context['current_month_name'] ?? ''));
    $status = trim((string)($context['status'] ?? 'Available'));

    $seasonalLine = '';
    if ($currentMonthName !== '' && abs($seasonalPressure) >= 1) {
        $trend = $seasonalPressure > 0 ? 'busier' : 'quieter';
        $seasonalLine = "Seasonal trend component: {$seasonalPressure} points ({$currentMonthName} is historically {$trend} than an average month system-wide, index {$seasonalIndex})\n";
    }

    $facts = <<<FACTS
Facility: {$facilityName}
Current status: {$status}
Overall pressure score: {$riskScore}/100 ({$riskBand} risk)
Bookings in the last 90 days: {$usage90}
Bookings in the last 30 days: {$usage30}
Usage pressure component: {$usagePressure}/60 (based on 90-day booking volume)
Growth pressure component: {$growthPressure}/25 (based on whether the last 30 days are busier than the 90-day average pace)
Status pressure component: {$statusPressure}/15 (added only if the facility is currently under maintenance)
FACTS;

    return $seasonalLine !== '' ? $facts . "\n" . $seasonalLine : $facts;
}

/**
 * @return string|null trimmed explanation text, or null if Gemini is unreachable/misconfigured
 */
function frs_gemini_text_maintenance_explanation_request(string $systemPrompt, string $facts): ?string
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        return null;
    }

    // Two candidates, not four: this is a synchronous, user-facing button click
    // (unlike the announcement generator, which runs in a background cron sync)
    // - if the network path to Gemini is down, every model fails the same way,
    // so more candidates just means a longer wait before the fallback kicks in.
    $models = ['gemini-flash-latest', 'gemini-2.0-flash'];
    $apiKey = GEMINI_API_KEY;
    $payloadBase = [
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => "Explain this facility's maintenance pressure using these facts:\n\n" . $facts]]],
        ],
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => 300,
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'object',
                'properties' => [
                    'explanation' => ['type' => 'string'],
                ],
                'required' => ['explanation'],
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
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw !== false && $httpCode === 200) {
            break;
        }
        error_log("Gemini maintenance-explanation model {$model} failed: HTTP {$httpCode}");
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
    if (!is_array($parsed) && preg_match('/\{[\s\S]*"explanation"[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed)) {
        return null;
    }

    $explanation = trim((string)($parsed['explanation'] ?? ''));
    if ($explanation === '') {
        return null;
    }
    if (mb_strlen($explanation) > 500) {
        $explanation = mb_substr($explanation, 0, 497) . '…';
    }

    return $explanation;
}

/**
 * Rule-based fallback when Gemini is unavailable/unconfigured - same facts,
 * plainly narrated instead of AI-generated.
 *
 * @param array<string, mixed> $context
 */
function frs_fallback_maintenance_pressure_explanation(array $context): string
{
    $facilityName = trim((string)($context['facility_name'] ?? 'This facility'));
    $riskBand = trim((string)($context['risk_band'] ?? 'Low'));
    $usage90 = (int)($context['usage_90d'] ?? 0);
    $usage30 = (int)($context['usage_30d'] ?? 0);
    $usagePressure = (int)($context['usage_pressure'] ?? 0);
    $growthPressure = (int)($context['growth_pressure'] ?? 0);
    $statusPressure = (int)($context['status_pressure'] ?? 0);
    $seasonalPressure = (int)($context['seasonal_pressure'] ?? 0);
    $currentMonthName = trim((string)($context['current_month_name'] ?? ''));

    $parts = [];
    $parts[] = "{$facilityName} had {$usage90} booking(s) in the last 90 days, contributing {$usagePressure} of 60 possible usage-pressure points.";
    if ($growthPressure > 0) {
        $parts[] = "The last 30 days ({$usage30} bookings) are busier than usual, adding {$growthPressure} of 25 possible growth-pressure points.";
    } else {
        $parts[] = "Recent 30-day activity ({$usage30} bookings) is not outpacing the usual rate, so no growth pressure was added.";
    }
    if ($statusPressure > 0) {
        $parts[] = "It is currently flagged under maintenance, adding {$statusPressure} points.";
    }
    if ($seasonalPressure > 0 && $currentMonthName !== '') {
        $parts[] = "{$currentMonthName} is historically a busier month for bookings system-wide, adding {$seasonalPressure} seasonal points.";
    } elseif ($seasonalPressure < 0 && $currentMonthName !== '') {
        $parts[] = "{$currentMonthName} is historically a quieter month system-wide, so {$seasonalPressure} points were subtracted.";
    }

    if ($riskBand === 'High') {
        $parts[] = 'Recommend sending a maintenance request soon.';
    } elseif ($riskBand === 'Medium') {
        $parts[] = 'Worth a routine check before the next busy stretch.';
    } else {
        $parts[] = 'No action needed right now.';
    }

    return implode(' ', $parts);
}
