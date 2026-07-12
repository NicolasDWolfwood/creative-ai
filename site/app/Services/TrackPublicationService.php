<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Carbon;

class TrackPublicationService
{
    public function prepareForSave(Track $track): void
    {
        $standaloneWasExplicit = $track->isDirty('standalone_published');
        $standaloneDateWasExplicit = $track->isDirty('standalone_published_at');

        if (! $standaloneWasExplicit && $track->isDirty('published')) {
            $track->standalone_published = (bool) $track->published;
        }

        if (! $standaloneDateWasExplicit
            && $track->isDirty('published_at')
            && ($track->isDirty('published') || $track->standalone_published)) {
            $track->standalone_published_at = $track->published_at;
        }

        $this->applyLegacyState($track, $this->resolveAlbum($track));
    }

    public function syncAlbum(Album $album): void
    {
        Track::query()
            ->where('album_id', $album->id)
            ->orderBy('id')
            ->each(function (Track $track) use ($album): void {
                $this->applyLegacyState($track, $album);

                if ($track->isDirty(['published', 'published_at'])) {
                    $track->saveQuietly();
                }
            });
    }

    public function syncAlbumRemoval(Album $album): void
    {
        Track::query()
            ->where('album_id', $album->id)
            ->orderBy('id')
            ->each(function (Track $track): void {
                $this->applyLegacyState($track, null);

                if ($track->isDirty(['published', 'published_at'])) {
                    $track->saveQuietly();
                }
            });
    }

    public function syncForAlbum(Track $track, Album $album): void
    {
        $this->applyLegacyState($track, $album);
    }

    protected function resolveAlbum(Track $track): ?Album
    {
        if (! $track->album_id) {
            return null;
        }

        if (! $track->isDirty('album_id')
            && $track->relationLoaded('album')
            && $track->album?->getKey() === $track->album_id) {
            return $track->album;
        }

        return Album::query()->select(['id', 'published', 'published_at'])->find($track->album_id);
    }

    protected function applyLegacyState(Track $track, ?Album $album): void
    {
        $sources = [];

        if ($track->standalone_published) {
            $sources[] = $track->standalone_published_at;
        }

        if ($album?->published) {
            $sources[] = $album->published_at;
        }

        $track->published = $sources !== [];
        $track->published_at = $this->earliestDate($sources);
    }

    /** @param array<int, Carbon|null> $dates */
    protected function earliestDate(array $dates): ?Carbon
    {
        $earliest = null;

        foreach ($dates as $date) {
            if ($date === null) {
                return null;
            }

            if ($earliest === null || $date->lessThan($earliest)) {
                $earliest = $date;
            }
        }

        return $earliest;
    }
}
