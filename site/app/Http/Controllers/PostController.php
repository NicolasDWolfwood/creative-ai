<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Post;
use App\Services\PostSlugRedirectService;
use App\Services\PostStructuredData;
use App\Services\PublicMediaService;
use App\Services\PublicStoryConnections;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class PostController extends Controller
{
    public function __construct(
        protected PublicMediaService $media,
        protected PostStructuredData $structuredData,
        protected PublicStoryConnections $connections,
        protected PostSlugRedirectService $slugRedirects,
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

    public function show(string $post): Response|RedirectResponse
    {
        $currentPost = Post::query()
            ->withTrashed()
            ->where('slug', $post)
            ->first();

        if ($currentPost !== null) {
            abort_unless(
                ! $currentPost->trashed() && $currentPost->isPubliclyPublishedAt(),
                404,
            );
        } else {
            $redirectTarget = $this->slugRedirects->resolvePublic($post);

            abort_if(
                $redirectTarget === null
                    || $redirectTarget->trashed()
                    || ! $redirectTarget->isPubliclyPublishedAt()
                    || hash_equals($post, $redirectTarget->slug),
                404,
            );

            return redirect()
                ->route('posts.show', $redirectTarget, 301)
                ->withHeaders($this->revalidationHeaders());
        }

        $currentPost->load('tags');
        $publishedAt = $currentPost->effectivePublishedAt();
        $connectedMedia = $this->connections->mediaForPost($currentPost);

        return response()->view('posts.show', [
            'post' => $currentPost,
            'connectedMedia' => $connectedMedia,
            'morePosts' => Post::query()->whereKeyNot($currentPost->getKey())->latestPublished()->limit(3)->get(),
            'playerPayload' => $this->media->libraryPlayerPayload(),
            'preview' => false,
            'seo' => [
                'title' => ($currentPost->seo_title ?: $currentPost->title).' | Creative-Ai',
                'description' => $currentPost->seo_description ?: $currentPost->summary,
                'image' => $currentPost->cover_url ? url($currentPost->cover_url) : null,
                'canonical' => route('posts.show', $currentPost),
                'type' => 'article',
                'published_at' => $publishedAt?->toIso8601String(),
                'modified_at' => $currentPost->effectivePublicContentUpdatedAt()?->toIso8601String(),
            ],
            'structured_data' => $this->structuredData->forPost($currentPost, $connectedMedia),
        ])->withHeaders($this->revalidationHeaders());
    }

    /** @return array<string, string> */
    private function revalidationHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
        ];
    }
}
