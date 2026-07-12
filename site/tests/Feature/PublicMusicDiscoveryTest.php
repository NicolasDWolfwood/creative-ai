<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Playlist;
use App\Models\Tag;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicMusicDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_playlist_page_keeps_only_playable_tracks_in_stored_order(): void
    {
        Queue::fake();
        $album = Album::query()->create(['title' => 'Public Album', 'published' => false]);
        $albumTrack = $this->track(['title' => 'Album Opening', 'album_id' => $album->id]);
        $standalone = $this->track(['title' => 'Independent Closing', 'standalone_published' => true]);
        $draft = $this->track(['title' => 'Private Interlude']);
        $future = $this->track([
            'title' => 'Tomorrow Signal',
            'standalone_published' => true,
            'standalone_published_at' => now()->addDay(),
        ]);
        $album->update(['published' => true]);

        $playlist = Playlist::query()->create([
            'title' => 'Public Sequence',
            'description' => 'A deliberately ordered listening path.',
            'published' => true,
        ]);
        $playlist->tracks()->attach([
            $standalone->id => ['position' => 4],
            $draft->id => ['position' => 2],
            $albumTrack->id => ['position' => 1],
            $future->id => ['position' => 3],
        ]);

        $response = $this->get(route('music.playlists.show', $playlist));

        $response
            ->assertOk()
            ->assertViewHas('playlist', fn (Playlist $record): bool => $record->tracks->pluck('id')->all() === [$albumTrack->id, $standalone->id])
            ->assertSeeInOrder(['Album Opening', 'Independent Closing'])
            ->assertDontSee('Private Interlude')
            ->assertDontSee('Tomorrow Signal')
            ->assertSee('data-playlist-id="playlist-'.$playlist->id.'"', false)
            ->assertSee('data-track-source-id="playlist-'.$playlist->id.'"', false)
            ->assertSee('data-queue-track-id="'.$albumTrack->id.'"', false)
            ->assertSee(route('music.tracks.show', $albumTrack), false);

        $empty = Playlist::query()->create(['title' => 'Published Empty Session', 'published' => true]);
        $this->get(route('music.playlists.show', $empty))
            ->assertOk()
            ->assertSee('does not have any playable tracks yet');
    }

    public function test_draft_and_scheduled_playlists_are_not_public(): void
    {
        $draft = Playlist::query()->create(['title' => 'Private Session', 'published' => false]);
        $scheduled = Playlist::query()->create([
            'title' => 'Tomorrow Session',
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $published = Playlist::query()->create(['title' => 'Current Session', 'published' => true]);

        $this->get(route('music.playlists.show', $draft))->assertNotFound();
        $this->get(route('music.playlists.show', $scheduled))->assertNotFound();
        $this->get(route('music.playlists.show', $published))->assertOk();
    }

    public function test_music_and_home_discovery_link_to_public_playlists_without_truncating_the_player_search_library(): void
    {
        Queue::fake();
        $album = Album::query()->create(['title' => 'Search Album', 'published' => false]);
        $matchingTrack = $this->track(['title' => 'Hidden Constellation', 'album_id' => $album->id]);
        $otherTrack = $this->track(['title' => 'Other Current', 'standalone_published' => true]);
        $album->update(['published' => true]);

        $matching = Playlist::query()->create(['title' => 'Night Session', 'description' => 'Quiet listening.', 'published' => true]);
        $matching->tracks()->attach($matchingTrack, ['position' => 1]);
        $other = Playlist::query()->create(['title' => 'Day Session', 'published' => true]);
        $other->tracks()->attach($otherTrack, ['position' => 1]);
        $draft = Playlist::query()->create(['title' => 'Private Session', 'published' => false]);
        $draft->tracks()->attach($otherTrack, ['position' => 1]);
        $empty = Playlist::query()->create(['title' => 'Empty Session', 'published' => true]);

        $searchResponse = $this->get(route('music.index', ['q' => 'Hidden Constellation']));
        $searchResponse
            ->assertOk()
            ->assertViewHas('playlists', fn ($playlists): bool => $playlists->pluck('id')->all() === [$matching->id])
            ->assertViewHas('seo', fn (array $seo): bool => $seo['canonical'] === route('music.index'))
            ->assertViewHas('structured_data', fn (array $data): bool => ($data['@type'] ?? null) === 'CollectionPage')
            ->assertViewHas('playerPayload', function (array $payload) use ($matching, $other): bool {
                $ids = collect($payload)->pluck('id');

                return $ids->contains('playlist-'.$matching->id) && $ids->contains('playlist-'.$other->id);
            })
            ->assertSee(route('music.playlists.show', $matching), false)
            ->assertDontSee(route('music.playlists.show', $other), false)
            ->assertDontSee(route('music.playlists.show', $draft), false)
            ->assertDontSee(route('music.playlists.show', $empty), false);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('music.playlists.show', $matching), false)
            ->assertSee('data-playlist-id="playlist-'.$matching->id.'"', false)
            ->assertDontSee(route('music.playlists.show', $draft), false)
            ->assertDontSee(route('music.playlists.show', $empty), false);
    }

    public function test_music_pages_supply_page_specific_open_graph_and_structured_data(): void
    {
        Queue::fake();
        $album = Album::query()->create([
            'title' => 'Structured Album',
            'artist' => 'Studio Artist',
            'description' => 'An ordered album.',
            'release_year' => 2026,
            'published' => false,
        ]);
        $first = $this->track([
            'title' => 'First Recording',
            'artist' => 'Studio Artist',
            'description' => '</script><script>window.musicCompromised = true</script>',
            'album_id' => $album->id,
            'disc_number' => 1,
            'track_number' => 1,
            'duration_seconds' => 65,
        ]);
        $second = $this->track([
            'title' => 'Second Recording',
            'artist' => 'Studio Artist',
            'album_id' => $album->id,
            'disc_number' => 1,
            'track_number' => 2,
            'duration_seconds' => 125,
        ]);
        $album->update(['published' => true]);
        $playlist = Playlist::query()->create([
            'title' => 'Structured Playlist',
            'description' => 'A public sequence.',
            'published' => true,
        ]);
        $playlist->tracks()->attach([
            $second->id => ['position' => 1],
            $first->id => ['position' => 2],
        ]);

        $albumResponse = $this->get(route('music.albums.show', $album));
        $albumResponse
            ->assertOk()
            ->assertViewHas('seo', fn (array $seo): bool => $seo['canonical'] === route('music.albums.show', $album) && $seo['type'] === 'music.album')
            ->assertViewHas('structured_data', fn (array $data): bool => collect($data['@graph'] ?? [])->contains('@type', 'MusicAlbum'))
            ->assertSee('<meta property="og:type" content="music.album">', false);

        $playlistResponse = $this->get(route('music.playlists.show', $playlist));
        $playlistResponse
            ->assertOk()
            ->assertViewHas('seo', fn (array $seo): bool => $seo['canonical'] === route('music.playlists.show', $playlist) && $seo['type'] === 'music.playlist')
            ->assertViewHas('structured_data', fn (array $data): bool => collect($data['@graph'] ?? [])->contains('@type', 'MusicPlaylist'))
            ->assertSee('<meta property="og:type" content="music.playlist">', false);

        $trackResponse = $this->get(route('music.tracks.show', $first));
        $trackResponse
            ->assertOk()
            ->assertViewHas('seo', fn (array $seo): bool => $seo['canonical'] === route('music.tracks.show', $first) && $seo['type'] === 'music.song')
            ->assertViewHas('structured_data', fn (array $data): bool => collect($data['@graph'] ?? [])->contains('@type', 'MusicRecording'))
            ->assertSee('<meta property="og:type" content="music.song">', false)
            ->assertSee('<meta property="og:audio" content="'.$first->audio_url.'">', false)
            ->assertSee('<meta property="music:album:disc" content="1">', false)
            ->assertSee('<meta property="music:album:track" content="1">', false)
            ->assertSee('AudioObject')
            ->assertDontSee('</script><script>window.musicCompromised = true</script>', false);
    }

    public function test_sitemap_covers_every_current_music_document_and_excludes_future_or_private_records(): void
    {
        Queue::fake();
        $album = Album::query()->create(['title' => 'Sitemap Album', 'published' => false]);
        $albumTrack = $this->track(['title' => 'Sitemap Album Track', 'album_id' => $album->id]);
        $album->update(['published' => true]);
        $standalone = $this->track(['title' => 'Sitemap Single', 'standalone_published' => true]);
        $playlist = Playlist::query()->create(['title' => 'Sitemap Playlist', 'published' => true]);
        $playlist->tracks()->attach($albumTrack, ['position' => 1]);
        $emptyPlaylist = Playlist::query()->create(['title' => 'Sitemap Empty Playlist', 'published' => true]);

        $draftAlbum = Album::query()->create(['title' => 'Private Sitemap Album', 'published' => false]);
        $draftAlbumTrack = $this->track(['title' => 'Private Album Track', 'album_id' => $draftAlbum->id]);
        $futureTrack = $this->track([
            'title' => 'Future Sitemap Single',
            'standalone_published' => true,
            'standalone_published_at' => now()->addDay(),
        ]);
        $futurePlaylist = Playlist::query()->create([
            'title' => 'Future Sitemap Playlist',
            'published' => true,
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee(route('music.index'), false)
            ->assertSee(route('music.albums.show', $album), false)
            ->assertSee(route('music.tracks.show', $albumTrack), false)
            ->assertSee(route('music.tracks.show', $standalone), false)
            ->assertSee(route('music.playlists.show', $playlist), false)
            ->assertSee(route('music.playlists.show', $emptyPlaylist), false)
            ->assertDontSee(route('music.albums.show', $draftAlbum), false)
            ->assertDontSee(route('music.tracks.show', $draftAlbumTrack), false)
            ->assertDontSee(route('music.tracks.show', $futureTrack), false)
            ->assertDontSee(route('music.playlists.show', $futurePlaylist), false);
    }

    public function test_track_recommendations_link_back_to_current_artwork_pages(): void
    {
        Queue::fake();
        $tag = Tag::query()->create(['name' => 'reciprocal']);
        $track = $this->track(['title' => 'Reciprocal Track', 'standalone_published' => true]);
        $track->tags()->attach($tag, ['category' => 'mood']);
        $published = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Reciprocal Artwork',
            'slug' => 'reciprocal-artwork',
            'image_path' => 'artworks/originals/reciprocal.jpg',
            'published' => true,
        ]));
        $scheduled = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Tomorrow Artwork',
            'slug' => 'tomorrow-artwork',
            'image_path' => 'artworks/originals/tomorrow.jpg',
            'published' => true,
            'published_at' => now()->addDay(),
        ]));
        $published->tags()->attach($tag, ['category' => 'mood']);
        $scheduled->tags()->attach($tag, ['category' => 'mood']);

        $this->get(route('music.tracks.show', $track))
            ->assertOk()
            ->assertSee(route('artworks.show', $published), false)
            ->assertDontSee(route('artworks.show', $scheduled), false);
    }

    public function test_private_cover_artwork_is_not_exposed_as_public_music_metadata(): void
    {
        $privateCover = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Private Cover',
            'slug' => 'private-cover',
            'image_path' => 'artworks/originals/private-cover.jpg',
            'published' => false,
        ]));
        $playlist = Playlist::query()->create([
            'cover_artwork_id' => $privateCover->id,
            'title' => 'Public Playlist Private Cover',
            'published' => true,
        ]);

        $this->assertNull($playlist->cover_url);

        $this->get(route('music.playlists.show', $playlist))
            ->assertOk()
            ->assertViewHas('seo', fn (array $seo): bool => ($seo['image'] ?? null) === null)
            ->assertDontSee(route('media.artworks.show', [$privateCover, 'variant' => 'thumb']), false);
    }

    /** @param array<string, mixed> $attributes */
    private function track(array $attributes): Track
    {
        return Track::query()->create(array_replace([
            'title' => 'Track',
            'audio_path' => 'tracks/audio/'.str()->uuid().'.mp3',
            'standalone_published' => false,
        ], $attributes));
    }
}
