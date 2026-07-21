<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Track;
use App\Services\PublicStoryConnections;
use App\Services\SharedTagPageService;
use Illuminate\Http\Response;

class DiscoveryController extends Controller
{
    public function robots(): Response
    {
        if (! config('creative_ai.allow_indexing')) {
            $contents = "User-agent: *\nDisallow: /\n";
        } else {
            $contents = implode("\n", [
                'User-agent: *',
                'Allow: /',
                'Disallow: /admin',
                'Sitemap: '.rtrim((string) config('app.url'), '/').'/sitemap.xml',
                '',
            ]);
        }

        return response($contents)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(
        SharedTagPageService $tagPages,
        PublicStoryConnections $storyConnections,
    ): Response {
        return response()->view('discovery.sitemap', [
            'artworks' => Artwork::query()
                ->publiclyAvailable()
                ->with(['collections' => fn ($query) => $query
                    ->published()
                    ->memberPublicationGrants()])
                ->orderByDesc('sort_order')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
            'collections' => Collection::query()->published()->get(),
            'albums' => Album::query()
                ->published()
                ->orderByDesc('featured')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'playlists' => Playlist::query()
                ->published()
                ->orderByDesc('featured')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'tracks' => Track::query()
                ->publiclyAvailable()
                ->orderBy('id')
                ->get(),
            'posts' => Post::query()->latestPublished()->get(),
            'tags' => $tagPages->publicTags(),
            'storyLastModified' => $storyConnections->latestPostUpdatesByMedia(),
        ])->header('Content-Type', 'application/xml');
    }

    public function feed(): Response
    {
        $posts = Post::query()->latestPublished()->limit(20)->get();

        return response()->view('discovery.feed', [
            'posts' => $posts,
            'lastBuildDate' => $posts
                ->map(fn (Post $post) => $post->effectivePublicContentUpdatedAt())
                ->filter()
                ->sortByDesc(fn ($date) => $date->getTimestamp())
                ->first(),
        ])->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
