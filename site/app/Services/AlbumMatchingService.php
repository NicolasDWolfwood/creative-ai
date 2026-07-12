<?php

namespace App\Services;

use App\Models\Album;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class AlbumMatchingService
{
    /** @param array<string, mixed> $metadata */
    public function resolve(array $metadata, ?string $fallbackArtist = null): ?Album
    {
        $title = Str::of((string) ($metadata['album'] ?? ''))->squish()->toString();
        if ($title === '') {
            return null;
        }

        $albumArtist = $this->clean($metadata['album_artist'] ?? null);
        $releaseYear = filled($metadata['release_year'] ?? null) ? (int) $metadata['release_year'] : null;
        $key = $this->key($title, $albumArtist);
        $album = Album::query()->where('import_key', $key)->first();

        if (! $album) {
            $album = $this->compatibleExisting($title, $albumArtist, $releaseYear);
        }

        if (! $album) {
            try {
                $album = Album::query()->create([
                    'title' => $title,
                    'artist' => $albumArtist ?: $this->clean($fallbackArtist),
                    'album_artist' => $albumArtist,
                    'import_key' => $key,
                    'release_year' => $releaseYear,
                    'embedded_cover_path' => $metadata['embedded_cover_path'] ?? null,
                ]);
            } catch (QueryException $exception) {
                $album = Album::query()->where('import_key', $key)->first();
                if (! $album) {
                    throw $exception;
                }
            }
        }

        $updates = array_filter([
            'import_key' => blank($album->import_key) ? $key : null,
            'artist' => blank($album->artist) ? ($albumArtist ?: $this->clean($fallbackArtist)) : null,
            'album_artist' => blank($album->album_artist) ? $albumArtist : null,
            'release_year' => blank($album->release_year) ? $releaseYear : null,
            'embedded_cover_path' => blank($album->embedded_cover_path) ? ($metadata['embedded_cover_path'] ?? null) : null,
        ], fn (mixed $value): bool => filled($value));

        if ($updates !== []) {
            $album->forceFill($updates)->saveQuietly();
        }

        return $album->refresh();
    }

    public function key(string $title, ?string $albumArtist): string
    {
        return hash('sha256', implode('|', [
            $this->normalize($title),
            $this->normalize($albumArtist),
        ]));
    }

    protected function compatibleExisting(string $title, ?string $albumArtist, ?int $releaseYear): ?Album
    {
        $candidates = Album::query()->whereRaw('LOWER(title) = ?', [Str::lower($title)])->orderBy('id')->get();
        if ($albumArtist) {
            return $candidates->first(fn (Album $album): bool => in_array(
                $this->normalize($albumArtist),
                [$this->normalize($album->album_artist), $this->normalize($album->artist)],
                true,
            ));
        }
        if ($releaseYear) {
            $matchingYear = $candidates->filter(fn (Album $album): bool => ! $album->release_year || $album->release_year === $releaseYear);
            if ($matchingYear->count() === 1) {
                return $matchingYear->first();
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    protected function clean(mixed $value): ?string
    {
        $value = Str::of((string) $value)->squish()->toString();

        return $value === '' ? null : $value;
    }

    protected function normalize(mixed $value): string
    {
        return Str::of((string) $value)->lower()->squish()->ascii()->toString();
    }
}
