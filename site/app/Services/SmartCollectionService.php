<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

class SmartCollectionService
{
    public function sync(Collection $collection): int
    {
        if (! $collection->is_smart) {
            return 0;
        }

        $rules = is_array($collection->smart_rules) ? $collection->smart_rules : [];
        $tagIds = collect($rules['tag_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        $query = Artwork::query();

        if ((bool) ($rules['only_published'] ?? true)) {
            $query->published();
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

        $ids = $query->orderByDesc('sort_order')->pluck('id')->all();
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
}
