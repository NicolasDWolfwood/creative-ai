<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Post;
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

    public function sitemap(): Response
    {
        return response()->view('discovery.sitemap', [
            'artworks' => Artwork::query()
                ->published()
                ->orderByDesc('sort_order')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
            'collections' => Collection::query()->published()->get(),
            'posts' => Post::query()->published()->get(),
        ])->header('Content-Type', 'application/xml');
    }

    public function feed(): Response
    {
        return response()->view('discovery.feed', [
            'posts' => Post::query()->published()->orderByDesc('published_at')->limit(20)->get(),
        ])->header('Content-Type', 'application/rss+xml');
    }
}
