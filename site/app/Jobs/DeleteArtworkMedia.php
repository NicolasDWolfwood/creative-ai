<?php

namespace App\Jobs;

use App\Services\ArtworkMediaCleanupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteArtworkMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    /** @param array<int, string|null> $paths */
    public function __construct(public array $paths)
    {
        $this->onQueue('default');
    }

    public function handle(ArtworkMediaCleanupService $cleanup): void
    {
        $cleanup->deleteUnreferenced($this->paths);
    }
}
