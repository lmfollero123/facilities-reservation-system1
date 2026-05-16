<?php

/**
 * SMS configuration (IPROG SMS default; other drivers in .env).
 *
 * IMPORTANT:
 * - Keep your real API token OUT of git.
 * - For defense/demo, set these values in your local copy only.
 */

return [
    // Global on/off switch so you can disable SMS without touching code
    'enabled' => strtolower((string)(function_exists('env_value') ? env_value('SMS_ENABLED', 'true') : (getenv('SMS_ENABLED') ?: 'true'))) === 'true',

    // Default recipient for demo (e.g. your own phone during panel defense).
    // Format: 639XXXXXXXXX
    'default_recipient' => (string)(function_exists('env_value') ? env_value('SMS_DEFAULT_RECIPIENT', '') : (getenv('SMS_DEFAULT_RECIPIENT') ?: '')),

    // philsms | iprogsms | email_gateway | log (see .env.example)
    'driver' => (string)(function_exists('env_value') ? env_value('SMS_DRIVER', 'iprogsms') : (getenv('SMS_DRIVER') ?: 'iprogsms')),

    'log' => [
        'path' => (string)(function_exists('env_value') ? env_value('SMS_LOG_PATH', '') : (getenv('SMS_LOG_PATH') ?: '')),
    ],

    'iprogsms' => [
        'api_token' => (string)(function_exists('env_value') ? env_value('IPROG_API_TOKEN', '') : (getenv('IPROG_API_TOKEN') ?: '')),
        'endpoint'  => (string)(function_exists('env_value') ? env_value('IPROG_ENDPOINT', 'https://www.iprogsms.com/api/v1/sms_messages') : (getenv('IPROG_ENDPOINT') ?: 'https://www.iprogsms.com/api/v1/sms_messages')),
    ],

    'email_gateway' => [
        // Carrier email-to-SMS (free via your SMTP; Globe often works, Smart may not)
        'globe_domain'  => (string)(function_exists('env_value') ? env_value('SMS_EMAIL_GLOBE_DOMAIN', 'messaging.globe.com.ph') : (getenv('SMS_EMAIL_GLOBE_DOMAIN') ?: 'messaging.globe.com.ph')),
        'smart_domain'  => (string)(function_exists('env_value') ? env_value('SMS_EMAIL_SMART_DOMAIN', 'messaging.smart.com.ph') : (getenv('SMS_EMAIL_SMART_DOMAIN') ?: 'messaging.smart.com.ph')),
        'default_network' => strtolower((string)(function_exists('env_value') ? env_value('SMS_EMAIL_DEFAULT_NETWORK', 'globe') : (getenv('SMS_EMAIL_DEFAULT_NETWORK') ?: 'globe'))),
    ],

    'philsms' => [
        // API base endpoint for sending SMS
        'endpoint'  => (string)(function_exists('env_value') ? env_value('PHILSMS_ENDPOINT', 'https://app.philsms.com/api/v3/sms/send') : (getenv('PHILSMS_ENDPOINT') ?: 'https://app.philsms.com/api/v3/sms/send')),
        // Your PhilSMS API token (Authorization: Bearer ...)
        'api_token' => (string)(function_exists('env_value') ? env_value('PHILSMS_API_TOKEN', '') : (getenv('PHILSMS_API_TOKEN') ?: '')),
        // Registered sender ID or numeric sender (max 11 chars for alphanumeric)
        'sender_id' => (string)(function_exists('env_value') ? env_value('PHILSMS_SENDER_ID', 'PhilSMS') : (getenv('PHILSMS_SENDER_ID') ?: 'PhilSMS')),
        // Message type: plain | unicode
        'type'      => (string)(function_exists('env_value') ? env_value('PHILSMS_TYPE', 'plain') : (getenv('PHILSMS_TYPE') ?: 'plain')),
    ],
];

