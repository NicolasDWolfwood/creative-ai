<?php

namespace App\Services;

use App\Enums\PostAiOperation;

final readonly class JournalAiRunPreview
{
    /**
     * @param  array<string, mixed>  $contextManifest
     */
    public function __construct(
        public PostAiOperation $operation,
        public array $contextManifest,
        public string $sourceHash,
        public string $contextHash,
        public string $providerProfileHash,
        public string $requestHash,
        public string $provider,
        public string $model,
        public string $endpoint,
        public bool $externalProcessing,
    ) {}
}
