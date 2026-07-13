<?php

namespace App\Services;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\PostRevision;
use App\Models\User;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class JournalAiApplicationService
{
    public const APPLICATION_VERSION = 'journal-ai-application-v1';

    /** @var array<string, int> */
    private const FIELD_LIMITS = [
        'title' => 255,
        'excerpt' => 500,
        'body' => 40000,
        'cover_alt_text' => 500,
        'seo_title' => 70,
        'seo_description' => 320,
    ];

    /** @var list<string> */
    private const METADATA_FIELDS = [
        'excerpt',
        'cover_alt_text',
        'seo_title',
        'seo_description',
    ];

    public function __construct(
        private readonly JournalAiContextBuilder $contexts,
        private readonly JournalAiContractRegistry $contracts,
        private readonly JournalAiResultNormalizer $normalizer,
        private readonly PostRevisionService $revisions,
    ) {}

    public function canApply(PostAiRun $run): bool
    {
        $run = $run->exists
            ? PostAiRun::query()->find($run->getKey())
            : null;
        $post = $run instanceof PostAiRun
            ? Post::query()->find($run->post_id)
            : null;

        if (
            ! ($run instanceof PostAiRun)
            || $run->status !== PostAiRunStatus::Ready
            || $run->application_manifest !== null
            || ! is_array($run->structured_result)
            || ! in_array($run->operation, [
                PostAiOperation::Outline,
                PostAiOperation::ImprovePassage,
                PostAiOperation::Metadata,
            ], true)
            || ! $post instanceof Post
            || ! $this->hasEditableWorkflowState($post)
        ) {
            return false;
        }

        try {
            $this->assertCurrentContract($run);
            $result = $this->normalizer->normalize($run->operation, $run->structured_result);
            $selection = $run->context_manifest['selection'] ?? null;

            if (! is_array($selection)
                || ($run->operation === PostAiOperation::Metadata
                    && ! $this->hasApplicableMetadataSuggestion($post, $result))) {
                return false;
            }

            $current = $this->contexts->build($post, $run->operation, $selection);
            $sourceRevision = PostRevision::query()
                ->whereBelongsTo($post)
                ->find($run->source_revision_id);

            return $sourceRevision instanceof PostRevision
                && hash_equals((string) $run->source_hash, $current->sourceHash)
                && hash_equals(
                    $this->revisions->revisionContentFingerprint($sourceRevision),
                    $this->revisions->contentFingerprint($post),
                );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply one server-derived batch from a ready result.
     *
     * @param  array<string, mixed>  $selection
     */
    public function apply(PostAiRun $run, User $actor, array $selection): PostAiRun
    {
        $this->authorize($actor, 'apply', $run);

        return DB::transaction(function () use ($run, $actor, $selection): PostAiRun {
            [$post, $lockedRun] = $this->lockPostThenRun($run);
            $this->authorize($actor, 'apply', $lockedRun);
            $this->assertApplyable($post, $lockedRun);
            $contract = $this->assertCurrentContract($lockedRun);
            $result = $this->normalizer->normalize($lockedRun->operation, $lockedRun->structured_result);
            $contextSelection = $lockedRun->context_manifest['selection'] ?? null;

            if (! is_array($contextSelection)) {
                throw new DomainException('The Journal AI result no longer has a valid source selection.');
            }

            $currentContext = $this->contexts->build($post, $lockedRun->operation, $contextSelection);

            if (! hash_equals((string) $lockedRun->source_hash, $currentContext->sourceHash)) {
                throw new DomainException('The Journal post or selected AI context changed. Request a fresh suggestion before applying it.');
            }

            $sourceRevision = $this->sourceRevision($post, $lockedRun);
            $sourceFingerprint = $this->revisions->revisionContentFingerprint($sourceRevision);

            if (! hash_equals($sourceFingerprint, $this->revisions->contentFingerprint($post))) {
                throw new DomainException('The Journal writing changed after this AI request. Request a fresh suggestion before applying it.');
            }

            [$patch, $normalizedSelection, $effect] = $this->derivePatch(
                $post,
                $lockedRun,
                $result,
                $selection,
            );
            $protectedState = $this->protectedState($post);
            $appliedRevision = $this->revisions->applyAiContentPatch(
                $post,
                $patch,
                $actor,
                $sourceFingerprint,
            );
            $post->refresh();
            $this->assertProtectedStateUnchanged($protectedState, $post);
            $appliedFingerprint = $this->revisions->revisionContentFingerprint($appliedRevision);
            $now = now();

            $lockedRun->forceFill([
                'status' => PostAiRunStatus::Applied,
                'application_manifest' => [
                    'version' => self::APPLICATION_VERSION,
                    'operation' => $lockedRun->operation->value,
                    'selection' => $normalizedSelection,
                    'effect' => $effect,
                    'changed_fields' => array_keys($patch),
                    'result_hash' => CanonicalJson::hash($result),
                    'source_revision_id' => (int) $sourceRevision->getKey(),
                    'source_content_fingerprint' => $sourceFingerprint,
                    'applied_revision_id' => (int) $appliedRevision->getKey(),
                    'applied_content_fingerprint' => $appliedFingerprint,
                    'prompt_version' => $contract->promptVersion,
                    'schema_version' => $contract->schemaVersion,
                ],
                'applied_by_user_id' => $actor->getKey(),
                'applied_revision_id' => $appliedRevision->getKey(),
                'applied_at' => $now,
            ])->saveOrFail();

            $this->staleSiblingResults($post, $lockedRun);

            return $lockedRun->refresh();
        }, 3);
    }

    public function canUndo(PostAiRun $run): bool
    {
        $run = $run->exists
            ? PostAiRun::query()->find($run->getKey())
            : null;
        $post = $run instanceof PostAiRun
            ? Post::query()->find($run->post_id)
            : null;

        if (
            ! ($run instanceof PostAiRun)
            || $run->status !== PostAiRunStatus::Applied
            || ! is_array($run->application_manifest)
            || $run->source_revision_id === null
            || $run->applied_revision_id === null
            || ! $post instanceof Post
            || ! $this->hasEditableWorkflowState($post)
        ) {
            return false;
        }

        try {
            $applied = PostRevision::query()
                ->whereBelongsTo($post)
                ->find($run->applied_revision_id);

            return $applied instanceof PostRevision
                && ! $this->hasNewerContentRevision($post, $run)
                && hash_equals(
                    $this->revisions->revisionContentFingerprint($applied),
                    $this->revisions->contentFingerprint($post),
                );
        } catch (\Throwable) {
            return false;
        }
    }

    public function undo(PostAiRun $run, User $actor): Post
    {
        $this->authorize($actor, 'undo', $run);

        return DB::transaction(function () use ($run, $actor): Post {
            [$post, $lockedRun] = $this->lockPostThenRun($run);
            $this->authorize($actor, 'undo', $lockedRun);

            if (
                $lockedRun->status !== PostAiRunStatus::Applied
                || ! is_array($lockedRun->application_manifest)
                || ! $this->hasEditableWorkflowState($post)
            ) {
                throw new DomainException('Only an unchanged AI application on a Draft or Ready post can be undone.');
            }

            $sourceRevision = $this->sourceRevision($post, $lockedRun);
            $appliedRevision = PostRevision::query()
                ->whereBelongsTo($post)
                ->lockForUpdate()
                ->find($lockedRun->applied_revision_id);

            if (! $appliedRevision instanceof PostRevision) {
                throw new DomainException('The applied Journal revision is no longer available.');
            }

            if ($this->hasNewerContentRevision($post, $lockedRun)) {
                throw new DomainException('A newer Journal content revision exists. Use revision history instead of undoing this AI application.');
            }

            $appliedFingerprint = $this->revisions->revisionContentFingerprint($appliedRevision);

            if (! hash_equals($appliedFingerprint, $this->revisions->contentFingerprint($post))) {
                throw new DomainException('The Journal writing changed after this AI application. Use revision history to review it safely.');
            }

            $protectedState = $this->protectedState($post);
            $restored = $this->revisions->restore(
                $post,
                $sourceRevision,
                actor: $actor,
                reason: 'Undid reviewed Journal AI suggestions.',
                expectedContentFingerprint: $appliedFingerprint,
            );
            $this->assertProtectedStateUnchanged($protectedState, $restored);

            return $restored;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $selection
     * @return array{array<string, ?string>, array<string, mixed>, array<string, mixed>}
     */
    private function derivePatch(Post $post, PostAiRun $run, array $result, array $selection): array
    {
        return match ($run->operation) {
            PostAiOperation::Outline => $this->outlinePatch($post, $result, $selection),
            PostAiOperation::ImprovePassage => $this->passagePatch($post, $run, $result, $selection),
            PostAiOperation::Metadata => $this->metadataPatch($post, $result, $selection),
            default => throw new DomainException('This Journal AI result is feedback only and cannot be applied.'),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $selection
     * @return array{array<string, ?string>, array<string, mixed>, array<string, mixed>}
     */
    private function outlinePatch(Post $post, array $result, array $selection): array
    {
        if (array_diff(array_keys($selection), ['mode']) !== [] || ! array_key_exists('mode', $selection)) {
            throw new DomainException('Applying an outline requires only a prepend or append mode.');
        }

        $mode = $selection['mode'] ?? null;

        if (! is_string($mode) || ! in_array($mode, ['prepend', 'append'], true)) {
            throw new DomainException('The Journal outline mode is invalid.');
        }

        $outline = $this->renderOutline($result);
        $body = $post->body;

        if (! is_string($body)) {
            throw new DomainException('The saved Journal body is invalid.');
        }

        $updated = match ($mode) {
            'prepend' => $body === '' ? $outline : $outline."\n\n".$body,
            'append' => $body === '' ? $outline : $body."\n\n".$outline,
        };
        $this->assertFieldValue('body', $updated);

        return [
            ['body' => $updated],
            ['mode' => $mode],
            ['type' => 'outline', 'field' => 'body', 'mode' => $mode],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $selection
     * @return array{array<string, ?string>, array<string, mixed>, array<string, mixed>}
     */
    private function passagePatch(Post $post, PostAiRun $run, array $result, array $selection): array
    {
        if ($selection !== []) {
            throw new DomainException('Passage replacement offsets are fixed by the acknowledged AI request.');
        }

        $passage = $run->context_manifest['outbound']['selected_passage'] ?? null;

        if (
            ! is_array($passage)
            || array_diff(array_keys($passage), ['field', 'start', 'end', 'content']) !== []
            || array_diff(['field', 'start', 'end', 'content'], array_keys($passage)) !== []
            || ! is_string($passage['field'])
            || ! in_array($passage['field'], ['title', 'excerpt', 'body'], true)
            || ! is_int($passage['start'])
            || ! is_int($passage['end'])
            || ! is_string($passage['content'])
        ) {
            throw new DomainException('The acknowledged Journal passage is invalid.');
        }

        $field = $passage['field'];
        $current = $post->getAttribute($field);

        if (! is_string($current) || str_replace(["\r\n", "\r"], "\n", $current) !== $current) {
            throw new DomainException('The saved Journal passage no longer has canonical line endings.');
        }

        $length = mb_strlen($current, 'UTF-8');

        if ($passage['start'] < 0 || $passage['end'] <= $passage['start'] || $passage['end'] > $length) {
            throw new DomainException('The acknowledged Journal passage offsets are invalid.');
        }

        $selected = mb_substr(
            $current,
            $passage['start'],
            $passage['end'] - $passage['start'],
            'UTF-8',
        );

        if (! hash_equals($passage['content'], $selected)) {
            throw new DomainException('The selected Journal passage changed. Request a fresh suggestion before applying it.');
        }

        $replacement = $result['replacement_markdown'] ?? null;

        if (! is_string($replacement)) {
            throw new DomainException('The Journal AI passage replacement is invalid.');
        }

        $updated = mb_substr($current, 0, $passage['start'], 'UTF-8')
            .$replacement
            .mb_substr($current, $passage['end'], null, 'UTF-8');
        $this->assertFieldValue($field, $updated);

        if ($updated === $current) {
            throw new DomainException('The Journal AI passage replacement does not change the saved post.');
        }

        return [
            [$field => $updated],
            [],
            [
                'type' => 'passage_replacement',
                'field' => $field,
                'start' => $passage['start'],
                'end' => $passage['end'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $selection
     * @return array{array<string, ?string>, array<string, mixed>, array<string, mixed>}
     */
    private function metadataPatch(Post $post, array $result, array $selection): array
    {
        if (array_diff(array_keys($selection), ['fields']) !== [] || ! array_key_exists('fields', $selection)) {
            throw new DomainException('Applying metadata requires only an explicit field list.');
        }

        $submitted = $selection['fields'] ?? null;

        if (! is_array($submitted) || ! array_is_list($submitted) || $submitted === []) {
            throw new DomainException('Select at least one Journal metadata field to apply.');
        }

        foreach ($submitted as $field) {
            if (! is_string($field) || ! in_array($field, self::METADATA_FIELDS, true)) {
                throw new DomainException('The selected Journal metadata field is not supported.');
            }
        }

        if (count(array_unique($submitted)) !== count($submitted)) {
            throw new DomainException('Each Journal metadata field can be selected only once.');
        }

        $fields = array_values(array_filter(
            self::METADATA_FIELDS,
            fn (string $field): bool => in_array($field, $submitted, true),
        ));
        $patch = [];

        foreach ($fields as $field) {
            $value = $result[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new DomainException("The Journal AI result has no {$field} suggestion to apply.");
            }

            $this->assertFieldValue($field, $value);

            if ($post->getAttribute($field) === $value) {
                throw new DomainException("The {$field} suggestion already matches the saved post.");
            }

            $patch[$field] = $value;
        }

        return [
            $patch,
            ['fields' => $fields],
            ['type' => 'metadata', 'fields' => $fields],
        ];
    }

    /** @param array<string, mixed> $result */
    private function renderOutline(array $result): string
    {
        $workingTitle = trim((string) ($result['working_title'] ?? ''));
        $thesis = trim((string) ($result['thesis'] ?? ''));
        $sections = $result['sections'] ?? null;

        if ($workingTitle === '' || $thesis === '' || ! is_array($sections) || ! array_is_list($sections)) {
            throw new DomainException('The Journal AI outline is invalid.');
        }

        $blocks = ['# '.$workingTitle, $thesis];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                throw new DomainException('The Journal AI outline is invalid.');
            }

            $heading = trim((string) ($section['heading'] ?? ''));
            $purpose = trim((string) ($section['purpose'] ?? ''));
            $keyPoints = $section['key_points'] ?? null;

            if ($heading === '' || $purpose === '' || ! is_array($keyPoints) || ! array_is_list($keyPoints)) {
                throw new DomainException('The Journal AI outline is invalid.');
            }

            $block = "## {$heading}\n\n{$purpose}";

            if ($keyPoints !== []) {
                $block .= "\n\n".implode("\n", array_map(
                    fn (mixed $point): string => '- '.trim((string) $point),
                    $keyPoints,
                ));
            }

            $blocks[] = $block;
        }

        return implode("\n\n", $blocks);
    }

    private function assertFieldValue(string $field, ?string $value): void
    {
        if (! array_key_exists($field, self::FIELD_LIMITS)
            || ($value === null && in_array($field, ['title', 'body'], true))
            || ($value !== null && ! mb_check_encoding($value, 'UTF-8'))) {
            throw new DomainException('The Journal AI suggestion cannot be stored in the selected field.');
        }

        if ($value !== null && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            throw new DomainException('The Journal AI suggestion contains unsupported control characters.');
        }

        if ($value !== null && mb_strlen($value, 'UTF-8') > self::FIELD_LIMITS[$field]) {
            throw new DomainException("The Journal AI {$field} suggestion is too long to apply.");
        }
    }

    private function assertApplyable(Post $post, PostAiRun $run): void
    {
        if (
            $run->status !== PostAiRunStatus::Ready
            || $run->application_manifest !== null
            || ! is_array($run->structured_result)
            || ! in_array($run->operation, [
                PostAiOperation::Outline,
                PostAiOperation::ImprovePassage,
                PostAiOperation::Metadata,
            ], true)
            || ! $this->hasEditableWorkflowState($post)
        ) {
            throw new DomainException('Only a ready Journal AI writing result on a Draft or Ready post can be applied.');
        }
    }

    private function assertCurrentContract(PostAiRun $run): JournalAiContract
    {
        $contract = $this->contracts->for($run->operation);

        if (
            $run->prompt_version !== $contract->promptVersion
            || ! hash_equals((string) $run->prompt_hash, $contract->promptHash())
            || $run->schema_version !== $contract->schemaVersion
            || ! hash_equals((string) $run->schema_hash, $contract->schemaHash())
        ) {
            throw new DomainException('The Journal AI result uses an older contract. Regenerate it before applying.');
        }

        return $contract;
    }

    private function sourceRevision(Post $post, PostAiRun $run): PostRevision
    {
        $revision = PostRevision::query()
            ->whereBelongsTo($post)
            ->lockForUpdate()
            ->find($run->source_revision_id);

        if (! $revision instanceof PostRevision) {
            throw new DomainException('The Journal AI source revision is no longer available.');
        }

        return $revision;
    }

    private function hasEditableWorkflowState(Post $post): bool
    {
        return ! $post->trashed()
            && in_array($post->status, [PostStatus::Draft, PostStatus::Ready], true)
            && ! $post->published
            && $post->scheduled_at === null;
    }

    /** @param array<string, mixed> $result */
    private function hasApplicableMetadataSuggestion(Post $post, array $result): bool
    {
        foreach (self::METADATA_FIELDS as $field) {
            $value = $result[$field] ?? null;

            if (is_string($value)
                && trim($value) !== ''
                && mb_strlen($value, 'UTF-8') <= self::FIELD_LIMITS[$field]
                && $post->getAttribute($field) !== $value) {
                return true;
            }
        }

        return false;
    }

    private function hasNewerContentRevision(Post $post, PostAiRun $run): bool
    {
        if ($run->applied_revision_id === null) {
            return true;
        }

        return PostRevision::query()
            ->whereBelongsTo($post)
            ->where('id', '>', $run->applied_revision_id)
            ->where(function ($query): void {
                foreach (Post::REVISION_CONTENT_FIELDS as $field) {
                    $query->orWhereJsonContains('changed_fields', 'content.'.$field);
                }
            })
            ->exists();
    }

    /** @return array<string, mixed> */
    private function protectedState(Post $post): array
    {
        return [
            'slug' => $post->getRawOriginal('slug'),
            'status' => $post->getRawOriginal('status'),
            'scheduled_at' => $post->getRawOriginal('scheduled_at'),
            'published' => $post->getRawOriginal('published'),
            'published_at' => $post->getRawOriginal('published_at'),
            'featured' => $post->getRawOriginal('featured'),
            'editorial_brief' => $post->getRawOriginal('editorial_brief'),
            'editorial_notes' => $post->getRawOriginal('editorial_notes'),
            'cover_image_path' => $post->getRawOriginal('cover_image_path'),
            'tag_ids' => $post->tags()
                ->reorder('tags.id')
                ->pluck('tags.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'media_ids' => $post->mediaItems()
                ->reorder('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
        ];
    }

    /** @param array<string, mixed> $expected */
    private function assertProtectedStateUnchanged(array $expected, Post $post): void
    {
        if ($expected !== $this->protectedState($post)) {
            throw new DomainException('The Journal AI application attempted to change protected post state.');
        }
    }

    private function staleSiblingResults(Post $post, PostAiRun $applied): void
    {
        $siblings = PostAiRun::query()
            ->whereBelongsTo($post)
            ->whereKeyNot($applied->getKey())
            ->where('status', PostAiRunStatus::Ready->value)
            ->lockForUpdate()
            ->get();

        foreach ($siblings as $sibling) {
            $sibling->forceFill([
                'status' => PostAiRunStatus::Stale,
                'error_category' => 'source_changed',
                'error_message' => 'Another reviewed Journal AI result changed the saved writing.',
                'stale_reason' => 'sibling_result_applied',
                'completed_at' => $sibling->completed_at ?: now(),
            ])->saveOrFail();
        }
    }

    private function authorize(User $actor, string $ability, PostAiRun $run): void
    {
        Gate::forUser($actor)->authorize($ability, $run);
    }

    /** @return array{Post, PostAiRun} */
    private function lockPostThenRun(PostAiRun $run): array
    {
        $postId = PostAiRun::query()->whereKey($run->getKey())->value('post_id');
        $post = Post::query()->lockForUpdate()->findOrFail($postId);
        $lockedRun = PostAiRun::query()->lockForUpdate()->findOrFail($run->getKey());

        if ((int) $lockedRun->post_id !== (int) $post->getKey()) {
            throw new DomainException('The Journal AI run source changed unexpectedly.');
        }

        $lockedRun->setRelation('post', $post);

        return [$post, $lockedRun];
    }
}
