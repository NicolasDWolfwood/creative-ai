<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Filament\Resources\Albums\Pages\ManageAlbums;
use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Filament\Resources\Collections\Pages\ManageCollections;
use App\Filament\Resources\Playlists\Pages\ManagePlaylists;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\PostTemplates\Pages\CreatePostTemplate;
use App\Filament\Resources\PostTemplates\Pages\EditPostTemplate;
use App\Filament\Resources\PostTemplates\Pages\ListPostTemplates;
use App\Filament\Resources\PostTemplates\PostTemplateResource;
use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection as ArtworkCollection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AdminJournalPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_manage_journal_templates(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $template = PostTemplate::query()->create(['name' => 'Artwork process']);

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, PostTemplate::class));
            $this->assertFalse(Gate::forUser($user)->allows($ability, PostTemplate::class));
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $template));
            $this->assertFalse(Gate::forUser($user)->allows($ability, $template));
        }

        Livewire::actingAs($user)
            ->test(ListPostTemplates::class)
            ->assertForbidden();
    }

    public function test_administrator_creates_updates_and_deletes_a_template_with_default_tags(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = Tag::query()->create(['name' => 'behind the scenes']);
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Public template tag source',
            'slug' => 'public-template-tag-source',
            'image_path' => 'artworks/originals/template-tag-source.jpg',
            'published' => true,
            'published_at' => now()->subMinute(),
        ]));
        $artwork->tags()->attach($tag, ['category' => 'subject']);

        $create = Livewire::actingAs($admin)
            ->test(CreatePostTemplate::class)
            ->assertSee('{{ source_title }}')
            ->assertSee('{{ source_type }}')
            ->fillForm([
                'name' => 'Creative process',
                'title' => 'How {{ source_title }} was made',
                'excerpt' => 'Notes about this {{ source_type }}.',
                'body' => 'Start with the decisions behind {{ source_title }}.',
                'editorial_brief' => 'Explain the process clearly.',
                'is_active' => true,
                'tags' => [$tag->getKey()],
            ]);

        $create->call('create')->assertHasNoFormErrors();

        $template = PostTemplate::query()->where('name', 'Creative process')->firstOrFail();

        $create->assertRedirect(PostTemplateResource::getUrl('edit', ['record' => $template]));
        $this->assertSame([$tag->getKey()], $template->tags()->pluck('tags.id')->all());
        $this->assertTrue($template->is_active);

        Livewire::actingAs($admin)
            ->test(EditPostTemplate::class, ['record' => $template->getKey()])
            ->fillForm([
                'name' => 'Creative process interview',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $template->refresh();
        $this->assertSame('Creative process interview', $template->name);
        $this->assertFalse($template->is_active);

        Livewire::actingAs($admin)
            ->test(EditPostTemplate::class, ['record' => $template->getKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors()
            ->assertRedirect(PostTemplateResource::getUrl());

        $this->assertDatabaseMissing('post_templates', ['id' => $template->getKey()]);
        $this->assertDatabaseHas('tags', ['id' => $tag->getKey()]);
    }

    public function test_create_journal_draft_action_is_available_only_for_public_sources_and_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach ($this->sourceCases() as [$component, $publicSource, $privateSource]) {
            Livewire::actingAs($admin)
                ->test($component)
                ->assertTableActionVisible('createJournalDraft', $publicSource)
                ->assertTableActionHidden('createJournalDraft', $privateSource);

            Livewire::actingAs($user)
                ->test($component)
                ->assertTableActionHidden('createJournalDraft', $publicSource);
        }
    }

    public function test_template_name_uniqueness_uses_the_normalized_name(): void
    {
        PostTemplate::query()->create(['name' => 'Creative process']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreatePostTemplate::class)
            ->fillForm(['name' => '  Creative   process  '])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        $this->assertDatabaseCount('post_templates', 1);
    }

    public function test_create_journal_draft_action_uses_template_and_tags_without_changing_source(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = Tag::query()->create(['name' => 'process']);
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Night Transit',
            'slug' => 'night-transit',
            'image_path' => 'artworks/originals/night-transit.jpg',
            'published' => true,
            'published_at' => now()->subMinute(),
        ]));
        $artwork->tags()->attach($tag, ['category' => 'subject']);
        $template = PostTemplate::query()->create([
            'name' => 'Process notes',
            'title' => '{{ source_type }} notes: {{ source_title }}',
            'body' => 'Write about {{ source_title }}.',
            'editorial_brief' => 'Describe this {{ source_type }} without hype.',
        ]);
        $template->tags()->attach($tag);
        $sourceBefore = $artwork->refresh()->getRawOriginal();

        $component = Livewire::actingAs($admin)
            ->test(ManageArtworks::class)
            ->callTableAction('createJournalDraft', $artwork, [
                'post_template_id' => $template->getKey(),
                'copy_shared_tags' => true,
            ])
            ->assertHasNoActionErrors();

        $post = Post::query()->latest('id')->firstOrFail();

        $component->assertRedirect(PostResource::getUrl('edit', ['record' => $post]));
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertNull($post->published_at);
        $this->assertSame('Artwork notes: Night Transit', $post->title);
        $this->assertSame('Write about Night Transit.', $post->body);
        $this->assertSame('Describe this Artwork without hype.', $post->editorial_brief);
        $this->assertSame([$tag->getKey()], $post->tags()->pluck('tags.id')->all());

        $mediaItem = $post->mediaItems()->firstOrFail();
        $this->assertSame(PostMediaType::Artwork, $mediaItem->type());
        $this->assertSame($artwork->getKey(), $mediaItem->artwork_id);
        $this->assertSame(1, $mediaItem->position);
        $this->assertSame($sourceBefore, $artwork->refresh()->getRawOriginal());
    }

    /**
     * @return list<array{class-string, Model, Model}>
     */
    private function sourceCases(): array
    {
        $publishedAt = now()->subMinute();

        return [
            [
                ManageArtworks::class,
                Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
                    'title' => 'Public artwork',
                    'slug' => 'public-artwork-planning',
                    'image_path' => 'artworks/originals/public-planning.jpg',
                    'published' => true,
                    'published_at' => $publishedAt,
                ])),
                Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
                    'title' => 'Private artwork',
                    'slug' => 'private-artwork-planning',
                    'image_path' => 'artworks/originals/private-planning.jpg',
                    'published' => false,
                ])),
            ],
            [
                ManageCollections::class,
                ArtworkCollection::query()->create([
                    'title' => 'Public collection',
                    'slug' => 'public-collection-planning',
                    'published' => true,
                    'published_at' => $publishedAt,
                ]),
                ArtworkCollection::query()->create([
                    'title' => 'Private collection',
                    'slug' => 'private-collection-planning',
                    'published' => false,
                ]),
            ],
            [
                ManageAlbums::class,
                Album::query()->create([
                    'title' => 'Public album',
                    'slug' => 'public-album-planning',
                    'published' => true,
                    'published_at' => $publishedAt,
                ]),
                Album::query()->create([
                    'title' => 'Private album',
                    'slug' => 'private-album-planning',
                    'published' => false,
                ]),
            ],
            [
                ManagePlaylists::class,
                Playlist::query()->create([
                    'title' => 'Public playlist',
                    'slug' => 'public-playlist-planning',
                    'published' => true,
                    'published_at' => $publishedAt,
                ]),
                Playlist::query()->create([
                    'title' => 'Private playlist',
                    'slug' => 'private-playlist-planning',
                    'published' => false,
                ]),
            ],
            [
                ManageTracks::class,
                Track::withoutEvents(fn (): Track => Track::query()->create([
                    'title' => 'Public track',
                    'slug' => 'public-track-planning',
                    'audio_path' => 'tracks/audio/public-planning.mp3',
                    'standalone_published' => true,
                    'standalone_published_at' => $publishedAt,
                ])),
                Track::withoutEvents(fn (): Track => Track::query()->create([
                    'title' => 'Private track',
                    'slug' => 'private-track-planning',
                    'audio_path' => 'tracks/audio/private-planning.mp3',
                    'standalone_published' => false,
                ])),
            ],
        ];
    }
}
