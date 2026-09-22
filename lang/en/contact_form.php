<?php
/**
 * Contact form responses (resources/views/pages/public/contact_handler.php).
 * These are returned as JSON and rendered into the form's feedback area.
 */
declare(strict_types=1);

return [
    'contactform.method_not_allowed' => 'Method not allowed',
    'contactform.invalid_token' => 'Invalid security token. Please refresh the page.',
    'contactform.rate_limited_ip' => 'Too many inquiries from your network. Please try again in a few minutes.',
    'contactform.rate_limited_email' => 'Too many inquiries for this email. Please try again later.',
    'contactform.err_name' => 'Please enter your full name.',
    'contactform.err_email' => 'Please enter a valid email address.',
    'contactform.err_message' => 'Please enter a message (at least 10 characters).',
    'contactform.ack' => 'Thank you for your inquiry!',
    'contactform.success' => 'Thank you for your inquiry! We will get back to you soon.',
    'contactform.error_generic' => 'An error occurred. Please try again later.',
    'contactform.js_sent' => 'Thank you! Your inquiry has been sent.',
    'contactform.js_failed' => 'Unable to send your message.',
    'contactform.js_retry' => 'Unable to send your message. Please try again.',
];
