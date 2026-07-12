<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\Track;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudioMetadataService
{
    public function __construct(
        protected AlbumMatchingService $albums,
        protected PrivateMediaService $privateMedia,
    ) {}

    /** @return array<string, mixed> */
    public function extract(string $path, ?string $originalFilename = null): array
    {
        $fallback = $this->fromFilename($originalFilename ?: basename($path));
        $metadata = $fallback;

        try {
            $analysis = (new \getID3)->analyze($this->privateMedia->absolutePath($path));
            \getid3_lib::CopyTagsToComments($analysis);
            $comments = $analysis['comments'] ?? [];
            $value = fn (string $key): ?string => filled($comments[$key][0] ?? null)
                ? Str::of((string) $comments[$key][0])->squish()->toString()
                : null;

            $metadata = array_filter([
                'title' => $value('title') ?: ($fallback['title'] ?? null),
                'artist' => $value('artist') ?: ($fallback['artist'] ?? null),
                'album' => $value('album') ?: ($fallback['album'] ?? null),
                'album_artist' => $value('band') ?: $value('album_artist'),
                'release_year' => $this->number($value('year')),
                'track_number' => $this->number($value('track_number')) ?: ($fallback['track_number'] ?? null),
                'disc_number' => $this->number($value('part_of_a_set')) ?: ($fallback['disc_number'] ?? null),
                'duration_seconds' => isset($analysis['playtime_seconds']) ? (int) round($analysis['playtime_seconds']) : null,
                'genres' => array_values(array_unique(array_filter(array_map(
                    fn (mixed $genre): string => Str::of((string) $genre)->squish()->lower()->toString(),
                    $comments['genre'] ?? [],
                )))),
                'format' => $analysis['fileformat'] ?? null,
                'audio' => $analysis['audio'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

            $picture = $comments['picture'][0] ?? null;
            if (is_array($picture) && isset($picture['data'])) {
                $mime = (string) ($picture['image_mime'] ?? 'image/jpeg');
                $extension = match ($mime) {
                    'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
                };
                $coverPath = 'albums/embedded/'.hash('sha256', $picture['data']).'.'.$extension;
                Storage::disk('local')->put($coverPath, $picture['data']);
                $metadata['embedded_cover_path'] = $coverPath;
            }
        } catch (\Throwable $exception) {
            $metadata['extraction_error'] = Str::limit($exception->getMessage(), 500, '');
        }

        return $metadata;
    }

    public function apply(Track $track): void
    {
        if (blank($track->audio_path)) {
            return;
        }

        $extracted = $this->extract($track->audio_path, $track->original_filename);
        $track->title = $track->title ?: ($extracted['title'] ?? null);
        $track->artist = $track->artist ?: ($extracted['artist'] ?? null);
        $track->duration_seconds = $track->duration_seconds ?: ($extracted['duration_seconds'] ?? null);
        $track->disc_number = $track->disc_number ?: ($extracted['disc_number'] ?? null);
        $track->track_number = $track->track_number ?: ($extracted['track_number'] ?? null);
        $track->release_year = $track->release_year ?: ($extracted['release_year'] ?? null);
        $track->metadata = array_replace($track->metadata ?? [], ['audio_import' => $extracted]);

        if (blank($track->slug) && filled($track->title)) {
            $base = Str::slug($track->title) ?: Str::random(8);
            $slug = $base;
            $suffix = 2;
            while (Track::query()->where('slug', $slug)->when($track->exists, fn ($query) => $query->whereKeyNot($track->getKey()))->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            $track->slug = $slug;
        }

        if (! $track->album_id && filled($extracted['album'] ?? null)) {
            $track->album_id = $this->albums->resolve($extracted, $track->artist)?->id;
        }
    }

    public function syncGenres(Track $track): void
    {
        foreach (data_get($track->metadata, 'audio_import.genres', []) as $name) {
            $slug = Str::slug($name);
            if (blank($slug)) {
                continue;
            }
            $tag = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
            $track->tags()->syncWithoutDetaching([$tag->id => ['category' => 'genre']]);
        }
    }

    /** @return array<string, mixed> */
    public function fromFilename(string $filename): array
    {
        $stem = Str::of(pathinfo($filename, PATHINFO_FILENAME))->replace(['_', '.'], ' ')->squish()->toString();
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s+-\s+/', $stem) ?: [])));
        $leadingNumber = null;
        if (isset($parts[0]) && preg_match('/^(?:cd|disc)?\s*(\d{1,3})$/i', $parts[0], $match)) {
            $leadingNumber = (int) $match[1];
            array_shift($parts);
        }
        if (count($parts) >= 4 && preg_match('/^\d{1,3}$/', $parts[2])) {
            return ['artist' => $parts[0], 'album' => $parts[1], 'track_number' => (int) $parts[2], 'title' => implode(' - ', array_slice($parts, 3))];
        }
        if (count($parts) >= 2) {
            return array_filter(['artist' => array_shift($parts), 'title' => implode(' - ', $parts), 'track_number' => $leadingNumber]);
        }

        return array_filter(['title' => preg_replace('/^\d{1,3}[\s._-]+/', '', $stem) ?: $stem, 'track_number' => $leadingNumber]);
    }

    protected function number(?string $value): ?int
    {
        return filled($value) && preg_match('/\d+/', $value, $match) ? (int) $match[0] : null;
    }
}
