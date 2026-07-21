<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostMedia;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class JournalPostCoverService
{
    public function __construct(
        private readonly JournalSourceImageResolver $sourceImages,
        private readonly JournalCoverService $covers,
        private readonly PublicStoryConnections $publicConnections,
    ) {}

    public function replaceFromConnection(
        Post $post,
        PostMedia $connection,
        string $expectedCoverFingerprint,
    ): Post {
        $copiedCoverPath = null;

        try {
            return DB::transaction(function () use (
                $post,
                $connection,
                $expectedCoverFingerprint,
                &$copiedCoverPath,
            ): Post {
                $lockedPost = Post::query()->lockForUpdate()->find($post->getKey());

                if (! $lockedPost instanceof Post) {
                    throw new DomainException('The Journal post is no longer available.');
                }

                if (! hash_equals($this->coverFingerprint($lockedPost), $expectedCoverFingerprint)) {
                    throw new DomainException('The Journal cover changed before this action was saved. Reload Connections and try again.');
                }

                $lockedConnection = PostMedia::query()
                    ->whereBelongsTo($lockedPost)
                    ->lockForUpdate()
                    ->find($connection->getKey());
                $source = $lockedConnection?->media();

                if (! $lockedConnection instanceof PostMedia || $source === null) {
                    throw new DomainException('The connected source is no longer available.');
                }

                if (! $this->publicConnections->mediaIsPublic($source)) {
                    throw new DomainException('Only currently public source artwork can become a Journal cover.');
                }

                $candidate = $this->sourceImages->resolve($source);

                if ($candidate === null) {
                    throw new DomainException('This source no longer has suitable artwork to copy.');
                }

                $copiedCoverPath = $this->covers->copy($candidate);
                $lockedPost->fill([
                    'cover_image_path' => $copiedCoverPath,
                    'cover_alt_text' => $candidate->altText,
                ])->save();

                return $lockedPost->refresh();
            });
        } catch (Throwable $exception) {
            $this->covers->cleanup($copiedCoverPath);

            throw $exception;
        }
    }

    public function coverFingerprint(Post $post): string
    {
        return hash('sha256', json_encode([
            'path' => $post->cover_image_path,
            'alt' => $post->cover_alt_text,
        ], JSON_THROW_ON_ERROR));
    }
}
