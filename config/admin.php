<?php

// Admin account created by `php artisan db:seed`. Set both in the server's
// environment; nothing is created on a deployed server when they're missing.
return [
    'email'    => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),
];
