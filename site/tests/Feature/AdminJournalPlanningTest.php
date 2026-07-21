<?php

namespace Tests\Feature;

use App\Enums\JournalPlanningMode;
use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Filament\Pages\StoryOpportunities;
use App\Filament\Resources\Albums\Pages\ManageAlbums;
use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Filament\Resources\Collections\Pages\ManageCollections;
use App\Filament\Resources\Playlists\Pages\ManagePlaylists;
use App\Filament\Resources\Posts\Pages\ListPosts;
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
use App\Services\AlbumImportService;
use App\Services\ArtworkBulkUploadService;
use App\Services\JournalDraftAutomationService;
use App\Services\JournalPlanningSettings;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminJournalPlanningTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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

    public function test_create_journal_draft_action_is_available_for_saved_sources_only_to_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach ($this->sourceCases() as [$component, $publicSource, $privateSource]) {
            Livewire::actingAs($admin)
                ->test($component)
                ->assertTableActionVisible('createJournalDraft', $publicSource)
                ->assertTableActionVisible('createJournalDraft', $privateSource);

            Livewire::actingAs($user)
                ->test($component)
                ->assertTableActionHidden('createJournalDraft', $publicSource)
                ->assertTableActionHidden('createJournalDraft', $privateSource);
        }
    }

    public function test_journal_list_exposes_blank_source_led_and_template_entry_points(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListPosts::class)
            ->assertActionVisible('create')
            ->assertActionVisible('createFromContent')
            ->assertActionHasUrl('createFromContent', StoryOpportunities::getUrl())
            ->assertActionVisible('manageTemplates')
            ->assertActionHasUrl('manageTemplates', PostTemplateResource::getUrl());
    }

    public function test_single_source_create_flow_can_optionally_create_one_private_linked_draft(): void
    {
        app(JournalPlanningSettings::class)->save([
            'collection_mode' => JournalPlanningMode::Ask->value,
        ]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(ManageCollections::class)
            ->callAction('create', [
                'title' => 'New planning collection',
                'published' => false,
                'journal_create_draft' => true,
                'journal_copy_shared_tags' => false,
                'journal_use_source_artwork' => false,
            ])
            ->assertHasNoActionErrors();

        $collection = ArtworkCollection::query()->where('title', 'New planning collection')->firstOrFail();
        $post = Post::query()->sole();

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertSame($collection->getKey(), $post->mediaItems()->sole()->collection_id);
        $this->assertDatabaseCount('post_ai_runs', 0);
    }

    public function test_automatic_source_default_still_creates_only_a_private_draft_and_can_be_overridden(): void
    {
        app(JournalPlanningSettings::class)->save([
            'collection_mode' => JournalPlanningMode::Automatic->value,
        ]);
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageCollections::class);

        $component
            ->callAction('create', [
                'title' => 'Automatic planning collection',
                'published' => true,
                'journal_use_source_artwork' => false,
            ])
            ->assertHasNoActionErrors();

        $post = Post::query()->sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertDatabaseCount('post_ai_runs', 0);

        $component
            ->callAction('create', [
                'title' => 'Opted-out planning collection',
                'published' => true,
                'journal_create_draft' => false,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('collections', 2);
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_optional_journal_failure_never_rolls_back_the_new_source(): void
    {
        app(JournalPlanningSettings::class)->save([
            'collection_mode' => JournalPlanningMode::Ask->value,
        ]);
        $automation = \Mockery::mock(JournalDraftAutomationService::class);
        $automation->shouldReceive('isEligibleSource')
            ->once()
            ->andReturnTrue();
        $automation->shouldReceive('createFor')
            ->once()
            ->andThrow(new DomainException('Simulated Journal planning failure.'));
        $this->app->instance(JournalDraftAutomationService::class, $automation);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageCollections::class)
            ->callAction('create', [
                'title' => 'Collection survives Journal failure',
                'published' => false,
                'journal_create_draft' => true,
                'journal_use_source_artwork' => false,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('collections', ['title' => 'Collection survives Journal failure']);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_default_off_and_ask_without_opt_in_create_no_journal_drafts(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(ManageCollections::class)
            ->callAction('create', [
                'title' => 'Default off collection',
                'published' => false,
            ])
            ->assertHasNoActionErrors();

        app(JournalPlanningSettings::class)->save([
            'collection_mode' => JournalPlanningMode::Ask->value,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageCollections::class)
            ->callAction('create', [
                'title' => 'Ask but not requested collection',
                'published' => false,
                'journal_create_draft' => false,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('collections', 2);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_album_publish_action_can_create_one_private_album_story(): void
    {
        app(JournalPlanningSettings::class)->save([
            'album_mode' => JournalPlanningMode::Ask->value,
        ]);
        $album = Album::query()->create([
            'title' => 'Album ready to publish',
            'published' => false,
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageAlbums::class)
            ->callTableAction('publishAlbum', $album, [
                'journal_create_draft' => true,
                'journal_copy_shared_tags' => false,
                'journal_use_source_artwork' => false,
            ])
            ->assertHasNoActionErrors();

        $post = Post::query()->sole();

        $this->assertTrue($album->fresh()->published);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertSame($album->getKey(), $post->mediaItems()->sole()->album_id);
    }

    public function test_artwork_bulk_action_creates_one_private_story_for_the_selected_batch(): void
    {
        $first = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'First selected artwork',
            'slug' => 'first-selected-artwork',
            'image_path' => 'artworks/originals/first-selected.png',
            'published' => false,
        ]));
        $second = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Second selected artwork',
            'slug' => 'second-selected-artwork',
            'image_path' => 'artworks/originals/second-selected.png',
            'published' => false,
        ]));

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->callTableBulkAction('createJournalBatch', [$first, $second], [
                'journal_copy_shared_tags' => false,
                'journal_use_source_artwork' => false,
            ])
            ->assertHasNoActionErrors();

        $post = Post::query()->sole();

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $second->getKey()],
            $post->mediaItems()->pluck('artwork_id')->all(),
        );
        $this->assertSame([1, 2], $post->mediaItems()->orderBy('position')->pluck('position')->all());
    }

    public function test_artwork_upload_workflow_groups_the_created_batch_into_one_story(): void
    {
        app(JournalPlanningSettings::class)->save([
            'artwork_batch_mode' => JournalPlanningMode::Ask->value,
        ]);
        $first = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'First uploaded artwork',
            'slug' => 'first-uploaded-artwork',
            'image_path' => 'artworks/originals/uploaded-one.png',
            'published' => false,
        ]));
        $second = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Second uploaded artwork',
            'slug' => 'second-uploaded-artwork',
            'image_path' => 'artworks/originals/uploaded-two.png',
            'published' => false,
        ]));
        $uploads = \Mockery::mock(ArtworkBulkUploadService::class);
        $uploads->shouldReceive('create')->once()->andReturn(new Collection([
            $first,
            $second,
        ]));
        $this->app->instance(ArtworkBulkUploadService::class, $uploads);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->callAction('bulkUpload', [
                'images' => ['artworks/originals/uploaded-one.png', 'artworks/originals/uploaded-two.png'],
                'original_names' => [
                    'artworks/originals/uploaded-one.png' => 'uploaded-one.png',
                    'artworks/originals/uploaded-two.png' => 'uploaded-two.png',
                ],
                'published' => false,
                'analyze_after_upload' => false,
                'journal_create_draft' => true,
                'journal_copy_shared_tags' => false,
                'journal_use_source_artwork' => false,
            ])
            ->assertHasNoActionErrors();

        $post = Post::query()->sole();

        $this->assertSame(2, $post->mediaItems()->count());
        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $second->getKey()],
            $post->mediaItems()->pluck('artwork_id')->all(),
        );
    }

    public function test_album_import_workflow_creates_one_story_per_distinct_album_and_none_for_member_tracks(): void
    {
        app(JournalPlanningSettings::class)->save([
            'album_import_mode' => JournalPlanningMode::Ask->value,
        ]);
        $firstAlbum = Album::query()->create(['title' => 'First imported album']);
        $secondAlbum = Album::query()->create(['title' => 'Second imported album']);
        $firstTrack = Track::withoutEvents(fn (): Track => Track::query()->create([
            'album_id' => $firstAlbum->getKey(),
            'title' => 'First album track',
            'slug' => 'first-album-track',
            'audio_path' => 'tracks/audio/first-album-track.mp3',
            'standalone_published' => false,
        ]));
        $secondTrack = Track::withoutEvents(fn (): Track => Track::query()->create([
            'album_id' => $firstAlbum->getKey(),
            'title' => 'Second album track',
            'slug' => 'second-album-track',
            'audio_path' => 'tracks/audio/second-album-track.mp3',
            'standalone_published' => false,
        ]));
        $thirdTrack = Track::withoutEvents(fn (): Track => Track::query()->create([
            'album_id' => $secondAlbum->getKey(),
            'title' => 'Other album track',
            'slug' => 'other-album-track',
            'audio_path' => 'tracks/audio/other-album-track.mp3',
            'standalone_published' => false,
        ]));
        $imports = \Mockery::mock(AlbumImportService::class);
        $imports->shouldReceive('import')->once()->andReturn(collect([
            $firstTrack,
            $secondTrack,
            $thirdTrack,
        ]));
        $this->app->instance(AlbumImportService::class, $imports);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageTracks::class)
            ->callAction('importAlbum', [
                'audio_files' => ['tracks/audio/import-placeholder.mp3'],
                'original_names' => [
                    'tracks/audio/import-placeholder.mp3' => 'import-placeholder.mp3',
                ],
                'standalone_published' => false,
                'journal_create_draft' => true,
                'journal_copy_shared_tags' => false,
                'journal_use_source_artwork' => false,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('posts', 2);
        $this->assertSame(
            [$firstAlbum->getKey(), $secondAlbum->getKey()],
            Post::query()
                ->with('mediaItems')
                ->get()
                ->flatMap(fn (Post $post) => $post->mediaItems->pluck('album_id'))
                ->filter()
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame(0, Post::query()
            ->with('mediaItems')
            ->get()
            ->flatMap(fn (Post $post) => $post->mediaItems->pluck('track_id'))
            ->filter()
            ->count());
    }

    public function test_automatic_track_planning_silently_skips_an_album_member_source(): void
    {
        Queue::fake();
        Storage::fake('local');
        app(JournalPlanningSettings::class)->save([
            'track_mode' => JournalPlanningMode::Automatic->value,
        ]);
        $album = Album::query()->create(['title' => 'Parent album']);

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageTracks::class)
            ->callAction('create', [
                'title' => 'New album member',
                'album_id' => $album->getKey(),
                'audio_path' => UploadedFile::fake()->create('album-member.mp3', 1, 'audio/mpeg'),
                'standalone_published' => false,
                // Simulate a previously hydrated automatic toggle that became
                // hidden after choosing an album.
                'journal_create_draft' => true,
            ]);

        $component->assertNotNotified('Source saved; Journal draft needs attention');

        $this->assertDatabaseHas('tracks', [
            'title' => 'New album member',
            'album_id' => $album->getKey(),
        ]);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_public_source_action_defaults_to_a_stable_private_cover_snapshot(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $bytes = base64_decode(self::PNG, true);
        Storage::disk('local')->put('artworks/display/default-cover.png', $bytes);
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Default cover source',
            'slug' => 'default-cover-source',
            'image_path' => 'artworks/originals/default-cover.png',
            'display_path' => 'artworks/display/default-cover.png',
            'alt_text' => 'A cover selected by default.',
            'published' => true,
            'published_at' => now(),
        ]));

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->callTableAction('createJournalDraft', $artwork)
            ->assertHasNoActionErrors();

        $post = Post::query()->sole();

        $this->assertNotNull($post->cover_image_path);
        $this->assertNotSame($artwork->display_path, $post->cover_image_path);
        $this->assertSame('A cover selected by default.', $post->cover_alt_text);
        $this->assertSame($bytes, Storage::disk('local')->get($post->cover_image_path));
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
