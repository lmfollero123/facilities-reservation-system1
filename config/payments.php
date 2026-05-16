<?php
return [
    // Toggle entire payments flow for demo or rollout.
    'enabled' => strtolower((string)(function_exists('env_value') ? env_value('PAYMENTS_ENABLED', 'true') : (getenv('PAYMENTS_ENABLED') ?: 'true'))) === 'true',

    // If true, new reservations go through pencil booking first.
    'require_payment_for_reservations' => strtolower((string)(function_exists('env_value') ? env_value('REQUIRE_PAYMENT_FOR_RESERVATIONS', 'true') : (getenv('REQUIRE_PAYMENT_FOR_RESERVATIONS') ?: 'true'))) === 'true',

    // Minutes allowed to complete payment before auto-cancel.
    'payment_window_minutes' => (int)(function_exists('env_value') ? env_value('PAYMENT_WINDOW_MINUTES', '60') : (getenv('PAYMENT_WINDOW_MINUTES') ?: '60')),

    // Currency expected by PayMongo.
    'currency' => (string)(function_exists('env_value') ? env_value('PAYMENT_CURRENCY', 'PHP') : (getenv('PAYMENT_CURRENCY') ?: 'PHP')),

    // PayMongo links endpoint configuration.
    'paymongo' => [
        'secret_key' => (string)(function_exists('env_value') ? env_value('PAYMONGO_SECRET_KEY', '') : (getenv('PAYMONGO_SECRET_KEY') ?: '')),
        'webhook_secret' => (string)(function_exists('env_value') ? env_value('PAYMONGO_WEBHOOK_SECRET', '') : (getenv('PAYMONGO_WEBHOOK_SECRET') ?: '')),
        'links_endpoint' => (string)(function_exists('env_value') ? env_value('PAYMONGO_LINKS_ENDPOINT', 'https://api.paymongo.com/v1/links') : (getenv('PAYMONGO_LINKS_ENDPOINT') ?: 'https://api.paymongo.com/v1/links')),
        'description_prefix' => (string)(function_exists('env_value') ? env_value('PAYMONGO_DESCRIPTION_PREFIX', 'LGU Culiat Reservation') : (getenv('PAYMONGO_DESCRIPTION_PREFIX') ?: 'LGU Culiat Reservation')),
    ],
];
