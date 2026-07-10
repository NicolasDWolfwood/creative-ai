<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\SiteSetting;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShowcaseController extends Controller
{
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
        abort_unless($collection->published, 404);

        return $this->renderShowcase($collection, 240, $request->query('tag'));
    }

    protected function renderShowcase(?Collection $selectedCollection = null, int $limit = 72, ?string $selectedTagSlug = null): View
    {
        $intro = SiteSetting::query()->where('key', 'home_intro')->first()?->value ?: [
            'title' => 'Creative Thoughts',
            'body' => 'A site made out of love for creating art with ComfyUI and Automatic1111.',
        ];

        $selectedTag = $selectedTagSlug
            ? Tag::query()->where('slug', $selectedTagSlug)->first()
            : null;

        $artworksQuery = Artwork::query()
            ->published()
            ->with(['collection', 'tags'])
            ->orderByDesc('sort_order')
            ->latest();

        if ($selectedCollection) {
            $artworksQuery->whereBelongsTo($selectedCollection);
        }

        if ($selectedTag) {
            $artworksQuery->whereHas('tags', fn ($query) => $query->whereKey($selectedTag->getKey()));
        }

        $artworks = $artworksQuery->limit($limit)->get();
        $heroArtwork = $artworks->first()
            ?: Artwork::query()->published()->orderByDesc('featured')->orderByDesc('sort_order')->first();

        $collections = Collection::query()
            ->published()
            ->withCount(['artworks' => fn ($query) => $query->published()])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->get();

        $tags = Tag::query()
            ->whereHas('artworks', function ($query) use ($selectedCollection): void {
                $query->published();

                if ($selectedCollection) {
                    $query->whereBelongsTo($selectedCollection);
                }
            })
            ->orderBy('name')
            ->limit(48)
            ->get();

        $playlists = Playlist::query()
            ->published()
            ->with([
                'coverArtwork',
                'tracks' => fn ($query) => $query->published()->with('coverArtwork'),
            ])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->get();

        return view('showcase', [
            'intro' => $intro,
            'heroArtwork' => $heroArtwork,
            'collections' => $collections,
            'selectedCollection' => $selectedCollection,
            'selectedTag' => $selectedTag,
            'tags' => $tags,
            'artworks' => $artworks,
            'playlists' => $playlists,
            'playerPayload' => $this->playerPayload($playlists),
        ]);
    }

    protected function playerPayload($playlists): array
    {
        return $playlists
            ->map(fn (Playlist $playlist) => [
                'id' => $playlist->id,
                'title' => $playlist->title,
                'description' => $playlist->description,
                'cover' => $playlist->cover_url,
                'tracks' => $playlist->tracks->map(fn ($track) => [
                    'id' => $track->id,
                    'title' => $track->title,
                    'artist' => $track->artist,
                    'url' => $track->audio_url,
                    'cover' => $track->cover_url ?: $playlist->cover_url,
                ])->values(),
            ])
            ->filter(fn (array $playlist) => count($playlist['tracks']) > 0)
            ->values()
            ->all();
    }
}
