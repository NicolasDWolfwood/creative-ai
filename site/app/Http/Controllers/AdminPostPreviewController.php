<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\PublicMediaService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AdminPostPreviewController extends Controller
{
    public function __construct(protected PublicMediaService $media) {}

    public function __invoke(Post $post): Response
    {
        Gate::authorize('preview', $post);

        return response()
            ->view('posts.show', [
                'post' => $post,
                'morePosts' => collect(),
                'playerPayload' => $this->media->libraryPlayerPayload(),
                'preview' => true,
                'seo' => [
                    'title' => 'Preview: '.$post->title.' | Creative-Ai',
                    'description' => $post->seo_description ?: $post->summary,
                    'image' => $post->cover_url ? url($post->cover_url) : null,
                    'canonical' => route('posts.show', $post),
                    'type' => 'article',
                    'published_at' => $post->effectivePublishedAt()?->toIso8601String(),
                ],
            ])
            ->header('Cache-Control', 'no-store, no-cache, private, max-age=0, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
