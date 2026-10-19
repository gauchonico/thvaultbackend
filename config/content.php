<?php

return [
    // Freemium users can watch this much Subscribers content per period, in total.
    'preview_seconds' => (int) env('PREVIEW_SECONDS', 600),

    // When the allowance refills: 'month', 'day' or 'never'.
    'preview_reset' => env('PREVIEW_RESET', 'month'),
];
