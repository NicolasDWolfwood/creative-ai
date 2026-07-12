<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Post;
use App\Models\SiteSetting;
use App\Models\Tag;
use App\Models\Track;
use App\Services\CollectionCoverService;
use App\Services\PublicMediaService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ShowcaseController extends Controller
{
    public function __construct(
        protected PublicMediaService $media,
        protected CollectionCoverService $collectionCovers,
    ) {}

    public function index(Request $request): View
    {
        return $this->renderShowcase(selectedTagSlug: $request->query('tag'));
    }

    public function gallery(Request $request): View
    {
        return $this->renderShowcase(limit: 240, selectedTagSlug: $request->query('tag'));
    }

    public function collection(Collection $collection, Request $request): View
    {
        abort_unless($collection->isPubliclyPublished(), 404);

        return $this->renderShowcase($collection, 240, $request->query('tag'));
    }

    protected function renderShowcase(?Collection $selectedCollection = null, int $limit = 72, ?string $selectedTagSlug = null): View
    {
        $intro = SiteSetting::query()->where('key', 'home_intro')->first()?->value ?: [
            'title' => 'Creative-Ai',
            'body' => 'A living archive of generative artwork, visual experiments, and original sound.',
        ];
        $selectedTag = $selectedTagSlug ? Tag::query()->where('slug', $selectedTagSlug)->first() : null;
        $artworksQuery = Artwork::query()
            ->published()
            ->with(['collections', 'tags'])
            ->orderByDesc('sort_order')
            ->latest();

        if ($selectedCollection) {
            $artworksQuery->whereHas('collections', fn (Builder $query) => $query->whereKey($selectedCollection->getKey()));
        }

        if ($selectedTag) {
            $artworksQuery->whereHas('tags', fn (Builder $query) => $query->whereKey($selectedTag->getKey()));
        }

        $artworks = $artworksQuery->limit($limit)->get();
        $heroArtwork = $artworks->first()
            ?: Artwork::query()->published()->orderByDesc('featured')->orderByDesc('sort_order')->first();
        $collections = Collection::query()
            ->published()
            ->with(['artworks' => fn ($query) => $query->published()->where('featured', true)])
            ->withCount(['artworks' => fn ($query) => $query->published()])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $collectionCovers = $this->collectionCovers->select($collections);
        $tags = Tag::query()
            ->whereHas('artworks', function (Builder $query) use ($selectedCollection): void {
                $query->published();

                if ($selectedCollection) {
                    $query->whereHas('collections', fn (Builder $query) => $query->whereKey($selectedCollection->getKey()));
                }
            })
            ->withCount(['artworks' => function (Builder $query) use ($selectedCollection): void {
                $query->published();

                if ($selectedCollection) {
                    $query->whereHas('collections', fn (Builder $query) => $query->whereKey($selectedCollection->getKey()));
                }
            }])
            ->orderByDesc('artworks_count')
            ->limit(36)
            ->get();
        $playlists = $this->media->playlists();
        $albums = $this->media->albums();
        $standaloneTracks = $this->media->standaloneTracks();
        $homeAlbums = $albums->take(4);
        $homePlaylists = $playlists->take(max(0, 6 - $homeAlbums->count()));
        $posts = Post::query()->published()->orderByDesc('published_at')->limit(3)->get();
        $pageTitle = $selectedCollection?->title ?? ($selectedTag ? ucfirst($selectedTag->name) : 'Creative-Ai');
        $description = $selectedCollection?->description ?: ($intro['body'] ?? 'Generative art and original music by John Reijmer.');

        return view('showcase', [
            'intro' => $intro,
            'heroArtwork' => $heroArtwork,
            'collections' => $collections,
            'collectionCovers' => $collectionCovers,
            'collectionCoverPlaceholder' => asset(CollectionCoverService::PLACEHOLDER_PATH),
            'selectedCollection' => $selectedCollection,
            'selectedTag' => $selectedTag,
            'tags' => $tags,
            'artworks' => $artworks,
            'totalArtworkCount' => Artwork::query()->published()->count(),
            'publicTrackCount' => Track::query()->publiclyAvailable()->count(),
            'playlists' => $playlists,
            'albums' => $albums,
            'homeAlbums' => $homeAlbums,
            'homePlaylists' => $homePlaylists,
            'posts' => $posts,
            'playerPayload' => $this->media->playerPayload($playlists, $albums, $standaloneTracks),
            'seo' => [
                'title' => $pageTitle === 'Creative-Ai' ? 'Creative-Ai | Generative Art and Original Music' : $pageTitle.' | Creative-Ai',
                'description' => str($description)->squish()->limit(200, '')->toString(),
                'image' => $heroArtwork ? url($heroArtwork->display_url) : null,
                'canonical' => $selectedCollection ? route('collections.show', $selectedCollection) : request()->url(),
                'type' => 'website',
            ],
        ]);
    }
}
