<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ManagePostConnections;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection as ArtworkCollection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPostConnectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_open_and_mutate_post_connections(): void
    {
        $post = $this->createPost();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('manageConnections', $post));
        $this->assertFalse(Gate::forUser($user)->allows('manageConnections', $post));

        Livewire::actingAs($user)
            ->test(ManagePostConnections::class, ['record' => $post->getKey()])
            ->assertForbidden();

        Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->assertSee('Connections')
            ->assertSee(PostResource::getUrl('connections', ['record' => $post]), escape: false);
    }

    public function test_administrator_edits_shared_tags_and_ordered_media_through_the_connections_page(): void
    {
        $post = $this->createPost();
        $tag = Tag::query()->create(['name' => 'behind the scenes']);
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Public artwork',
            'slug' => 'public-artwork',
            'image_path' => 'artworks/originals/public.jpg',
            'published' => true,
            'published_at' => now()->subMinute(),
        ]));
        $collection = ArtworkCollection::query()->create(['title' => 'Draft collection']);
        $album = Album::query()->create(['title' => 'Draft album']);
        $playlist = Playlist::query()->create(['title' => 'Draft playlist']);
        $track = Track::withoutEvents(fn (): Track => Track::query()->create([
            'title' => 'Draft track',
            'slug' => 'draft-track',
            'audio_path' => 'tracks/audio/draft.mp3',
        ]));
        $originalPublicContentUpdate = $post->public_content_updated_at;
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManagePostConnections::class, ['record' => $post->getKey()])
            ->assertActionVisible('editTags')
            ->assertActionVisible('addMedia')
            ->assertSee('Unlinking an item removes only this connection; it never deletes the source');

        $this->travel(1)->minute();
        $component
            ->callAction('editTags', ['tag_ids' => [$tag->getKey()]])
            ->assertHasNoActionErrors();

        $post->refresh();
        $this->assertSame([$tag->getKey()], $post->tags()->pluck('tags.id')->all());
        $this->assertTrue($post->public_content_updated_at->gt($originalPublicContentUpdate));

        foreach ([
            [PostMediaType::Artwork, $artwork],
            [PostMediaType::Collection, $collection],
            [PostMediaType::Album, $album],
            [PostMediaType::Playlist, $playlist],
            [PostMediaType::Track, $track],
        ] as [$type, $media]) {
            $component
                ->callAction('addMedia', ['type' => $type->value, 'media_id' => $media->getKey()])
                ->assertHasNoActionErrors();
        }

        $items = $post->mediaItems()->get();

        $this->assertSame(range(1, 5), $items->pluck('position')->all());
        $this->assertSame(
            collect(PostMediaType::cases())->pluck('value')->all(),
            $items->map(fn ($item): ?string => $item->type()?->value)->all(),
        );
        $component
            ->assertTableColumnStateSet('visibility', 'Public', $items->first())
            ->assertTableColumnStateSet('visibility', 'Draft / private', $items->last());

        $reverseOrder = $items->reverse()->pluck('id')->all();
        $component->call('reorderTable', $reverseOrder);

        $this->assertSame(
            array_reverse(collect(PostMediaType::cases())->pluck('value')->all()),
            $post->mediaItems()->get()->map(fn ($item): ?string => $item->type()?->value)->all(),
        );

        $linkToRemove = $post->mediaItems()->where('artwork_id', $artwork->getKey())->firstOrFail();
        $component
            ->callTableAction('unlink', $linkToRemove)
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('post_media', [
            'post_id' => $post->getKey(),
            'artwork_id' => $artwork->getKey(),
        ]);
        $this->assertDatabaseHas('artworks', ['id' => $artwork->getKey(), 'title' => 'Public artwork']);
    }

    private function createPost(): Post
    {
        return Post::query()->create([
            'title' => 'Connected Journal story',
            'body' => 'A story with ordered media.',
        ]);
    }
}
