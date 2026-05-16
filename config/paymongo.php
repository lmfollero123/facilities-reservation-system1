<?php
return [
    // Toggle payment feature without touching code.
    'enabled' => true,

    // PayMongo Secret Key (starts with sk_...)
    'secret_key' => 'YOUR_PAYMONGO_SECRET_KEY',

    // Optional webhook signature secret from PayMongo dashboard.
    'webhook_secret' => 'YOUR_PAYMONGO_WEBHOOK_SECRET',

    // Use this in checkout display and audit text.
    'currency' => 'PHP',

    // Reservation hold time while waiting for payment.
    'pencil_hold_minutes' => 30,
];
