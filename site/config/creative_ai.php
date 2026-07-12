<?php

return [
    'allow_indexing' => filter_var(env('ALLOW_INDEXING', false), FILTER_VALIDATE_BOOL),
    'uploads' => [
        'max_track_size_kb' => 102_400,
        'track_mime_types' => [
            'audio/mpeg',
            'audio/mp3',
            'audio/x-mp3',
            'audio/wav',
            'audio/x-wav',
            'audio/wave',
            'audio/vnd.wave',
            'audio/ogg',
            'application/ogg',
            'audio/mp4',
            'audio/x-m4a',
            'audio/m4a',
            'audio/aac',
            'audio/flac',
            'audio/x-flac',
        ],
    ],
    'image_variants' => [
        'thumb' => 720,
        'display' => 1600,
        'max_source_pixels' => 20_000_000,
    ],
    'ai' => [
        'prompt_version' => 'artwork-metadata-v1',
    ],
];
