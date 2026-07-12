<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Post;
use App\Services\PostStructuredData;
use App\Services\PublicMediaService;
use App\Services\PublicStoryConnections;
use Illuminate\Contracts\View\View;

class PostController extends Controller
{
    public function __construct(
        protected PublicMediaService $media,
        protected PostStructuredData $structuredData,
        protected PublicStoryConnections $connections,
    ) {}

    public function index(): View
    {
        $posts = Post::query()->latestPublished()->paginate(12);
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
        abort_unless($post->isPubliclyPublishedAt(), 404);

        $post->load('tags');
        $publishedAt = $post->effectivePublishedAt();
        $connectedMedia = $this->connections->mediaForPost($post);

        return view('posts.show', [
            'post' => $post,
            'connectedMedia' => $connectedMedia,
            'morePosts' => Post::query()->whereKeyNot($post->getKey())->latestPublished()->limit(3)->get(),
            'playerPayload' => $this->media->libraryPlayerPayload(),
            'preview' => false,
            'seo' => [
                'title' => ($post->seo_title ?: $post->title).' | Creative-Ai',
                'description' => $post->seo_description ?: $post->summary,
                'image' => $post->cover_url ? url($post->cover_url) : null,
                'canonical' => route('posts.show', $post),
                'type' => 'article',
                'published_at' => $publishedAt?->toIso8601String(),
                'modified_at' => $post->effectivePublicContentUpdatedAt()?->toIso8601String(),
            ],
            'structured_data' => $this->structuredData->forPost($post, $connectedMedia),
        ]);
    }
}
