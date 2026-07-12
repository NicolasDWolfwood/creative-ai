<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Post;
use App\Services\PublicMediaService;
use Illuminate\Contracts\View\View;

class PostController extends Controller
{
    public function __construct(protected PublicMediaService $media) {}

    public function index(): View
    {
        $posts = Post::query()->published()->orderByDesc('published_at')->paginate(12);
        $heroArtwork = Artwork::query()->published()->orderByDesc('featured')->orderByDesc('sort_order')->first();

        return view('posts.index', [
            'posts' => $posts,
            'playerPayload' => $this->media->libraryPlayerPayload(),
            'seo' => [
                'title' => 'Journal | Creative-Ai',
                'description' => 'Notes, experiments, releases, and progress from the Creative-Ai studio.',
                'image' => $heroArtwork ? url($heroArtwork->display_url) : null,
                'canonical' => route('posts.index'),
                'type' => 'website',
            ],
        ]);
    }

    public function show(Post $post): View
    {
        abort_unless($post->published && (! $post->published_at || $post->published_at->isPast()), 404);

        return view('posts.show', [
            'post' => $post,
            'morePosts' => Post::query()->published()->whereKeyNot($post->getKey())->orderByDesc('published_at')->limit(3)->get(),
            'playerPayload' => $this->media->libraryPlayerPayload(),
            'seo' => [
                'title' => ($post->seo_title ?: $post->title).' | Creative-Ai',
                'description' => $post->seo_description ?: $post->summary,
                'image' => $post->cover_url ? url($post->cover_url) : null,
                'canonical' => route('posts.show', $post),
                'type' => 'article',
                'published_at' => $post->published_at?->toIso8601String(),
            ],
        ]);
    }
}
