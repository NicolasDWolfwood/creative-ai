<?php

namespace App\Data;

use App\Enums\PostMediaType;

final readonly class JournalSourceImage
{
    public function __construct(
        public string $sourcePath,
        public string $thumbnailUrl,
        public string $altText,
        public PostMediaType $sourceType,
        public int $sourceId,
    ) {}
}
