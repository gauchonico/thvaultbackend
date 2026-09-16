<?php
// ═══════════════════════════════════════════════════════════════════
// FILE 1: config/cors.php
// Replace the existing file with this content
// ═══════════════════════════════════════════════════════════════════
 
return [
    'paths'                    => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods'          => ['*'],
    'allowed_origins'          => [
        'http://localhost:5173',       // Vite dev server
        'http://localhost:8080', //react front end
        'http://localhost:8081', //react front end (alt port)
        'http://localhost:4173',       // Vite preview
        'https://yourapp.lovable.app', // Replace with your Lovable URL
        'https://app.yourdomain.com',  // Replace with your production frontend
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => [],
    'max_age'                  => 0,
    'supports_credentials'     => true,
];