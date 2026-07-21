<?php

namespace App\Data;

use App\Models\Post;

final readonly class JournalDraftBatchPlanningResult
{
    public function __construct(
        public ?Post $post,
        public int $connected,
        public int $skipped,
    ) {}

    public function created(): bool
    {
        return $this->post !== null && $this->connected > 0;
    }
}
