<?php

return [
    // 'none'    — payments off: picking a plan activates it immediately (current behaviour).
    // 'fake'    — local testing: simulates the mobile money PIN prompt, no money moves.
    // 'pesapal' — real collections through NMG's Pesapal account (not built yet).
    'driver' => env('PAYMENTS_DRIVER', 'none'),

    // Lets the 'fake' driver run in production for demos. No money moves, so the site
    // shows a "Demo: no money is charged" notice. Never leave it on after the demo.
    'demo' => (bool) env('PAYMENTS_DEMO', false),

    // A pending payment the user never confirms is marked expired after this long.
    'pending_timeout_seconds' => (int) env('PAYMENTS_PENDING_TIMEOUT', 180),

    // How long a title rental lasts after payment.
    'rental_hours' => (int) env('PAYMENTS_RENTAL_HOURS', 48),

    // Each successful payment buys this many days (mobile money can't auto-debit monthly).
    'period_days' => (int) env('PAYMENTS_PERIOD_DAYS', 30),

    // Where the provider sends card payers back to after its checkout page
    // (the web app's /payment/return screen, which checks the result).
    'return_url' => env('PAYMENTS_RETURN_URL', 'http://localhost:8080/payment/return'),

    'fake' => [
        // Seconds before the simulated "user entered their PIN" confirmation.
        'confirm_after_seconds' => (int) env('PAYMENTS_FAKE_CONFIRM_AFTER', 6),
    ],
];
