<?php

return [
    'allow_indexing' => filter_var(env('ALLOW_INDEXING', false), FILTER_VALIDATE_BOOL),
    'image_variants' => [
        'thumb' => 720,
        'display' => 1600,
    ],
    'ai' => [
        'prompt_version' => 'artwork-metadata-v1',
    ],
];
