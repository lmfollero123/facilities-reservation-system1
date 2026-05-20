<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Simple outbound mail guard to reduce abuse if SMTP is compromised.
 * Limits can be tuned via .env:
 * - MAIL_RATE_LIMIT_GLOBAL_MAX (default 80)
 * - MAIL_RATE_LIMIT_GLOBAL_WINDOW (default 600 seconds)
 * - MAIL_RATE_LIMIT_PER_RECIPIENT_MAX (default 5)
 * - MAIL_RATE_LIMIT_PER_RECIPIENT_WINDOW (default 600 seconds)
 * - MAIL_RATE_LIMIT_PER_IP_MAX (default 20)
 * - MAIL_RATE_LIMIT_PER_IP_WINDOW (default 600 seconds)
 */
function frs_mail_send_allowed(string $toEmail): bool
{
    if (!function_exists('checkRateLimit')) {
        return true;
    }

    $recipient = strtolower(trim($toEmail));
    if ($recipient === '') {
        return false;
    }

    $globalMax = (int)(function_exists('env_value') ? env_value('MAIL_RATE_LIMIT_GLOBAL_MAX', '80') : '80');
    $globalWindow = (int)(function_exists('env_value') ? env_value('MAIL_RATE_LIMIT_GLOBAL_WINDOW', '600') : '600');
    if (!checkRateLimit('mail_global', 'all', max(1, $globalMax), max(60, $globalWindow))) {
        error_log('Mail guard: global send rate limit reached.');
        return false;
    }

    $recipientMax = (int)(function_exists('env_value') ? env_value('MAIL_RATE_LIMIT_PER_RECIPIENT_MAX', '5') : '5');
    $recipientWindow = (int)(function_exists('env_value') ? env_value('MAIL_RATE_LIMIT_PER_RECIPIENT_WINDOW', '600') : '600');
    if (!checkRateLimit('mail_recipient', $recipient, max(1, $recipientMax), max(60, $recipientWindow))) {
        error_log('Mail guard: recipient rate limit reached for ' . $recipient);
        return false;
    }

    if (function_exists('getClientIP')) {
        $ip = trim((string)getClientIP());
        if ($ip !== '') {
            $ipMax = (int)(function_exists('env_value') ? env_value('MAIL_RATE_LIMIT_PER_IP_MAX', '20') : '20');
            $ipWindow = (int)(function_exists('env_value') ? env_value('MAIL_RATE_LIMIT_PER_IP_WINDOW', '600') : '600');
            if (!checkRateLimit('mail_ip', $ip, max(1, $ipMax), max(60, $ipWindow))) {
                error_log('Mail guard: IP rate limit reached for ' . $ip);
                return false;
            }
        }
    }

    return true;
}

/**
 * Send email via SMTP (PHPMailer). Configure credentials in config/mail.php.
 */
/**
 * Send a plain-text email (used for carrier email-to-SMS gateways).
 */
function sendPlainTextEmail(string $toEmail, string $subject, string $textBody): bool
{
    if (!frs_mail_send_allowed($toEmail)) {
        return false;
    }

    $config = require __DIR__ . '/mail.php';
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'] ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'] ?? '';
        $mail->Password   = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $config['port'] ?? 587;

        $mail->setFrom($config['from_email'] ?? 'no-reply@example.com', $config['from_name'] ?? 'LGU Facilities');
        $mail->addAddress($toEmail);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $textBody;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail error (plain): ' . $mail->ErrorInfo);
        return false;
    }
}

function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
{
    if (!frs_mail_send_allowed($toEmail)) {
        return false;
    }

    $config = require __DIR__ . '/mail.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'] ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'] ?? '';
        $mail->Password   = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $config['port'] ?? 587;

        $mail->setFrom($config['from_email'] ?? 'no-reply@example.com', $config['from_name'] ?? 'LGU Facilities');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}

