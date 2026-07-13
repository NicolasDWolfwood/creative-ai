<?php

namespace App\Services;

use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostRevision;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

class PostRevisionService
{
    private static int $automaticCaptureSuppressionDepth = 0;

    public function __construct(
        private readonly PostConnectionService $connections,
        private readonly PostSlugRedirectService $slugRedirects,
        private readonly PrivateMediaService $media,
        private readonly PostReadiness $readiness,
    ) {}

    public static function automaticCaptureIsSuppressed(): bool
    {
        return self::$automaticCaptureSuppressionDepth > 0;
    }

    public function capture(
        Post $post,
        string $provenance = 'content_edit',
        ?User $actor = null,
        ?string $reason = null,
    ): PostRevision {
        return $this->captureRevision($post, $provenance, $actor, $reason);
    }

    private function captureRevision(
        Post $post,
        string $provenance,
        ?User $actor,
        ?string $reason,
    ): PostRevision {
        if (! $post->exists) {
            throw new LogicException('Journal revisions require a persisted post.');
        }

        $provenance = $this->normalizeProvenance($provenance);
        $reason = $this->normalizeReason($reason, $provenance);

        return DB::transaction(function () use ($post, $provenance, $actor, $reason): PostRevision {
            $locked = Post::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($post->getKey());
            $snapshot = $this->snapshot($locked);
            $hash = $this->snapshotHash($snapshot);
            $previous = PostRevision::query()
                ->whereBelongsTo($locked)
                ->latest('id')
                ->first();

            if (
                $previous !== null
                && $this->deduplicates($provenance)
                && hash_equals((string) $previous->snapshot_hash, $hash)
            ) {
                return $previous;
            }

            return PostRevision::query()->create([
                'post_id' => $locked->getKey(),
                'user_id' => ($actor ?? $this->authenticatedUser())?->getKey(),
                'provenance' => $provenance,
                'reason' => $reason,
                'snapshot' => $snapshot,
                'changed_fields' => $this->changedFields($previous?->snapshot, $snapshot),
                'snapshot_hash' => $hash,
            ]);
        }, 3);
    }

    public function restore(
        Post $post,
        PostRevision $revision,
        ?User $actor = null,
        ?string $reason = null,
        ?string $expectedContentFingerprint = null,
    ): Post {
        if (! $post->exists || ! $revision->exists) {
            throw new LogicException('A saved Journal post and revision are required.');
        }

        if ((int) $revision->post_id !== (int) $post->getKey()) {
            throw new DomainException('The selected revision does not belong to this Journal post.');
        }

        return DB::transaction(function () use ($post, $revision, $actor, $reason, $expectedContentFingerprint): Post {
            $locked = Post::query()
                ->lockForUpdate()
                ->findOrFail($post->getKey());

            if (
                $expectedContentFingerprint !== null
                && ! hash_equals($expectedContentFingerprint, $this->contentFingerprint($locked))
            ) {
                throw new DomainException('The Journal post changed in another session. Reload History before restoring a revision.');
            }

            $lockedRevision = PostRevision::query()
                ->whereBelongsTo($locked)
                ->lockForUpdate()
                ->findOrFail($revision->getKey());
            $content = $this->restorableContent($lockedRevision->snapshot);

            $this->assertRestorableCoverIntegrity($lockedRevision->snapshot, $content['cover_image_path']);
            $this->assertCandidateIsReadyWhenRequired($locked, $content);

            $this->withoutAutomaticCapture(function () use ($locked, $content): void {
                $locked->fill($content);
                $locked->saveOrFail();
            });

            $this->capture(
                $locked,
                provenance: 'revision_restore',
                actor: $actor,
                reason: $reason,
            );

            return $locked->refresh();
        }, 3);
    }

    public function contentFingerprint(Post $post): string
    {
        $content = collect(Post::REVISION_CONTENT_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $post->getAttribute($field)])
            ->all();

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function trash(Post $post, ?User $actor = null, ?string $reason = null): Post
    {
        if (! $post->exists) {
            throw new LogicException('A saved Journal post is required.');
        }

        return DB::transaction(function () use ($post, $actor, $reason): Post {
            $locked = Post::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($post->getKey());

            if ($locked->trashed()) {
                throw new DomainException('The Journal post is already in the trash.');
            }

            $wasPublic = $locked->isPubliclyPublishedAt();
            $this->capture($locked, 'trash', $actor, $reason);

            $this->withoutAutomaticCapture(function () use ($locked): void {
                $locked->forceFill([
                    'status' => PostStatus::Draft,
                    'scheduled_at' => null,
                    'published' => false,
                    'published_at' => null,
                ]);
                $locked->saveOrFail();
                $locked->delete();
            });

            if ($wasPublic) {
                $this->connections->touchConnectedMedia($locked);
            }

            return $locked;
        }, 3);
    }

    public function restoreTrashed(Post $post, ?User $actor = null, ?string $reason = null): Post
    {
        if (! $post->exists) {
            throw new LogicException('A saved Journal post is required.');
        }

        return DB::transaction(function () use ($post, $actor, $reason): Post {
            $locked = Post::query()
                ->onlyTrashed()
                ->lockForUpdate()
                ->findOrFail($post->getKey());

            $this->withoutAutomaticCapture(function () use ($locked): void {
                $locked->restore();
            });

            $this->capture($locked, 'trash_restore', $actor, $reason);

            return $locked->refresh();
        }, 3);
    }

    public function forceDelete(Post $post, ?User $actor = null, ?string $reason = null): void
    {
        if (! $post->exists) {
            throw new LogicException('A saved Journal post is required.');
        }

        DB::transaction(function () use ($post, $actor, $reason): void {
            $locked = Post::query()
                ->onlyTrashed()
                ->lockForUpdate()
                ->findOrFail($post->getKey());

            $this->capture($locked, 'force_delete', $actor, $reason);
            $this->slugRedirects->tombstoneCurrentSlug($locked);
            $locked->forceDelete();
        }, 3);
    }

    /**
     * @return array{
     *   version: int,
     *   content: array<string, ?string>,
     *   cover: array{path: ?string, source_disk: ?string, size: ?int, sha256: ?string},
     *   tag_ids: list<int>,
     *   media: list<array<string, mixed>>
     * }
     */
    private function snapshot(Post $post): array
    {
        $content = collect(Post::REVISION_CONTENT_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $post->getAttribute($field)])
            ->all();
        $tagIds = DB::table('post_tag')
            ->where('post_id', $post->getKey())
            ->reorder('tag_id')
            ->pluck('tag_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $media = $post->mediaItems()
            ->reorder('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PostMedia $item): array => $this->mediaAuditReference($item))
            ->values()
            ->all();

        return [
            'version' => 1,
            'content' => $content,
            'cover' => $this->coverIntegrity($content['cover_image_path']),
            'tag_ids' => $tagIds,
            'media' => $media,
        ];
    }

    /** @return array<string, ?string> */
    private function restorableContent(mixed $snapshot): array
    {
        if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 1 || ! is_array($snapshot['content'] ?? null)) {
            throw new DomainException('The selected Journal revision has an unsupported snapshot format.');
        }

        $content = [];

        foreach (Post::REVISION_CONTENT_FIELDS as $field) {
            if (! array_key_exists($field, $snapshot['content'])) {
                throw new DomainException('The selected Journal revision is missing restorable content.');
            }

            $value = $snapshot['content'][$field];

            if ($value !== null && ! is_string($value)) {
                throw new DomainException('The selected Journal revision contains invalid restorable content.');
            }

            $content[$field] = $value;
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function assertRestorableCoverIntegrity(array $snapshot, ?string $coverPath): void
    {
        $integrity = $snapshot['cover'] ?? null;

        if (! is_array($integrity) || ($integrity['path'] ?? null) !== $coverPath) {
            throw new DomainException('The selected Journal revision has invalid cover integrity metadata.');
        }

        if (blank($coverPath)) {
            return;
        }

        $expectedSize = $integrity['size'] ?? null;
        $expectedHash = $integrity['sha256'] ?? null;

        if (
            ! in_array($integrity['source_disk'] ?? null, ['local', 'public'], true)
            || ! is_int($expectedSize)
            || $expectedSize < 0
            || ! is_string($expectedHash)
            || preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1
        ) {
            throw new DomainException('The cover image referenced by this Journal revision had no verifiable source bytes.');
        }

        $current = $this->coverIntegrity($coverPath);

        if (($current['source_disk'] ?? null) === 'missing') {
            throw new DomainException('The cover image referenced by this Journal revision no longer exists.');
        }

        if (($current['size'] ?? null) !== $expectedSize || ! hash_equals($expectedHash, (string) ($current['sha256'] ?? ''))) {
            throw new DomainException('The cover image referenced by this Journal revision no longer matches its recorded bytes.');
        }
    }

    /**
     * @param  array<string, ?string>  $content
     */
    private function assertCandidateIsReadyWhenRequired(Post $post, array $content): void
    {
        if (
            $post->getRawOriginal('status') === PostStatus::Draft->value
            && ! $post->published
            && $post->scheduled_at === null
            && $post->published_at === null
        ) {
            return;
        }

        $candidate = clone $post;
        $candidate->fill($content);
        $report = $this->readiness->evaluate($candidate);

        if ($report->hasBlockers()) {
            throw new DomainException('The selected revision is not publication-ready: '.implode(' ', $report->blockers()));
        }
    }

    /** @return array<string, mixed> */
    private function mediaAuditReference(PostMedia $item): array
    {
        $references = collect(PostMediaType::cases())
            ->filter(fn (PostMediaType $type): bool => $item->getAttribute($type->foreignKey()) !== null)
            ->mapWithKeys(fn (PostMediaType $type): array => [
                $type->foreignKey() => (int) $item->getAttribute($type->foreignKey()),
            ])
            ->all();
        $type = $item->type();

        if ($type !== null && (int) $item->position >= 1) {
            return [
                'position' => (int) $item->position,
                'type' => $type->value,
                'id' => (int) $item->getAttribute($type->foreignKey()),
            ];
        }

        return [
            'position' => (int) $item->position,
            'type' => 'invalid',
            'id' => null,
            'invalid' => true,
            'references' => $references,
        ];
    }

    /**
     * @return array{path: ?string, source_disk: ?string, size: ?int, sha256: ?string}
     */
    private function coverIntegrity(?string $path): array
    {
        if (blank($path)) {
            return [
                'path' => null,
                'source_disk' => null,
                'size' => null,
                'sha256' => null,
            ];
        }

        $diskName = Storage::disk('local')->exists($path)
            ? 'local'
            : (Storage::disk('public')->exists($path) ? 'public' : null);

        if ($diskName === null) {
            return [
                'path' => $path,
                'source_disk' => 'missing',
                'size' => null,
                'sha256' => null,
            ];
        }

        $stream = $this->media->sourceDisk($path)->readStream($path);

        if (! is_resource($stream)) {
            return [
                'path' => $path,
                'source_disk' => 'missing',
                'size' => null,
                'sha256' => null,
            ];
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    return [
                        'path' => $path,
                        'source_disk' => 'missing',
                        'size' => null,
                        'sha256' => null,
                    ];
                }

                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return [
            'path' => $path,
            'source_disk' => $diskName,
            'size' => $size,
            'sha256' => hash_final($hash),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function changedFields(?array $previous, array $current): array
    {
        if ($previous === null) {
            return [
                ...array_map(fn (string $field): string => 'content.'.$field, Post::REVISION_CONTENT_FIELDS),
                'tags',
                'media',
            ];
        }

        $changed = [];

        foreach (Post::REVISION_CONTENT_FIELDS as $field) {
            if (data_get($previous, 'content.'.$field) !== data_get($current, 'content.'.$field)) {
                $changed[] = 'content.'.$field;
            }
        }

        if (($previous['tag_ids'] ?? null) !== ($current['tag_ids'] ?? null)) {
            $changed[] = 'tags';
        }

        if (($previous['media'] ?? null) !== ($current['media'] ?? null)) {
            $changed[] = 'media';
        }

        return $changed;
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeProvenance(string $provenance): string
    {
        $provenance = trim($provenance);

        if ($provenance === '' || Str::length($provenance) > 64) {
            throw new DomainException('Journal revision provenance must be between 1 and 64 characters.');
        }

        return $provenance;
    }

    private function normalizeReason(?string $reason, string $provenance): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        $maxLength = $provenance === 'slug_change' ? 600 : 500;

        if ($reason !== null && Str::length($reason) > $maxLength) {
            throw new DomainException("Journal revision reasons cannot be longer than {$maxLength} characters.");
        }

        return $reason === '' ? null : $reason;
    }

    private function deduplicates(string $provenance): bool
    {
        return in_array($provenance, ['content_edit', 'connections_update'], true);
    }

    private function authenticatedUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** @template TValue */
    /** @param Closure(): TValue $callback */
    /** @return TValue */
    private function withoutAutomaticCapture(Closure $callback): mixed
    {
        self::$automaticCaptureSuppressionDepth++;

        try {
            return $callback();
        } finally {
            self::$automaticCaptureSuppressionDepth--;
        }
    }
}
