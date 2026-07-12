<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait HasPublicationSchedule
{
    #[Scope]
    protected function published(Builder $query): void
    {
        $query
            ->where('published', true)
            ->where(function (Builder $query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function isPubliclyPublished(): bool
    {
        return (bool) $this->published
            && (! $this->published_at || $this->published_at->isPast());
    }
}
