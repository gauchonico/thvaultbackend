<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    ],

    // Admin episode auto-fill (upload date + duration). Accepts the env name either way.
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY', env('YOUTUB_API_KEY')),
    ],

    // DRM-protected playback. The secret only ever stays on the server.
    'vdocipher' => [
        'secret' => env('VDOCIPHER_API_SECRET'),
    ],

    'gemini' => [
        'key' => env('GOOGLE_API_KEY'),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-flash-lite-latest'),
        'dimensions' => env('GEMINI_EMBEDDING_DIMENSIONS', 768),
    ],

    // Researcher Portal embeddings/chat provider: 'openai', 'gemini' or 'ollama'.
    'embeddings' => [
        'provider' => env('EMBEDDINGS_PROVIDER', 'openai'),
    ],

    'researcher' => [
        // AI Executive Synthesis is off by default — local CPU inference (Ollama) takes
        // 20s+ for it, which risks proxy timeouts and makes the demo feel broken. Search
        // itself (embedding + cosine ranking) stays fast either way. Flip this on once
        // either OpenAI has credits or you're fine with the local-model wait.
        'synthesis_enabled' => env('RESEARCHER_SYNTHESIS_ENABLED', false),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        'chat_model' => env('OLLAMA_CHAT_MODEL', 'llama3.2:3b'),
    ],

];
