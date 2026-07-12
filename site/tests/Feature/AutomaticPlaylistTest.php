<?php

namespace Tests\Feature;

use App\Filament\Resources\Albums\Pages\ManageAlbums;
use App\Filament\Resources\Playlists\Pages\ManagePlaylists;
use App\Models\Playlist;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\AutomaticPlaylistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AutomaticPlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_playlists_are_managed_from_recurring_music_tags_and_preserve_custom_playlists(): void
    {
        Queue::fake();
        $manual = Playlist::create(['title' => 'My handpicked mix', 'published' => true]);
        foreach (['ambient', 'calm', 'energetic', 'dark', 'electronic', 'epic'] as $tag) {
            $this->taggedTrack($tag);
            $this->taggedTrack($tag);
        }

        $result = app(AutomaticPlaylistService::class)->maintain(target: 4, minimumTracks: 2);

        $this->assertSame(4, $result['playlist_count']);
        $this->assertSame(4, Playlist::query()->where('is_auto_generated', true)->count());
        $this->assertTrue(Playlist::query()->whereKey($manual->id)->exists());
        Playlist::query()->where('is_auto_generated', true)->each(function (Playlist $playlist): void {
            $this->assertTrue($playlist->is_smart);
            $this->assertTrue($playlist->auto_sync);
            $this->assertGreaterThanOrEqual(2, $playlist->tracks()->count());
            $this->assertSame('tag_frequency', data_get($playlist->smart_rules, 'source'));
        });

        app(AutomaticPlaylistService::class)->maintain(target: 1, minimumTracks: 2);
        $this->assertSame(1, Playlist::query()->where('is_auto_generated', true)->count());
        $this->assertTrue(Playlist::query()->whereKey($manual->id)->exists());
    }

    public function test_ai_can_create_a_custom_smart_playlist_from_existing_track_tags(): void
    {
        Queue::fake();
        app(AiSettings::class)->save([
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama.test:11434',
            'ollama_model' => 'qwen-test:latest',
        ]);
        $this->taggedTrack('ambient');
        $this->taggedTrack('ambient');
        $this->taggedTrack('calm');

        Http::fake(['ollama.test:11434/api/chat' => Http::response([
            'message' => ['content' => json_encode([
                'title' => 'Quiet Horizons',
                'description' => 'Ambient and calm music for focused listening.',
                'tag_slugs' => ['ambient', 'calm'],
                'explanation' => 'Combines the recurring quiet-listening tags.',
            ])],
        ])]);

        $result = app(AutomaticPlaylistService::class)->createWithAi('quiet focus', minimumTracks: 2);

        $this->assertSame('Quiet Horizons', $result['playlist']->title);
        $this->assertTrue($result['playlist']->is_smart);
        $this->assertFalse($result['playlist']->is_auto_generated);
        $this->assertSame('ai_assisted', data_get($result['playlist']->smart_rules, 'source'));
        $this->assertSame(3, $result['count']);
    }

    public function test_playlist_and_album_pages_expose_the_quick_organization_workflows(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManagePlaylists::class)->assertSee('Generate automatic')->assertSee('Create with AI')->assertSee('New playlist');
        Livewire::test(ManageAlbums::class)->assertSee('Organize from metadata')->assertSee('New album');
    }

    protected function taggedTrack(string $tagName): Track
    {
        static $sequence = 0;
        $sequence++;
        $tag = Tag::firstOrCreate(['slug' => str($tagName)->slug()->toString()], ['name' => $tagName]);
        $track = Track::create(['title' => 'Track '.$sequence, 'slug' => 'auto-track-'.$sequence, 'audio_path' => 'tracks/'.$sequence.'.mp3', 'published' => true]);
        $track->tags()->attach($tag, ['category' => 'mood']);

        return $track;
    }
}
