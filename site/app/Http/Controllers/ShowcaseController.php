<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Post;
use App\Models\SiteSetting;
use App\Models\Tag;
use App\Models\Track;
use App\Services\CollectionCoverService;
use App\Services\HomepageHeroArtworkService;
use App\Services\PublicMediaService;
use App\Services\PublicStoryConnections;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ShowcaseController extends Controller
{
    public function __construct(
        protected PublicMediaService $media,
        protected CollectionCoverService $collectionCovers,
        protected PublicStoryConnections $storyConnections,
        protected HomepageHeroArtworkService $homepageHeroArtwork,
    ) {}

    public function index(Request $request): View
    {
        $selectedTagSlug = $request->query('tag');

        return $this->renderShowcase(
            selectedTagSlug: $selectedTagSlug,
            useHomepageHero: blank($selectedTagSlug),
        );
    }

    public function gallery(Request $request): View
    {
        return $this->renderShowcase(limit: 48, selectedTagSlug: $request->query('tag'), paginateArtwork: true);
    }

    public function collection(Collection $collection, Request $request): View
    {
        abort_unless($collection->isPubliclyPublished(), 404);

        return $this->renderShowcase($collection, 48, $request->query('tag'), paginateArtwork: true);
    }

    protected function renderShowcase(
        ?Collection $selectedCollection = null,
        int $limit = 72,
        ?string $selectedTagSlug = null,
        bool $paginateArtwork = false,
        bool $useHomepageHero = false,
    ): View {
        $intro = SiteSetting::query()->where('key', 'home_intro')->first()?->value ?: [
            'title' => 'Creative-Ai',
            'body' => 'A living archive of generative artwork, visual experiments, and original sound.',
        ];
        $publicArtworkForContext = function (Builder $query) use ($selectedCollection): void {
            if ($selectedCollection) {
                $query
                    ->publiclyAvailable()
                    ->whereHas('collections', fn (Builder $query) => $query->whereKey($selectedCollection->getKey()));

                return;
            }

            $query->published();
        };
        $selectedTag = $selectedTagSlug
            ? Tag::query()
                ->where('slug', $selectedTagSlug)
                ->whereHas('artworks', $publicArtworkForContext)
                ->first()
            : null;
        $artworksQuery = Artwork::query()
            ->with(['collections', 'tags'])
            ->orderByDesc('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($selectedCollection) {
            $artworksQuery
                ->publiclyAvailable()
                ->whereHas('collections', fn (Builder $query) => $query->whereKey($selectedCollection->getKey()));
        } else {
            $artworksQuery->published();
        }

        if ($selectedTag) {
            $artworksQuery->whereHas('tags', fn (Builder $query) => $query->whereKey($selectedTag->getKey()));
        }

        $archiveArtworkCount = (clone $artworksQuery)->count();
        $artworks = $paginateArtwork
            ? $artworksQuery->cursorPaginate($limit)->withQueryString()
            : $artworksQuery->limit($limit)->get();
        $heroArtwork = $useHomepageHero
            ? $this->homepageHeroArtwork->select()
            : ($artworks->first()
                ?: Artwork::query()->published()->orderByDesc('featured')->orderByDesc('sort_order')->first());
        $heroImageUrl = $heroArtwork
            ? ($useHomepageHero ? $heroArtwork->homepage_display_url : $heroArtwork->display_url)
            : null;
        $collections = Collection::query()
            ->published()
            ->withCount(['artworks' => fn ($query) => $query->publiclyAvailable()])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $collectionCovers = $this->collectionCovers->select($collections);
        $tags = Tag::query()
            ->whereHas('artworks', $publicArtworkForContext)
            ->withCount(['artworks' => $publicArtworkForContext])
            ->orderByDesc('artworks_count')
            ->limit(36)
            ->get();
        $playlists = $this->media->playlists();
        $albums = $this->media->albums();
        $standaloneTracks = $this->media->standaloneTracks();
        $homeAlbums = $albums->take(4);
        $homePlaylists = $playlists->take(max(0, 6 - $homeAlbums->count()));
        $posts = Post::query()->latestPublished()->limit(3)->get();
        $pageTitle = $selectedCollection?->title ?? ($selectedTag ? ucfirst($selectedTag->name) : 'Creative-Ai');
        $description = $selectedCollection?->description ?: ($intro['body'] ?? 'Generative art and original music by John Reijmer.');
        $collectionStories = $selectedCollection
            ? $this->storyConnections->postsForMedia($selectedCollection)
            : collect();
        $structuredData = null;

        if ($selectedCollection) {
            $collectionItems = collect($artworks->items());
            $canonical = route('collections.show', $selectedCollection);
            $itemListId = request()->fullUrl().'#items';
            $structuredData = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'CollectionPage',
                        '@id' => $canonical.'#collection',
                        'name' => $selectedCollection->title,
                        'description' => str($description)->squish()->limit(200, '')->toString(),
                        'url' => $canonical,
                        'mainEntity' => ['@id' => $itemListId],
                        'subjectOf' => $collectionStories
                            ->map(fn (Post $post): array => ['@id' => route('posts.show', $post).'#article'])
                            ->values()
                            ->all(),
                    ],
                    [
                        '@type' => 'ItemList',
                        '@id' => $itemListId,
                        'numberOfItems' => $collectionItems->count(),
                        'itemListElement' => $collectionItems
                            ->map(fn (Artwork $artwork, int $index): array => [
                                '@type' => 'ListItem',
                                'position' => $index + 1,
                                'item' => [
                                    '@type' => 'VisualArtwork',
                                    '@id' => route('artworks.show', $artwork).'#artwork',
                                    'name' => $artwork->title,
                                    'url' => route('artworks.show', $artwork),
                                ],
                            ])
                            ->all(),
                    ],
                ],
            ];
        }

        return view('showcase', [
            'intro' => $intro,
            'heroArtwork' => $heroArtwork,
            'heroImageUrl' => $heroImageUrl,
            'collections' => $collections,
            'collectionCovers' => $collectionCovers,
            'collectionCoverPlaceholder' => asset(CollectionCoverService::PLACEHOLDER_PATH),
            'selectedCollection' => $selectedCollection,
            'selectedTag' => $selectedTag,
            'tags' => $tags,
            'artworks' => $artworks,
            'archiveArtworkCount' => $archiveArtworkCount,
            'paginateArtwork' => $paginateArtwork,
            'totalArtworkCount' => Artwork::query()->published()->count(),
            'publicTrackCount' => Track::query()->publiclyAvailable()->count(),
            'playlists' => $playlists,
            'albums' => $albums,
            'homeAlbums' => $homeAlbums,
            'homePlaylists' => $homePlaylists,
            'posts' => $posts,
            'collectionStories' => $collectionStories,
            'playerPayload' => $this->media->playerPayload($playlists, $albums, $standaloneTracks),
            'seo' => [
                'title' => $pageTitle === 'Creative-Ai' ? 'Creative-Ai | Generative Art and Original Music' : $pageTitle.' | Creative-Ai',
                'description' => str($description)->squish()->limit(200, '')->toString(),
                'image' => $heroImageUrl ? url($heroImageUrl) : null,
                'canonical' => $selectedCollection ? route('collections.show', $selectedCollection) : request()->url(),
                'type' => 'website',
            ],
            'structured_data' => $structuredData,
        ]);
    }
}
