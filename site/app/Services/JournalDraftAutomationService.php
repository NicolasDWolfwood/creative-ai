<?php

namespace App\Services;

use App\Data\JournalDraftBatchPlanningResult;
use App\Data\JournalDraftPlanningResult;
use App\Enums\PostMediaType;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\PostTemplate;
use App\Models\Track;
use DomainException;
use Illuminate\Database\Eloquent\Model;

class JournalDraftAutomationService
{
    public function __construct(
        private readonly JournalDraftPlanningService $planning,
        private readonly JournalPlanningSettings $settings,
    ) {}

    /** @param array<string, mixed> $options */
    public function createFor(Model $source, array $options = []): JournalDraftPlanningResult
    {
        $this->assertSupportedAutomaticSource($source);

        return $this->planning->createIfUnconnected(
            source: $source,
            template: $this->template($options),
            copySharedTags: $this->booleanOption($options, 'journal_copy_shared_tags', 'copySharedTags'),
            useSourceArtwork: $this->booleanOption($options, 'journal_use_source_artwork', 'useSourceArtworkAsCover'),
        );
    }

    /**
     * @param  iterable<int, Model>  $sources
     * @param  array<string, mixed>  $options
     */
    public function createBatch(iterable $sources, array $options = []): JournalDraftBatchPlanningResult
    {
        $sources = collect($sources)->each(function (mixed $source): void {
            if (! $source instanceof Model) {
                throw new DomainException('A Journal batch can contain only saved media records.');
            }

            $this->assertSupportedAutomaticSource($source);
        });

        return $this->planning->createBatchIfUnconnected(
            sources: $sources,
            template: $this->template($options),
            copySharedTags: $this->booleanOption($options, 'journal_copy_shared_tags', 'copySharedTags'),
            useSourceArtwork: $this->booleanOption($options, 'journal_use_source_artwork', 'useSourceArtworkAsCover'),
        );
    }

    public function shouldCreateAutomatically(Model $source): bool
    {
        $type = PostMediaType::forModel($source);

        return $type !== null
            && $this->isEligibleSource($source)
            && $this->settings->current()->sourceMode($type)->isAutomatic();
    }

    public function isEligibleSource(Model $source): bool
    {
        if (PostMediaType::forModel($source) === null || ! $source->exists) {
            return false;
        }

        if (($source instanceof Collection || $source instanceof Playlist) && (bool) $source->is_auto_generated) {
            return false;
        }

        if ($this->isCollectionMemberOnlyArtwork($source)) {
            return false;
        }

        return ! ($source instanceof Track
            && $source->album_id !== null
            && ! (bool) $source->standalone_published);
    }

    /** @param array<string, mixed> $options */
    private function template(array $options): ?PostTemplate
    {
        $submitted = $options['journal_post_template_id'] ?? null;

        if (blank($submitted)) {
            return $this->settings->template();
        }

        $id = filter_var($submitted, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $template = $id === false ? null : PostTemplate::query()->find($id);

        if (! $template instanceof PostTemplate) {
            throw new DomainException('The selected Journal template is no longer available.');
        }

        return $template;
    }

    /** @param array<string, mixed> $options */
    private function booleanOption(array $options, string $key, string $defaultProperty): bool
    {
        if (! array_key_exists($key, $options)) {
            return (bool) $this->settings->current()->{$defaultProperty};
        }

        return filter_var($options[$key], FILTER_VALIDATE_BOOL);
    }

    private function assertSupportedAutomaticSource(Model $source): void
    {
        if (PostMediaType::forModel($source) === null || ! $source->exists) {
            throw new DomainException('A saved artwork, collection, album, playlist, or track is required.');
        }

        if (($source instanceof Collection || $source instanceof Playlist) && (bool) $source->is_auto_generated) {
            throw new DomainException('Automatically maintained collections and playlists remain in Story opportunities instead of creating Journal drafts.');
        }

        if ($this->isCollectionMemberOnlyArtwork($source)) {
            throw new DomainException('Collection-only artwork uses the collection Journal story unless it is intentionally published as standalone artwork.');
        }

        if ($source instanceof Track && $source->album_id !== null && ! (bool) $source->standalone_published) {
            throw new DomainException('Album-member tracks use the album Journal story unless they are intentionally released as standalone tracks.');
        }
    }

    private function isCollectionMemberOnlyArtwork(Model $source): bool
    {
        return $source instanceof Artwork
            && ! (bool) $source->published
            && $source->collections()
                ->memberPublicationGrants()
                ->exists();
    }
}
