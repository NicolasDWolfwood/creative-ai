<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\MusicStructuredData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

class MusicStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://creative.test');
        Queue::fake();

        if (! Route::has('music.playlists.show')) {
            Route::get('/music/playlists/{playlist:slug}', fn (): string => '')
                ->name('music.playlists.show');
        }
    }

    public function test_album_schema_has_stable_ids_ordered_recordings_and_only_public_relations(): void
    {
        $publishedAt = now()->subDays(10);
        $cover = $this->artwork('Public cover');
        $album = Album::query()->create([
            'cover_artwork_id' => $cover->id,
            'title' => 'Night Signals',
            'slug' => 'night-signals',
            'album_artist' => 'Signal Ensemble',
            'description' => '<p>A public listening study.</p>',
            'embedded_cover_path' => 'private/albums/HIDDEN-SOURCE.jpg',
            'cover_preference' => 'artwork',
            'published' => true,
            'published_at' => $publishedAt,
        ]);
        $second = $this->track($album, [
            'title' => 'Second Signal',
            'slug' => 'second-signal',
            'track_number' => 2,
            'duration_seconds' => 3723,
        ]);
        $first = $this->track($album, [
            'title' => 'First Signal',
            'slug' => 'first-signal',
            'artist' => 'Guest Artist',
            'description' => '<strong>The opening movement.</strong>',
            'track_number' => 1,
            'duration_seconds' => 185,
            'original_filename' => 'ADMIN-ONLY-SOURCE.wav',
            'metadata' => ['source_url' => 'https://private.test/source'],
            'ai_model' => 'PRIVATE-AI-MODEL',
            'ai_suggestion' => ['secret' => 'AI-PRIVATE-RESULT'],
            'ai_error' => 'ADMIN-ONLY-ERROR',
        ]);
        $publicPlaylist = $this->playlist('Public sequence', true);
        $draftPlaylist = $this->playlist('ADMIN-ONLY-PLAYLIST', false);
        $publicPlaylist->tracks()->attach($first, ['position' => 1]);
        $draftPlaylist->tracks()->attach($first, ['position' => 1]);

        $schema = app(MusicStructuredData::class)->forAlbum($album);
        $canonical = route('music.albums.show', $album);
        $graph = collect($schema['@graph'])->keyBy('@id');
        $albumNode = $graph[$canonical.'#album'];
        $list = $graph[$canonical.'#tracks'];
        $firstRecording = $graph[route('music.tracks.show', $first).'#recording'];
        $firstAudio = $graph[route('music.tracks.show', $first).'#audio'];

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('MusicAlbum', $albumNode['@type']);
        $this->assertSame($canonical, $albumNode['url']);
        $this->assertSame('A public listening study.', $albumNode['description']);
        $this->assertSame($cover->thumb_url, $albumNode['image']);
        $this->assertSame('Signal Ensemble', $albumNode['byArtist']['name']);
        $this->assertSame($publishedAt->toIso8601String(), $albumNode['datePublished']);
        $this->assertSame(2, $albumNode['numTracks']);
        $this->assertSame([1, 2], array_column($list['itemListElement'], 'position'));
        $this->assertSame([
            route('music.tracks.show', $first).'#recording',
            route('music.tracks.show', $second).'#recording',
        ], collect($list['itemListElement'])->pluck('item.@id')->all());
        $this->assertSame('PT3M5S', $firstRecording['duration']);
        $this->assertSame('Guest Artist', $firstRecording['byArtist']['name']);
        $this->assertSame($canonical.'#album', $firstRecording['inAlbum']['@id']);
        $this->assertSame(
            [route('music.playlists.show', $publicPlaylist).'#playlist'],
            collect($firstRecording['inPlaylist'])->pluck('@id')->all(),
        );
        $this->assertSame('AudioObject', $firstAudio['@type']);
        $this->assertSame($first->audio_url, $firstAudio['contentUrl']);
        $this->assertSame('PT3M5S', $firstAudio['duration']);

        $json = json_encode($schema, JSON_THROW_ON_ERROR);
        foreach (['HIDDEN-SOURCE', 'ADMIN-ONLY', 'private.test', 'PRIVATE-AI', 'AI-PRIVATE'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $json);
        }
    }

    public function test_track_schema_uses_the_effective_publication_source_without_leaking_a_future_standalone_date(): void
    {
        $albumPublishedAt = Carbon::parse('2026-01-02 12:00:00');
        $futureStandaloneAt = now()->addYear();
        $album = Album::query()->create([
            'title' => 'Present Album',
            'slug' => 'present-album',
            'artist' => 'Present Artist',
            'description' => 'Already available as an album.',
            'embedded_cover_path' => 'albums/present-cover.jpg',
            'cover_preference' => 'embedded',
            'published' => true,
            'published_at' => $albumPublishedAt,
        ]);
        $track = $this->track($album, [
            'title' => 'Scheduled Single',
            'slug' => 'scheduled-single',
            'standalone_published' => true,
            'standalone_published_at' => $futureStandaloneAt,
            'duration_seconds' => 60,
        ]);
        $publicPlaylist = $this->playlist('Current playlist', true);
        $scheduledPlaylist = Playlist::query()->create([
            'title' => 'Future private relationship',
            'slug' => 'future-private-relationship',
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $publicPlaylist->tracks()->attach($track, ['position' => 1]);
        $scheduledPlaylist->tracks()->attach($track, ['position' => 1]);

        $schema = app(MusicStructuredData::class)->forTrack($track);
        $recording = collect($schema['@graph'])->firstWhere('@type', 'MusicRecording');

        $this->assertSame(route('music.tracks.show', $track).'#recording', $recording['@id']);
        $this->assertSame($albumPublishedAt->toIso8601String(), $recording['datePublished']);
        $this->assertSame(route('music.albums.show', $album).'#album', $recording['inAlbum']['@id']);
        $this->assertSame(
            [route('music.playlists.show', $publicPlaylist).'#playlist'],
            collect($recording['inPlaylist'])->pluck('@id')->all(),
        );
        $this->assertSame(route('media.albums.embedded-cover', [
            $album,
            'v' => substr(hash('sha256', $album->embedded_cover_path), 0, 12),
        ]), $recording['image']);
        $this->assertStringNotContainsString(
            $futureStandaloneAt->toIso8601String(),
            json_encode($schema, JSON_THROW_ON_ERROR),
        );
    }

    public function test_playlist_schema_preserves_visible_order_and_omits_unavailable_tracks(): void
    {
        $playlistCover = $this->artwork('Playlist cover');
        $playlist = Playlist::query()->create([
            'cover_artwork_id' => $playlistCover->id,
            'title' => 'Connected Listening',
            'slug' => 'connected-listening',
            'description' => '<p>An ordered public playlist.</p>',
            'published' => true,
            'published_at' => now()->subDay(),
        ]);
        $album = Album::query()->create([
            'title' => 'Public Source Album',
            'slug' => 'public-source-album',
            'published' => true,
            'published_at' => now()->subDays(3),
        ]);
        $albumTrack = $this->track($album, [
            'title' => 'Album-only Track',
            'slug' => 'album-only-track',
            'duration_seconds' => 125,
        ]);
        $standalone = $this->track(null, [
            'title' => 'Standalone Track',
            'slug' => 'standalone-track-schema',
            'standalone_published' => true,
            'standalone_published_at' => now()->subDays(2),
            'duration_seconds' => 45,
        ]);
        $draftAlbum = Album::query()->create([
            'title' => 'Private Source Album',
            'slug' => 'private-source-album',
            'published' => false,
        ]);
        $unavailable = $this->track($draftAlbum, [
            'title' => 'ADMIN-ONLY-TRACK',
            'slug' => 'admin-only-track',
        ]);
        $playlist->tracks()->attach($standalone, ['position' => 1]);
        $playlist->tracks()->attach($unavailable, ['position' => 2]);
        $playlist->tracks()->attach($albumTrack, ['position' => 3]);

        $schema = app(MusicStructuredData::class)->forPlaylist($playlist);
        $canonical = route('music.playlists.show', $playlist);
        $graph = collect($schema['@graph'])->keyBy('@id');
        $playlistNode = $graph[$canonical.'#playlist'];
        $list = $graph[$canonical.'#tracks'];
        $albumRecording = $graph[route('music.tracks.show', $albumTrack).'#recording'];
        $standaloneRecording = $graph[route('music.tracks.show', $standalone).'#recording'];

        $this->assertSame('MusicPlaylist', $playlistNode['@type']);
        $this->assertSame('An ordered public playlist.', $playlistNode['description']);
        $this->assertSame($playlistCover->thumb_url, $playlistNode['image']);
        $this->assertSame(2, $playlistNode['numTracks']);
        $this->assertSame([1, 2], array_column($list['itemListElement'], 'position'));
        $this->assertSame([
            route('music.tracks.show', $standalone).'#recording',
            route('music.tracks.show', $albumTrack).'#recording',
        ], collect($list['itemListElement'])->pluck('item.@id')->all());
        $this->assertSame($canonical.'#playlist', $albumRecording['inPlaylist'][0]['@id']);
        $this->assertSame(route('music.albums.show', $album).'#album', $albumRecording['inAlbum']['@id']);
        $this->assertArrayNotHasKey('inAlbum', $standaloneRecording);
        $this->assertStringNotContainsString('ADMIN-ONLY-TRACK', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function test_private_roots_are_rejected_before_they_can_be_serialized(): void
    {
        $service = app(MusicStructuredData::class);
        $album = Album::query()->create(['title' => 'Draft album', 'slug' => 'draft-schema-album', 'published' => false]);

        $this->expectException(InvalidArgumentException::class);
        $service->forAlbum($album);
    }

    /** @param array<string, mixed> $attributes */
    private function track(?Album $album, array $attributes): Track
    {
        return Track::query()->create(array_replace([
            'album_id' => $album?->id,
            'title' => 'Track',
            'slug' => 'track-'.str()->uuid(),
            'artist' => null,
            'audio_path' => 'tracks/audio/'.str()->uuid().'.mp3',
            'published' => false,
            'standalone_published' => false,
        ], $attributes));
    }

    private function artwork(string $title): Artwork
    {
        return Artwork::query()->create([
            'title' => $title,
            'slug' => str($title)->slug().'-'.str()->random(6),
            'image_path' => 'artworks/originals/'.str()->uuid().'.jpg',
            'published' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    private function playlist(string $title, bool $published): Playlist
    {
        return Playlist::query()->create([
            'title' => $title,
            'slug' => str($title)->slug().'-'.str()->random(6),
            'published' => $published,
            'published_at' => $published ? now()->subDay() : null,
        ]);
    }
}
