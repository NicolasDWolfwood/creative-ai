<?php

namespace App\Data;

use App\Models\Post;

final readonly class JournalDraftPlanningResult
{
    public function __construct(
        public Post $post,
        public bool $created,
    ) {}
}
