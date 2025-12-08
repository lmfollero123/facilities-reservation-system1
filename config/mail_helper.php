<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send email via SMTP (PHPMailer). Configure credentials in config/mail.php.
 */
function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
{
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

