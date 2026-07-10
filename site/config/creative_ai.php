<?php

return [
    'admin_email' => env('ADMIN_EMAIL'),
    'allow_indexing' => filter_var(env('ALLOW_INDEXING', false), FILTER_VALIDATE_BOOL),
    'legacy_path' => env('CREATIVE_AI_LEGACY_PATH', base_path('legacy')),
    'image_variants' => [
        'thumb' => 720,
        'display' => 1600,
    ],
    'ai' => [
        'provider' => env('AI_PROVIDER', 'openai'),
        'prompt_version' => env('AI_PROMPT_VERSION', 'artwork-metadata-v1'),
        'auto_analyze_uploads' => filter_var(env('AI_AUTO_ANALYZE_UPLOADS', false), FILTER_VALIDATE_BOOL),
        'image_max_width' => (int) env('AI_IMAGE_MAX_WIDTH', 768),
        'image_jpeg_quality' => (int) env('AI_IMAGE_JPEG_QUALITY', 72),
    ],
];
