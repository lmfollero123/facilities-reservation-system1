<?php

require_once __DIR__ . '/app.php';

/** @var string|null */
$GLOBALS['frs_sms_last_error'] = null;
/** @var array<string, mixed>|null */
$GLOBALS['frs_sms_last_debug'] = null;

/**
 * @return array<string, mixed>
 */
function frs_sms_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/sms.php';
    $config = file_exists($path) ? (require $path) : [];
    if (!is_array($config)) {
        $config = [];
    }
    return $config;
}

function frs_sms_set_last_error(?string $message): void
{
    $GLOBALS['frs_sms_last_error'] = $message;
}

function frs_sms_last_error(): ?string
{
    $err = $GLOBALS['frs_sms_last_error'] ?? null;
    return is_string($err) && $err !== '' ? $err : null;
}

/**
 * @return array<string, mixed>|null
 */
function frs_sms_last_debug(): ?array
{
    $debug = $GLOBALS['frs_sms_last_debug'] ?? null;
    return is_array($debug) ? $debug : null;
}

/**
 * Normalize Philippine mobile numbers to PhilSMS format (639XXXXXXXXX).
 */
function normalizePhilippineMobileNumber(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === null || $digits === '') {
        return null;
    }

    if (str_starts_with($digits, '63') && strlen($digits) === 12) {
        return $digits;
    }
    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        return '63' . substr($digits, 1);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        return '63' . $digits;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '9')) {
        return '63' . $digits;
    }

    return null;
}

/**
 * @return array{ready: bool, enabled: bool, token_set: bool, endpoint_set: bool, sender_id: string, default_recipient: string, issues: string[]}
 */
function frs_sms_log_file_path(): string
{
    $config = frs_sms_config();
    $log = is_array($config['log'] ?? null) ? $config['log'] : [];
    $custom = trim((string)($log['path'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    return dirname(__DIR__) . '/storage/logs/sms.log';
}

function getSmsConfigurationStatus(): array
{
    $config = frs_sms_config();
    $enabled = !empty($config['enabled']);
    $driver = strtolower(trim((string)($config['driver'] ?? 'philsms')));
    $philsms = is_array($config['philsms'] ?? null) ? $config['philsms'] : [];
    $tokenSet = trim((string)($philsms['api_token'] ?? '')) !== '';
    $endpointSet = trim((string)($philsms['endpoint'] ?? '')) !== '';
    $senderId = trim((string)($philsms['sender_id'] ?? ''));
    $defaultRecipient = trim((string)($config['default_recipient'] ?? ''));

    $issues = [];
    if (!$enabled) {
        $issues[] = 'SMS is disabled (set SMS_ENABLED=true in .env).';
    }

    if ($driver === 'log') {
        return [
            'ready' => $enabled,
            'enabled' => $enabled,
            'driver' => 'log',
            'token_set' => false,
            'endpoint_set' => false,
            'sender_id' => $senderId,
            'default_recipient' => $defaultRecipient,
            'log_path' => frs_sms_log_file_path(),
            'issues' => $issues,
        ];
    }

    if ($driver === 'iprogsms') {
        $iprog = is_array($config['iprogsms'] ?? null) ? $config['iprogsms'] : [];
        $iprogToken = trim((string)($iprog['api_token'] ?? ''));
        if ($iprogToken === '') {
            $issues[] = 'IPROG_API_TOKEN is missing (sign up at iprogsms.com — free credits after KYC).';
        }
        return [
            'ready' => $enabled && $iprogToken !== '',
            'enabled' => $enabled,
            'driver' => 'iprogsms',
            'token_set' => $iprogToken !== '',
            'endpoint_set' => true,
            'sender_id' => 'iprogSMS (shared)',
            'default_recipient' => $defaultRecipient,
            'log_path' => '',
            'issues' => $issues,
        ];
    }

    if ($driver === 'email_gateway') {
        $emailGw = is_array($config['email_gateway'] ?? null) ? $config['email_gateway'] : [];
        $globeDomain = trim((string)($emailGw['globe_domain'] ?? ''));
        $smartDomain = trim((string)($emailGw['smart_domain'] ?? ''));
        if ($globeDomain === '') {
            $issues[] = 'SMS_EMAIL_GLOBE_DOMAIN is empty.';
        }
        $mailCfg = file_exists(__DIR__ . '/mail.php') ? (require __DIR__ . '/mail.php') : [];
        if (trim((string)($mailCfg['username'] ?? '')) === '') {
            $issues[] = 'MAIL_USERNAME is not set (needed to send via SMTP).';
        }
        return [
            'ready' => $enabled && $globeDomain !== '' && trim((string)($mailCfg['username'] ?? '')) !== '',
            'enabled' => $enabled,
            'driver' => 'email_gateway',
            'token_set' => false,
            'endpoint_set' => $smartDomain !== '',
            'sender_id' => 'Globe: ' . $globeDomain,
            'default_recipient' => $defaultRecipient,
            'log_path' => '',
            'issues' => $issues,
        ];
    }

    if (!$tokenSet) {
        $issues[] = 'PHILSMS_API_TOKEN is missing in .env.';
    }
    if (!$endpointSet) {
        $issues[] = 'PHILSMS_ENDPOINT is missing in .env.';
    }
    if ($senderId === '') {
        $issues[] = 'PHILSMS_SENDER_ID is empty.';
    }

    return [
        'ready' => $enabled && $tokenSet && $endpointSet && $senderId !== '',
        'enabled' => $enabled,
        'driver' => $driver !== '' ? $driver : 'philsms',
        'token_set' => $tokenSet,
        'endpoint_set' => $endpointSet,
        'sender_id' => $senderId,
        'default_recipient' => $defaultRecipient,
        'log_path' => '',
        'issues' => $issues,
    ];
}

/**
 * Demo/capstone driver: log SMS to file instead of sending (free, no API).
 */
function sendSmsViaLog(string $normalizedRecipient, string $message): bool
{
    $logPath = frs_sms_log_file_path();
    $dir = dirname($logPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        frs_sms_set_last_error('Cannot create SMS log directory: ' . $dir);
        return false;
    }

    $line = sprintf(
        "[%s] TO=+%s | %s\n",
        date('Y-m-d H:i:s'),
        $normalizedRecipient,
        str_replace(["\r", "\n"], ' ', $message)
    );

    if (@file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) === false) {
        frs_sms_set_last_error('Failed to write SMS log: ' . $logPath);
        return false;
    }

    $GLOBALS['frs_sms_last_debug'] = [
        'http_code' => 0,
        'recipient' => $normalizedRecipient,
        'sender_id' => '(log driver)',
        'response' => 'Logged to ' . $logPath . ' (no real SMS sent).',
    ];

    return true;
}

/**
 * 639XXXXXXXXX → 09XXXXXXXXX for carrier email gateways.
 */
function frs_ph_mobile_to_local(string $normalized639): string
{
    if (str_starts_with($normalized639, '63') && strlen($normalized639) === 12) {
        return '0' . substr($normalized639, 2);
    }
    return $normalized639;
}

/**
 * Guess Globe vs Smart from mobile prefix (best-effort; override with SMS_EMAIL_DEFAULT_NETWORK).
 */
function frs_detect_ph_email_network(string $normalized639, string $defaultNetwork = 'globe'): string
{
    if (!str_starts_with($normalized639, '63') || strlen($normalized639) !== 12) {
        return $defaultNetwork;
    }
    $prefix = substr($normalized639, 3, 3);

    $smart = ['813', '907', '908', '909', '910', '911', '912', '913', '914', '918', '919', '920', '921', '928', '929', '930', '938', '939', '940', '946', '947', '948', '949', '950', '951', '960', '961', '970', '981', '989', '998', '999'];
    $globe = ['817', '905', '906', '915', '916', '917', '925', '926', '927', '935', '936', '937', '945', '953', '954', '955', '956', '965', '966', '967', '975', '976', '977', '995', '996', '997'];

    if (in_array($prefix, $smart, true)) {
        return 'smart';
    }
    if (in_array($prefix, $globe, true)) {
        return 'globe';
    }

    return $defaultNetwork;
}

/**
 * Build carrier email address for email-to-SMS (e.g. 09171234567@messaging.globe.com.ph).
 */
function frs_ph_email_gateway_address(string $normalized639, array $emailGwConfig): ?string
{
    $local = frs_ph_mobile_to_local($normalized639);
    if (!preg_match('/^09\d{9}$/', $local)) {
        return null;
    }

    $defaultNetwork = strtolower(trim((string)($emailGwConfig['default_network'] ?? 'globe')));
    $network = frs_detect_ph_email_network($normalized639, $defaultNetwork !== '' ? $defaultNetwork : 'globe');

    $globeDomain = trim((string)($emailGwConfig['globe_domain'] ?? 'messaging.globe.com.ph'));
    $smartDomain = trim((string)($emailGwConfig['smart_domain'] ?? 'messaging.smart.com.ph'));

    $domain = $network === 'smart' ? $smartDomain : $globeDomain;
    if ($domain === '') {
        return null;
    }

    return $local . '@' . $domain;
}

function sendSmsViaEmailGateway(string $normalizedRecipient, string $message): bool
{
    require_once __DIR__ . '/mail_helper.php';

    $config = frs_sms_config();
    $emailGw = is_array($config['email_gateway'] ?? null) ? $config['email_gateway'] : [];
    $gatewayEmail = frs_ph_email_gateway_address($normalizedRecipient, $emailGw);

    if ($gatewayEmail === null) {
        frs_sms_set_last_error('Could not build carrier email address for this number.');
        return false;
    }

    if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
    }

    $subject = trim((string)(function_exists('env_value') ? env_value('SMS_EMAIL_SUBJECT', 'SMS') : 'SMS'));
    if ($subject === '') {
        $subject = 'SMS';
    }

    $sent = sendPlainTextEmail($gatewayEmail, $subject, $message);
    if (!$sent) {
        frs_sms_set_last_error('SMTP failed sending to carrier gateway ' . $gatewayEmail . '. Check MAIL_* in .env.');
        return false;
    }

    $GLOBALS['frs_sms_last_debug'] = [
        'http_code' => 0,
        'recipient' => $normalizedRecipient,
        'sender_id' => $gatewayEmail,
        'response' => 'Sent plain email to carrier gateway. Delivery depends on Globe/Smart accepting email-to-SMS (often Globe only).',
    ];

    return true;
}

function sendSmsViaIprog(string $normalizedRecipient, string $message): bool
{
    $config = frs_sms_config();
    $iprog = is_array($config['iprogsms'] ?? null) ? $config['iprogsms'] : [];
    $apiToken = trim((string)($iprog['api_token'] ?? ''));
    $endpoint = trim((string)($iprog['endpoint'] ?? 'https://www.iprogsms.com/api/v1/sms_messages'));

    if ($apiToken === '') {
        frs_sms_set_last_error('IPROG_API_TOKEN is missing. Register at https://www.iprogsms.com/register');
        return false;
    }

    if (strlen($message) > 480) {
        $message = substr($message, 0, 477) . '...';
    }

    $payload = http_build_query([
        'api_token' => $apiToken,
        'phone_number' => $normalizedRecipient,
        'message' => $message,
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $GLOBALS['frs_sms_last_debug'] = [
        'http_code' => $httpCode,
        'recipient' => $normalizedRecipient,
        'sender_id' => 'iprogSMS',
        'response' => is_string($responseBody) ? $responseBody : '',
    ];

    if ($curlErr) {
        frs_sms_set_last_error('Network error: ' . $curlErr);
        return false;
    }

    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
    $status = is_array($decoded) ? ($decoded['status'] ?? null) : null;
    if ($status === 200 || $status === 'success' || (is_numeric($status) && (int)$status >= 200 && (int)$status < 300)) {
        return true;
    }

    $apiMessage = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
    frs_sms_set_last_error($apiMessage !== '' ? $apiMessage : 'IPROG SMS request failed (HTTP ' . $httpCode . ').');
    return false;
}

function sendSmsViaPhilsms(string $normalizedRecipient, string $message): bool
{
    $config = frs_sms_config();
    $philsms = is_array($config['philsms'] ?? null) ? $config['philsms'] : [];
    $apiToken = trim((string)($philsms['api_token'] ?? ''));
    $endpoint = trim((string)($philsms['endpoint'] ?? ''));
    $senderId = trim((string)($philsms['sender_id'] ?? 'LGUCuliat'));
    $type = trim((string)($philsms['type'] ?? 'plain'));

    if ($apiToken === '' || $endpoint === '') {
        frs_sms_set_last_error('PhilSMS API token or endpoint is not configured.');
        return false;
    }

    if (strlen($message) > 480) {
        $message = substr($message, 0, 477) . '...';
    }

    $payload = [
        'recipient' => '+' . $normalizedRecipient,
        'sender_id' => $senderId,
        'type' => $type,
        'message' => $message,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $GLOBALS['frs_sms_last_debug'] = [
        'http_code' => $httpCode,
        'recipient' => $normalizedRecipient,
        'sender_id' => $senderId,
        'response' => is_string($responseBody) ? $responseBody : '',
    ];

    if ($curlErr) {
        frs_sms_set_last_error('Network error: ' . $curlErr);
        error_log('SMS error (cURL): ' . $curlErr);
        return false;
    }

    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
    $apiStatus = is_array($decoded) ? strtolower((string)($decoded['status'] ?? '')) : '';

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiMessage = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
        frs_sms_set_last_error('HTTP ' . $httpCode . ($apiMessage !== '' ? ': ' . $apiMessage : ''));
        return false;
    }

    if ($apiStatus === 'success') {
        return true;
    }

    if ($apiStatus === 'error') {
        $apiMessage = is_array($decoded) ? (string)($decoded['message'] ?? 'Unknown API error') : 'Unknown API error';
        frs_sms_set_last_error($apiMessage);
        return false;
    }

    $fallbackMsg = is_string($responseBody) && $responseBody !== ''
        ? 'Unexpected API response: ' . substr($responseBody, 0, 200)
        : 'Unexpected API response (empty body).';
    frs_sms_set_last_error($fallbackMsg);
    return false;
}

/**
 * Send an SMS (driver: philsms | iprogsms | email_gateway | log).
 *
 * @param string|null $recipient E.164-style PH mobile (639XXXXXXXXX) or local formats; uses default_recipient when empty.
 */
function sendSmsNotification(?string $recipient, string $message): bool
{
    frs_sms_set_last_error(null);
    $GLOBALS['frs_sms_last_debug'] = null;

    $config = frs_sms_config();
    if (empty($config['enabled'])) {
        frs_sms_set_last_error('SMS is disabled.');
        return false;
    }

    $message = trim($message);
    if ($message === '') {
        frs_sms_set_last_error('Message is empty.');
        return false;
    }

    $normalized = normalizePhilippineMobileNumber($recipient);
    if ($normalized === null && !empty($config['default_recipient'])) {
        $normalized = normalizePhilippineMobileNumber($config['default_recipient']);
    }
    if ($normalized === null) {
        frs_sms_set_last_error('No valid Philippine mobile number (use 09XX or 639XX format).');
        return false;
    }

    $driver = strtolower(trim((string)($config['driver'] ?? 'philsms')));

    if ($driver === 'log') {
        if (strlen($message) > 480) {
            $message = substr($message, 0, 477) . '...';
        }
        return sendSmsViaLog($normalized, $message);
    }

    if ($driver === 'email_gateway') {
        return sendSmsViaEmailGateway($normalized, $message);
    }

    if ($driver === 'iprogsms') {
        return sendSmsViaIprog($normalized, $message);
    }

    if ($driver === 'philsms') {
        return sendSmsViaPhilsms($normalized, $message);
    }

    frs_sms_set_last_error('Unsupported SMS driver: ' . $driver . ' (philsms, iprogsms, email_gateway, log).');
    return false;
}

/**
 * Build and send reservation status SMS.
 *
 * @param array<string, mixed> $reservation Must include facility_name, reservation_date, time_slot; optional requester_mobile/mobile.
 */
function sendReservationStatusSms(array $reservation, string $status): bool
{
    $userId = (int)($reservation['user_id'] ?? $reservation['requester_id'] ?? 0);
    $statusKeyEarly = strtolower(trim($status));
    if ($userId > 0) {
        require_once __DIR__ . '/notification_preferences.php';
        frs_ensure_notification_preferences_schema();
        $smsCategory = ($statusKeyEarly === 'reminder') ? 'reminder' : 'booking';
        if (!frs_user_wants_notification($userId, $smsCategory, 'sms')) {
            return false;
        }
    }

    $facility = (string)($reservation['facility_name'] ?? 'facility');
    $date = !empty($reservation['reservation_date'])
        ? date('M j, Y', strtotime((string)$reservation['reservation_date']))
        : '';
    $slot = (string)($reservation['time_slot'] ?? '');

    $templates = [
        'approved' => 'LGU Culiat: Your reservation for %s on %s (%s) has been APPROVED.',
        'pending_payment' => 'LGU Culiat: Reservation approved for %s. Complete payment now to finalize your slot.',
        'denied' => 'LGU Culiat: Your reservation for %s on %s (%s) was DENIED.',
        'cancelled' => 'LGU Culiat: Your reservation for %s on %s (%s) has been CANCELLED.',
        'pending' => 'LGU Culiat: We received your reservation for %s on %s (%s). Status: PENDING review.',
        'submitted' => 'LGU Culiat: We received your reservation for %s on %s (%s). Status: PENDING review.',
        'postponed' => 'LGU Culiat: Your reservation for %s has been POSTPONED. Check the app for the new schedule.',
        'modified' => 'LGU Culiat: Your reservation for %s on %s (%s) was UPDATED. Please review in the app.',
        'reminder' => 'LGU Culiat: Reminder — %s tomorrow %s (%s). See My Reservations in the app.',
    ];

    $statusKey = strtolower(trim($status));
    if (!isset($templates[$statusKey])) {
        return false;
    }

    if (in_array($statusKey, ['pending_payment'], true)) {
        $message = sprintf($templates[$statusKey], $facility);
    } elseif (in_array($statusKey, ['postponed'], true)) {
        $message = sprintf($templates[$statusKey], $facility);
    } else {
        $message = sprintf($templates[$statusKey], $facility, $date, $slot);
    }

    $mobile = $reservation['requester_mobile'] ?? $reservation['mobile'] ?? null;
    return sendSmsNotification(is_string($mobile) ? $mobile : null, $message);
}

/**
 * Verify IPROG API token and return SMS credit balance.
 *
 * @return array{ok: bool, http_code: int, message: string, body: string, credits: float|null}
 */
function verifyIprogSmsToken(?string $apiToken = null): array
{
    $config = frs_sms_config();
    $iprog = is_array($config['iprogsms'] ?? null) ? $config['iprogsms'] : [];
    $token = trim($apiToken ?? (string)($iprog['api_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'http_code' => 0, 'message' => 'No IPROG API token configured.', 'body' => '', 'credits' => null];
    }

    $url = 'https://www.iprogsms.com/api/v1/account/sms_credits?api_token=' . rawurlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    $status = is_array($decoded) ? strtolower((string)($decoded['status'] ?? '')) : '';
    $message = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
    $credits = null;
    if (is_array($decoded['data'] ?? null)) {
        $credits = isset($decoded['data']['load_balance']) ? (float)$decoded['data']['load_balance'] : null;
    }

    if ($status === 'success') {
        $creditMsg = $credits !== null ? sprintf(' Token valid. Balance: %s credit(s).', rtrim(rtrim(number_format($credits, 2, '.', ''), '0'), '.')) : ' Token valid.';
        return ['ok' => true, 'http_code' => $httpCode, 'message' => trim($message . $creditMsg), 'body' => $body, 'credits' => $credits];
    }

    return [
        'ok' => false,
        'http_code' => $httpCode,
        'message' => $message !== '' ? $message : 'Invalid or expired IPROG API token.',
        'body' => $body,
        'credits' => null,
    ];
}

/**
 * Verify PhilSMS API token (lightweight GET /balance check).
 *
 * @return array{ok: bool, http_code: int, message: string, body: string}
 */
function verifyPhilSmsToken(?string $apiToken = null): array
{
    $config = frs_sms_config();
    $philsms = is_array($config['philsms'] ?? null) ? $config['philsms'] : [];
    $token = trim($apiToken ?? (string)($philsms['api_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'http_code' => 0, 'message' => 'No API token configured.', 'body' => ''];
    }

    $ch = curl_init('https://app.philsms.com/api/v3/balance');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    $status = is_array($decoded) ? strtolower((string)($decoded['status'] ?? '')) : '';
    $message = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';

    if ($status === 'success') {
        return ['ok' => true, 'http_code' => $httpCode, 'message' => 'Token is valid.', 'body' => $body];
    }

    return [
        'ok' => false,
        'http_code' => $httpCode,
        'message' => $message !== '' ? $message : 'Token verification failed.',
        'body' => $body,
    ];
}

/**
 * Send login OTP via SMS when user has a mobile number on file.
 */
function sendLoginOtpSms(?string $mobile, string $otp, int $validMinutes = 1): bool
{
    $message = 'LGU Culiat login code: ' . $otp . '. Valid for ' . max(1, $validMinutes) . ' min. Do not share this code.';
    return sendSmsNotification($mobile, $message);
}
