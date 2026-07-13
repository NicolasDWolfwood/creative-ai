<?php

namespace App\Data;

final readonly class JournalAiContext
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public array $manifest,
        public string $contextHash,
        public string $sourceHash,
    ) {}

    /** @return array<string, mixed> */
    public function outbound(): array
    {
        $outbound = $this->manifest['outbound'] ?? [];

        return is_array($outbound) ? $outbound : [];
    }

    /** @return array<string, mixed> */
    public function selection(): array
    {
        $selection = $this->manifest['selection'] ?? [];

        return is_array($selection) ? $selection : [];
    }
}
