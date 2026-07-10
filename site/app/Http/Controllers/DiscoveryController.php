<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Post;
use Illuminate\Http\Response;

class DiscoveryController extends Controller
{
    public function sitemap(): Response
    {
        return response()->view('discovery.sitemap', [
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
