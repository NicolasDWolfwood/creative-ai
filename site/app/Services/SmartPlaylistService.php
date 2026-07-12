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
        } elseif (empty(array_filter($rules, fn ($value, $key) => ! in_array($key, ['match', 'only_published', 'order', 'max_tracks'], true) && filled($value), ARRAY_FILTER_USE_BOTH))) {
            $query->whereRaw('1 = 0');
        }

        $query->when(filled($rules['artist'] ?? null), fn (Builder $q) => $q->where('artist', $rules['artist']))
            ->when(filled($rules['album_ids'] ?? []), fn (Builder $q) => $q->whereIn('album_id', $rules['album_ids']))
            ->when(filled($rules['min_duration'] ?? null), fn (Builder $q) => $q->where('duration_seconds', '>=', $rules['min_duration']))
            ->when(filled($rules['max_duration'] ?? null), fn (Builder $q) => $q->where('duration_seconds', '<=', $rules['max_duration']))
            ->when(filled($rules['year_from'] ?? null), fn (Builder $q) => $q->where('release_year', '>=', $rules['year_from']))
            ->when(filled($rules['year_to'] ?? null), fn (Builder $q) => $q->where('release_year', '<=', $rules['year_to']))
            ->when(array_key_exists('featured', $rules) && $rules['featured'] !== null, fn (Builder $q) => $q->where('featured', (bool) $rules['featured']))
            ->when(filled($rules['health_status'] ?? null), fn (Builder $q) => $q->where('health_status', $rules['health_status']))
            ->when(($rules['has_cover'] ?? null) === true, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNotNull('cover_artwork_id')->orWhereHas('album', fn (Builder $q) => $q->whereNotNull('cover_artwork_id')->orWhereNotNull('embedded_cover_path'))))
            ->when(($rules['has_cover'] ?? null) === false, fn (Builder $q) => $q->whereNull('cover_artwork_id')->whereDoesntHave('album', fn (Builder $q) => $q->whereNotNull('cover_artwork_id')->orWhereNotNull('embedded_cover_path')))
            ->when(filled($rules['added_within_days'] ?? null), fn (Builder $q) => $q->where('created_at', '>=', now()->subDays((int) $rules['added_within_days'])));

        match ($rules['order'] ?? 'library') {
            'newest' => $query->latest(), 'oldest' => $query->oldest(), 'title' => $query->orderBy('title'),
            'duration' => $query->orderBy('duration_seconds'), 'random' => $query->inRandomOrder(),
            default => $query->orderBy('sort_order')->orderBy('id'),
        };
        $query->when((int) ($rules['max_tracks'] ?? 0) > 0, fn (Builder $q) => $q->limit((int) $rules['max_tracks']));
        $ids = $query->pluck('id');
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
