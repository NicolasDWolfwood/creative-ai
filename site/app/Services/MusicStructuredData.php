<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MusicStructuredData
{
    private const CREATOR_NAME = 'John Reijmer';

    public function __construct(
        private readonly PublicStoryConnections $connections,
    ) {}

    /** @return array<string, mixed> */
    public function forAlbum(Album $album): array
    {
        $this->assertPublic($album->isPubliclyPublished(), 'album');

        $album->loadMissing([
            'coverArtwork',
            'tracks' => fn ($query) => $query->publiclyAvailable(),
            'tracks.coverArtwork',
            'tracks.tags',
            'tracks.playlists' => fn ($query) => $query->published(),
        ]);

        /** @var Collection<int, Track> $tracks */
        $tracks = $album->tracks
            ->filter(fn (Track $track): bool => $this->trackIsPublic($track, $album))
            ->values();
        $canonical = route('music.albums.show', $album);
        $albumId = $canonical.'#album';
        $listId = $canonical.'#tracks';
        $graph = [
            $this->withoutEmpty([
                '@type' => 'MusicAlbum',
                '@id' => $albumId,
                'name' => $album->title,
                'url' => $canonical,
                'description' => $this->description($album->description),
                'image' => $this->albumImage($album),
                'byArtist' => $this->artist($album->album_artist ?: $album->artist),
                'creator' => ['@id' => $this->creatorId()],
                'datePublished' => $album->published_at?->toIso8601String(),
                'numTracks' => $tracks->count(),
                'track' => ['@id' => $listId],
                'keywords' => $tracks->flatMap->tags->pluck('name')->unique()->values()->all(),
                'subjectOf' => $this->postReferences($album),
            ]),
            $this->trackList($listId, $tracks),
        ];

        foreach ($tracks as $track) {
            $playlists = $this->publicPlaylists($track->playlists);
            $graph[] = $this->recording($track, $album, $playlists);
            $graph[] = $this->audio($track);
        }

        $graph[] = $this->creator();

        return $this->document($graph);
    }

    /** @return array<string, mixed> */
    public function forTrack(Track $track): array
    {
        $track->loadMissing([
            'album.coverArtwork',
            'coverArtwork',
            'tags',
            'playlists' => fn ($query) => $query->published(),
        ]);

        $album = $this->publicAlbum($track);
        $this->assertPublic($this->trackIsPublic($track, $album), 'track');
        $playlists = $this->publicPlaylists($track->playlists);

        return $this->document([
            $this->recording($track, $album, $playlists, $this->connections->postsForMedia($track)),
            $this->audio($track),
            $this->creator(),
        ]);
    }

    /** @return array<string, mixed> */
    public function forPlaylist(Playlist $playlist): array
    {
        $this->assertPublic($playlist->isPubliclyPublished(), 'playlist');

        $playlist->loadMissing([
            'coverArtwork',
            'tracks' => fn ($query) => $query->publiclyAvailable(),
            'tracks.album.coverArtwork',
            'tracks.coverArtwork',
            'tracks.tags',
        ]);

        /** @var Collection<int, Track> $tracks */
        $tracks = $playlist->tracks
            ->filter(fn (Track $track): bool => $this->trackIsPublic($track, $this->publicAlbum($track)))
            ->values();
        $canonical = route('music.playlists.show', $playlist);
        $playlistId = $canonical.'#playlist';
        $listId = $canonical.'#tracks';
        $graph = [
            $this->withoutEmpty([
                '@type' => 'MusicPlaylist',
                '@id' => $playlistId,
                'name' => $playlist->title,
                'url' => $canonical,
                'description' => $this->description($playlist->description),
                'image' => $this->publicArtworkImage($playlist->coverArtwork),
                'creator' => ['@id' => $this->creatorId()],
                'datePublished' => $playlist->published_at?->toIso8601String(),
                'numTracks' => $tracks->count(),
                'track' => ['@id' => $listId],
                'keywords' => $tracks->flatMap->tags->pluck('name')->unique()->values()->all(),
                'subjectOf' => $this->postReferences($playlist),
            ]),
            $this->trackList($listId, $tracks),
        ];

        foreach ($tracks as $track) {
            $graph[] = $this->recording($track, $this->publicAlbum($track), collect([$playlist]));
            $graph[] = $this->audio($track);
        }

        $graph[] = $this->creator();

        return $this->document($graph);
    }

    /**
     * @param  array<int, array<string, mixed>>  $graph
     * @return array<string, mixed>
     */
    private function document(array $graph): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * @param  Collection<int, Track>  $tracks
     * @return array<string, mixed>
     */
    private function trackList(string $id, Collection $tracks): array
    {
        return [
            '@type' => 'ItemList',
            '@id' => $id,
            'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
            'numberOfItems' => $tracks->count(),
            'itemListElement' => $tracks
                ->values()
                ->map(fn (Track $track, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => ['@id' => $this->recordingId($track)],
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Playlist>  $playlists
     * @return array<string, mixed>
     */
    private function recording(Track $track, ?Album $album, Collection $playlists, ?Collection $stories = null): array
    {
        $duration = $this->duration($track->duration_seconds);
        $playlistReferences = $playlists
            ->map(fn (Playlist $playlist): array => $this->playlistReference($playlist))
            ->values()
            ->all();

        return $this->withoutEmpty([
            '@type' => 'MusicRecording',
            '@id' => $this->recordingId($track),
            'name' => $track->title,
            'url' => route('music.tracks.show', $track),
            'description' => $this->description($track->description),
            'image' => $this->trackImage($track, $album),
            'byArtist' => $this->artist($track->artist ?: $album?->album_artist ?: $album?->artist),
            'creator' => ['@id' => $this->creatorId()],
            'datePublished' => $this->effectiveTrackPublicationDate($track, $album),
            'duration' => $duration,
            'audio' => ['@id' => $this->audioId($track)],
            'inAlbum' => $album ? $this->albumReference($album) : null,
            'inPlaylist' => $playlistReferences,
            'keywords' => $track->tags->pluck('name')->values()->all(),
            'subjectOf' => $stories?->map(fn ($post): array => [
                '@id' => route('posts.show', $post).'#article',
            ])->values()->all(),
        ]);
    }

    /** @return array<int, array<string, string>> */
    private function postReferences(Model $media): array
    {
        return $this->connections
            ->postsForMedia($media)
            ->map(fn ($post): array => [
                '@id' => route('posts.show', $post).'#article',
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function audio(Track $track): array
    {
        return $this->withoutEmpty([
            '@type' => 'AudioObject',
            '@id' => $this->audioId($track),
            'name' => $track->title,
            'contentUrl' => $track->audio_url,
            'duration' => $this->duration($track->duration_seconds),
        ]);
    }

    /** @return array<string, mixed> */
    private function creator(): array
    {
        return [
            '@type' => 'Person',
            '@id' => $this->creatorId(),
            'name' => self::CREATOR_NAME,
            'url' => route('home'),
        ];
    }

    /** @return array<string, mixed> */
    private function artist(?string $name): array
    {
        $name = $this->description($name);

        if (! $name || $name === self::CREATOR_NAME) {
            return ['@id' => $this->creatorId()];
        }

        return [
            '@type' => 'MusicGroup',
            'name' => $name,
        ];
    }

    /** @return array<string, mixed> */
    private function albumReference(Album $album): array
    {
        $canonical = route('music.albums.show', $album);

        return [
            '@type' => 'MusicAlbum',
            '@id' => $canonical.'#album',
            'name' => $album->title,
            'url' => $canonical,
        ];
    }

    /** @return array<string, mixed> */
    private function playlistReference(Playlist $playlist): array
    {
        $canonical = route('music.playlists.show', $playlist);

        return [
            '@type' => 'MusicPlaylist',
            '@id' => $canonical.'#playlist',
            'name' => $playlist->title,
            'url' => $canonical,
        ];
    }

    private function recordingId(Track $track): string
    {
        return route('music.tracks.show', $track).'#recording';
    }

    private function audioId(Track $track): string
    {
        return route('music.tracks.show', $track).'#audio';
    }

    private function creatorId(): string
    {
        return route('home').'#creator';
    }

    private function effectiveTrackPublicationDate(Track $track, ?Album $album): ?string
    {
        $dates = collect();

        if ($track->isPubliclyPublished() && $track->standalone_published_at) {
            $dates->push($track->standalone_published_at);
        }

        if ($album?->isPubliclyPublished() && $album->published_at) {
            $dates->push($album->published_at);
        }

        return $dates->sort()->first()?->toIso8601String();
    }

    private function trackIsPublic(Track $track, ?Album $album): bool
    {
        return $track->isPubliclyPublished() || $album?->isPubliclyPublished() === true;
    }

    private function publicAlbum(Track $track): ?Album
    {
        $album = $track->relationLoaded('album') ? $track->album : null;

        return $album?->isPubliclyPublished() ? $album : null;
    }

    /**
     * @param  Collection<int, Playlist>  $playlists
     * @return Collection<int, Playlist>
     */
    private function publicPlaylists(Collection $playlists): Collection
    {
        return $playlists
            ->filter(fn (Playlist $playlist): bool => $playlist->isPubliclyPublished())
            ->unique(fn (Playlist $playlist): int|string => $playlist->getKey())
            ->values();
    }

    private function albumImage(Album $album): ?string
    {
        if ($album->cover_preference === 'none') {
            return null;
        }

        $artworkImage = $this->publicArtworkImage($album->coverArtwork);

        if ($album->cover_preference !== 'embedded' && $artworkImage) {
            return $artworkImage;
        }

        if ($album->cover_preference !== 'artwork' && filled($album->embedded_cover_path)) {
            return route('media.albums.embedded-cover', [
                $album,
                'v' => substr(hash('sha256', $album->embedded_cover_path), 0, 12),
            ]);
        }

        return $artworkImage;
    }

    private function trackImage(Track $track, ?Album $album): ?string
    {
        return $this->publicArtworkImage($track->coverArtwork)
            ?: ($album ? $this->albumImage($album) : null);
    }

    private function publicArtworkImage(?Artwork $artwork): ?string
    {
        return $artwork?->isPubliclyPublished() ? $artwork->thumb_url : null;
    }

    private function duration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;
        $duration = 'PT';

        if ($hours > 0) {
            $duration .= $hours.'H';
        }
        if ($minutes > 0) {
            $duration .= $minutes.'M';
        }
        if ($remainingSeconds > 0 || $duration === 'PT') {
            $duration .= $remainingSeconds.'S';
        }

        return $duration;
    }

    private function description(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Str::of($value)->stripTags()->squish()->toString();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function assertPublic(bool $public, string $type): void
    {
        if (! $public) {
            throw new InvalidArgumentException("Structured data is only available for a public {$type}.");
        }
    }
}
