<?php
/**
 * Mail configuration (sample values).
 * Replace with real SMTP credentials in production and keep them out of version control.
 */
return [
    'from_email' => (string)(function_exists('env_value') ? env_value('MAIL_FROM_EMAIL', 'no-reply@example.com') : (getenv('MAIL_FROM_EMAIL') ?: 'no-reply@example.com')),
    'from_name'  => (string)(function_exists('env_value') ? env_value('MAIL_FROM_NAME', 'LGU Facilities') : (getenv('MAIL_FROM_NAME') ?: 'LGU Facilities')),
    // SMTP placeholders (if you wire PHPMailer later)
    'smtp_enabled' => strtolower((string)(function_exists('env_value') ? env_value('MAIL_SMTP_ENABLED', 'true') : (getenv('MAIL_SMTP_ENABLED') ?: 'true'))) === 'true',
    'host' => (string)(function_exists('env_value') ? env_value('MAIL_HOST', 'smtp.gmail.com') : (getenv('MAIL_HOST') ?: 'smtp.gmail.com')),
    'port' => (int)(function_exists('env_value') ? env_value('MAIL_PORT', '587') : (getenv('MAIL_PORT') ?: '587')),
    'username' => (string)(function_exists('env_value') ? env_value('MAIL_USERNAME', '') : (getenv('MAIL_USERNAME') ?: '')),
    'password' => (string)(function_exists('env_value') ? env_value('MAIL_PASSWORD', '') : (getenv('MAIL_PASSWORD') ?: '')),
    'encryption' => (string)(function_exists('env_value') ? env_value('MAIL_ENCRYPTION', 'tls') : (getenv('MAIL_ENCRYPTION') ?: 'tls')),
];

