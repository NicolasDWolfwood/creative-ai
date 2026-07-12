<?php

namespace App\Services;

final readonly class PostReadinessReport
{
    /**
     * @param  array<string, string>  $blockers
     * @param  array<string, string>  $warnings
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
    ) {}

    /** @return array<string, string> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return array<string, string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function hasBlockers(): bool
    {
        return $this->blockers !== [];
    }

    public function isReady(): bool
    {
        return ! $this->hasBlockers();
    }

    /**
     * @return array{ready: bool, blockers: array<string, string>, warnings: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->isReady(),
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
        ];
    }
}
