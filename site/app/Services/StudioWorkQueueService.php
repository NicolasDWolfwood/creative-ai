<?php

namespace App\Services;

use App\Enums\PostAiRunStatus;
use App\Enums\PostStatus;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Artworks\ArtworkResource;
use App\Filament\Resources\JournalAiRuns\JournalAiRunResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Tracks\TrackResource;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\Track;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class StudioWorkQueueService
{
    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     oldest_at: ?CarbonImmutable,
     *     timestamp_label: string,
     *     reason: string,
     *     href: string,
     *     action_label: string,
     *     tone: string,
     *     links: list<array{label: string, href: string}>
     * }>
     */
    public function summaries(): array
    {
        $failedVariants = Artwork::query()
            ->where('variant_status', Artwork::VARIANT_STATUS_FAILED);
        $failedAnalysis = Track::query()
            ->where('analysis_status', 'failed');
        $missingArtworkAlt = Artwork::query()
            ->published()
            ->whereRaw("TRIM(COALESCE(alt_text, '')) = ''");
        $artworkSuggestions = Artwork::query()
            ->where('ai_status', Artwork::AI_STATUS_READY)
            ->whereNotNull('ai_suggestion');
        $trackSuggestions = Track::query()
            ->where('ai_status', Track::AI_STATUS_READY)
            ->whereNotNull('ai_suggestion');
        $draftPosts = $this->effectiveDraftPosts();
        $scheduledPosts = Post::query()
            ->where('status', PostStatus::Scheduled->value)
            ->where('published', true)
            ->whereNotNull('scheduled_at')
            ->whereNotNull('published_at')
            ->whereColumn('published_at', 'scheduled_at')
            ->where('scheduled_at', '>', now());
        $journalAiAttention = PostAiRun::query()
            ->whereIn('status', [
                PostAiRunStatus::Failed->value,
                PostAiRunStatus::Stale->value,
            ]);

        [$missingMusicCoverCount, $missingMusicCoverOldest] = $this->missingMusicCovers();
        [$metadataSuggestionCount, $metadataSuggestionOldest] = $this->combine(
            [$artworkSuggestions, $trackSuggestions],
            'ai_analyzed_at',
        );

        return [
            $this->summary(
                key: 'failed-artwork-variants',
                label: 'Failed artwork variants',
                query: $failedVariants,
                reason: 'Display or thumbnail generation did not complete. Retry from the artwork library.',
                href: ArtworkResource::getUrl(),
                tone: 'danger',
                timestampColumn: 'updated_at',
            ),
            $this->summary(
                key: 'failed-track-analysis',
                label: 'Failed track audio analysis',
                query: $failedAnalysis,
                reason: 'FFmpeg or FFprobe analysis needs an operator retry; stored error details stay out of this dashboard.',
                href: TrackResource::getUrl(),
                tone: 'danger',
                timestampColumn: 'analyzed_at',
            ),
            $this->summary(
                key: 'missing-artwork-alt',
                label: 'Missing artwork alt text',
                query: $missingArtworkAlt,
                reason: 'Effectively public artwork is missing a dedicated accessibility description.',
                href: ArtworkResource::getUrl(),
                tone: 'warning',
            ),
            $this->fixedSummary(
                key: 'missing-public-music-covers',
                label: 'Missing public music covers',
                count: $missingMusicCoverCount,
                oldestAt: $missingMusicCoverOldest,
                reason: 'Published albums, playlists, or standalone tracks have no public cover; intentional no-cover album choices are excluded.',
                href: TrackResource::getUrl(),
                tone: 'warning',
                links: [
                    ['label' => 'Tracks', 'href' => TrackResource::getUrl()],
                    ['label' => 'Albums', 'href' => AlbumResource::getUrl()],
                    ['label' => 'Playlists', 'href' => PlaylistResource::getUrl()],
                ],
            ),
            $this->fixedSummary(
                key: 'ai-metadata-review',
                label: 'AI metadata suggestions awaiting review',
                count: $metadataSuggestionCount,
                oldestAt: $metadataSuggestionOldest,
                reason: 'Normalized artwork and track suggestions are ready, but no public metadata has been applied yet.',
                href: ArtworkResource::getUrl(parameters: [
                    'tableFilters' => ['ai_status' => ['value' => Artwork::AI_STATUS_READY]],
                ]),
                tone: 'info',
                links: [
                    [
                        'label' => 'Artwork',
                        'href' => ArtworkResource::getUrl(parameters: [
                            'tableFilters' => ['ai_status' => ['value' => Artwork::AI_STATUS_READY]],
                        ]),
                    ],
                    ['label' => 'Tracks', 'href' => TrackResource::getUrl()],
                ],
            ),
            $this->summary(
                key: 'journal-drafts',
                label: 'Journal Draft posts',
                query: $draftPosts,
                reason: 'Posts that currently resolve to Draft, including invalid stored lifecycle combinations, remain private for editorial review.',
                href: PostResource::getUrl(parameters: [
                    'tableFilters' => ['workflow_status' => ['value' => PostStatus::Draft->value]],
                ]),
                tone: 'neutral',
            ),
            $this->summary(
                key: 'journal-scheduled',
                label: 'Future Scheduled posts',
                query: $scheduledPosts,
                reason: 'Valid future Journal schedules will publish automatically at their stored UTC time.',
                href: PostResource::getUrl(parameters: [
                    'tableFilters' => ['workflow_status' => ['value' => PostStatus::Scheduled->value]],
                ]),
                tone: 'info',
                timestampColumn: 'scheduled_at',
                timestampLabel: 'Next',
            ),
            $this->summary(
                key: 'journal-ai-attention',
                label: 'Stale or failed Journal AI runs',
                query: $journalAiAttention,
                reason: 'Retained run status needs review or an explicit retry; prompts, context, and provider errors are never shown here.',
                href: $this->journalAiRunsUrl(),
                tone: 'danger',
                timestampColumn: 'completed_at',
            ),
        ];
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<array{label: string, href: string}>  $links
     * @return array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     oldest_at: ?CarbonImmutable,
     *     timestamp_label: string,
     *     reason: string,
     *     href: string,
     *     action_label: string,
     *     tone: string,
     *     links: list<array{label: string, href: string}>
     * }
     */
    private function summary(
        string $key,
        string $label,
        Builder $query,
        string $reason,
        string $href,
        string $tone,
        string $timestampColumn = 'created_at',
        string $timestampLabel = 'Oldest',
        array $links = [],
    ): array {
        [$count, $oldestAt] = $this->aggregate($query, $timestampColumn);

        return $this->fixedSummary(
            key: $key,
            label: $label,
            count: $count,
            oldestAt: $oldestAt,
            reason: $reason,
            href: $href,
            tone: $tone,
            timestampLabel: $timestampLabel,
            links: $links,
        );
    }

    /**
     * @param  list<array{label: string, href: string}>  $links
     * @return array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     oldest_at: ?CarbonImmutable,
     *     timestamp_label: string,
     *     reason: string,
     *     href: string,
     *     action_label: string,
     *     tone: string,
     *     links: list<array{label: string, href: string}>
     * }
     */
    private function fixedSummary(
        string $key,
        string $label,
        int $count,
        ?CarbonInterface $oldestAt,
        string $reason,
        string $href,
        string $tone,
        string $timestampLabel = 'Oldest',
        array $links = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'oldest_at' => $oldestAt ? CarbonImmutable::instance($oldestAt) : null,
            'timestamp_label' => $timestampLabel,
            'reason' => $reason,
            'href' => $href,
            'action_label' => 'Review',
            'tone' => $tone,
            'links' => $links,
        ];
    }

    /**
     * @param  list<Builder<*>>  $queries
     * @return array{int, ?CarbonImmutable}
     */
    private function combine(array $queries, string $timestampColumn = 'created_at'): array
    {
        $count = 0;
        $timestamps = [];

        foreach ($queries as $query) {
            [$queryCount, $timestamp] = $this->aggregate($query, $timestampColumn);
            $count += $queryCount;

            if ($timestamp) {
                $timestamps[] = $timestamp;
            }
        }

        return [$count, collect($timestamps)->sort()->first()];
    }

    /**
     * @param  Builder<*>  $query
     * @return array{int, ?CarbonImmutable}
     */
    private function aggregate(Builder $query, string $timestampColumn): array
    {
        $qualifiedTimestamp = $query->getModel()->qualifyColumn($timestampColumn);
        $wrappedTimestamp = $query->getQuery()->getGrammar()->wrap($qualifiedTimestamp);
        $aggregate = (clone $query)
            ->toBase()
            ->selectRaw("COUNT(*) AS queue_count, MIN({$wrappedTimestamp}) AS oldest_at")
            ->first();
        $oldestAt = filled($aggregate?->oldest_at)
            ? CarbonImmutable::parse((string) $aggregate->oldest_at)
            : null;

        return [(int) ($aggregate?->queue_count ?? 0), $oldestAt];
    }

    /** @return array{int, ?CarbonImmutable} */
    private function missingMusicCovers(): array
    {
        $albums = Album::query()
            ->published()
            ->where('cover_preference', '!=', 'none')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('cover_preference', 'artwork')
                            ->whereDoesntHave('coverArtwork', fn (Builder $query) => $query->published());
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('cover_preference', '!=', 'artwork')
                            ->where(function (Builder $query): void {
                                $query->whereNull('embedded_cover_path')->orWhere('embedded_cover_path', '');
                            })
                            ->whereDoesntHave('coverArtwork', fn (Builder $query) => $query->published());
                    });
            });
        $playlists = Playlist::query()
            ->published()
            ->whereDoesntHave('coverArtwork', fn (Builder $query) => $query->published());
        $tracks = Track::query()
            ->published()
            ->whereDoesntHave('coverArtwork', fn (Builder $query) => $query->published())
            ->whereDoesntHave('album', function (Builder $query): void {
                $query->published()->where(function (Builder $query): void {
                    $query
                        ->where('cover_preference', 'none')
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->where('cover_preference', 'artwork')
                                ->whereHas('coverArtwork', fn (Builder $query) => $query->published());
                        })
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->whereNotIn('cover_preference', ['none', 'artwork'])
                                ->where(function (Builder $query): void {
                                    $query
                                        ->whereNotNull('embedded_cover_path')
                                        ->where('embedded_cover_path', '!=', '')
                                        ->orWhereHas('coverArtwork', fn (Builder $query) => $query->published());
                                });
                        });
                });
            });

        return $this->combine([$albums, $playlists, $tracks]);
    }

    /** @return Builder<Post> */
    private function effectiveDraftPosts(): Builder
    {
        $now = now();

        return Post::query()->whereNot(function (Builder $query) use ($now): void {
            $query
                ->where(function (Builder $query): void {
                    $query
                        ->where('status', PostStatus::Ready->value)
                        ->where('published', false)
                        ->whereNull('scheduled_at');
                })
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('status', PostStatus::Scheduled->value)
                        ->where('published', true)
                        ->whereNotNull('scheduled_at')
                        ->whereNotNull('published_at')
                        ->whereColumn('published_at', 'scheduled_at');
                })
                ->orWhere(function (Builder $query) use ($now): void {
                    $query
                        ->where('status', PostStatus::Published->value)
                        ->where('published', true)
                        ->whereNull('scheduled_at')
                        ->whereNotNull('published_at')
                        ->where('published_at', '<=', $now);
                });
        });
    }

    private function journalAiRunsUrl(): string
    {
        if (class_exists(JournalAiRunResource::class)) {
            return JournalAiRunResource::getUrl();
        }

        return PostResource::getUrl();
    }
}
