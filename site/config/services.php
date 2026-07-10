<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
        'timeout' => (int) env('AI_REQUEST_TIMEOUT', 90),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'timeout' => (int) env('ANTHROPIC_REQUEST_TIMEOUT', 120),
    ],

    'zai' => [
        'api_key' => env('ZAI_API_KEY'),
        'base_url' => env('ZAI_BASE_URL', 'https://api.z.ai/api/paas/v4'),
        'model' => env('ZAI_MODEL', 'glm-4.6v-flash'),
        'timeout' => (int) env('ZAI_REQUEST_TIMEOUT', 120),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3.5:latest'),
        'timeout' => (int) env('OLLAMA_REQUEST_TIMEOUT', 150),
        'context_length' => (int) env('OLLAMA_CONTEXT_LENGTH', 4096),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '5m'),
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

];
