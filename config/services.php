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

    // Researcher Portal embeddings/chat provider: 'openai' or 'ollama'.
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
