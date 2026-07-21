<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

class SmartCollectionService
{
    public function sync(Collection $collection, bool $explicit = false): int
    {
        if (! $collection->is_smart) {
            return 0;
        }

        $rules = is_array($collection->smart_rules) ? $collection->smart_rules : [];

        if ($this->requiresSnapshot($collection, $rules)) {
            if (! $explicit) {
                $collection->forceFill(['auto_sync' => false])->saveQuietly();

                return $collection->artworks()->count();
            }

            $collection->forceFill(['auto_sync' => false])->saveQuietly();
        }

        $tagIds = collect($rules['tag_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        $query = Artwork::query();

        if ((bool) ($rules['only_published'] ?? true)) {
            // Keep deliberately scheduled standalone artwork in live smart
            // memberships. Public scopes enforce its timestamp, so it appears
            // automatically when due without a scheduler-driven resync.
            $query->where('published', true);
        } elseif ($this->requiresSnapshot($collection, $rules) || (bool) ($rules['exclude_future_scheduled'] ?? false)) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
        }

        if ($collection->is_auto_generated || (bool) ($rules['only_ai_applied'] ?? false)) {
            $query
                ->where('ai_status', Artwork::AI_STATUS_APPLIED)
                ->whereNotNull('ai_analyzed_at');
        } elseif ((bool) ($rules['only_analyzed'] ?? false)) {
            $query->whereNotNull('ai_analyzed_at');
        }

        if ($tagIds->isNotEmpty()) {
            if (($rules['match'] ?? 'any') === 'all') {
                $tagIds->each(fn (int $tagId) => $query->whereHas('tags', fn (Builder $query) => $query->whereKey($tagId)));
            } else {
                $query->whereHas('tags', fn (Builder $query) => $query->whereKey($tagIds->all()));
            }
        } else {
            $query->whereRaw('1 = 0');
        }

        $artworks = $query
            ->orderByDesc('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ((bool) ($rules['only_with_available_media'] ?? false)) {
            $artworks = $artworks
                ->filter(fn (Artwork $artwork): bool => $artwork->hasAvailableImage())
                ->values();
        }

        $ids = $artworks->pluck('id')->all();
        $collection->artworks()->sync($ids);
        $collection->forceFill(['last_synced_at' => now()])->saveQuietly();

        return count($ids);
    }

    public function syncAutomatic(): int
    {
        $count = 0;

        Collection::query()->where('is_smart', true)->where('auto_sync', true)->each(function (Collection $collection) use (&$count): void {
            $this->sync($collection);
            $count++;
        });

        return $count;
    }

    /** @param array<string, mixed> $rules */
    protected function requiresSnapshot(Collection $collection, array $rules): bool
    {
        return (bool) $collection->publishes_members
            && ! (bool) ($rules['only_published'] ?? true);
    }
}
