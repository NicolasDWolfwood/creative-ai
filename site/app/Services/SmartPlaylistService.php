<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

class SmartPlaylistService
{
    public function sync(Playlist $playlist): int
    {
        if (! $playlist->is_smart) {
            return 0;
        }

        $rules = is_array($playlist->smart_rules) ? $playlist->smart_rules : [];
        $tagIds = collect($rules['tag_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        $query = Track::query();

        if ((bool) ($rules['only_published'] ?? true)) {
            $query->where('published', true);
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

        $ids = $query->orderBy('sort_order')->orderBy('id')->pluck('id');
        $sync = $ids->values()->mapWithKeys(fn (int $id, int $position): array => [$id => ['position' => $position + 1]])->all();
        $playlist->tracks()->sync($sync);
        $playlist->forceFill(['last_synced_at' => now()])->saveQuietly();

        return $ids->count();
    }

    public function syncAutomatic(): int
    {
        $count = 0;

        Playlist::query()->where('is_smart', true)->where('auto_sync', true)->each(function (Playlist $playlist) use (&$count): void {
            $this->sync($playlist);
            $count++;
        });

        return $count;
    }
}
