<?php

namespace App\Services;

use App\Enums\PostAiOperation;
use App\Support\CanonicalJson;

final readonly class JournalAiContract
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public PostAiOperation $operation,
        public string $prompt,
        public string $promptVersion,
        public array $schema,
        public string $schemaVersion,
        public int $maxOutputTokens,
    ) {}

    public function promptHash(): string
    {
        return hash('sha256', $this->prompt);
    }

    public function schemaHash(): string
    {
        return CanonicalJson::hash($this->schema);
    }

    /** @param array<string, mixed> $outbound */
    public function renderInput(array $outbound): string
    {
        return CanonicalJson::encode($outbound);
    }

    /** @return array<string, mixed> */
    public function portableSchema(): array
    {
        return AiSchema::portable($this->schema);
    }

    /** @return array<never, never> */
    public function tools(): array
    {
        return [];
    }
}
