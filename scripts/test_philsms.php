<?php
/**
 * CLI test for PhilSMS configuration.
 *
 * Usage:
 *   php scripts/test_philsms.php
 *   php scripts/test_philsms.php 09171234567 "Hello from FRS"
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/sms_helper.php';

$recipient = $argv[1] ?? null;
$message = $argv[2] ?? 'LGU Culiat: PhilSMS test from Facilities Reservation System.';

$status = getSmsConfigurationStatus();

echo "SMS configuration\n";
echo "  Enabled: " . ($status['enabled'] ? 'yes' : 'no') . "\n";
echo "  Token set: " . ($status['token_set'] ? 'yes' : 'no') . "\n";
echo "  Endpoint set: " . ($status['endpoint_set'] ? 'yes' : 'no') . "\n";
echo "  Sender ID: " . ($status['sender_id'] !== '' ? $status['sender_id'] : '(empty)') . "\n";
echo "  Default recipient: " . ($status['default_recipient'] !== '' ? $status['default_recipient'] : '(none)') . "\n";

if (!empty($status['issues'])) {
    echo "\nIssues:\n";
    foreach ($status['issues'] as $issue) {
        echo "  - {$issue}\n";
    }
}

if (!$status['ready']) {
    echo "\nSMS is not ready. Fix .env and try again.\n";
    exit(1);
}

$normalized = normalizePhilippineMobileNumber($recipient);
if ($recipient !== null) {
    echo "\nRecipient input: {$recipient}\n";
    echo "Normalized: " . ($normalized ?? '(invalid)') . "\n";
}

echo "\nSending test message...\n";
$ok = sendSmsNotification($recipient, $message);

if ($ok) {
    echo "SUCCESS: SMS accepted by PhilSMS.\n";
    exit(0);
}

echo "FAILED: " . (frs_sms_last_error() ?? 'Unknown error') . "\n";
exit(1);
