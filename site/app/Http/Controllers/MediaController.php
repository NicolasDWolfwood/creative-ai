<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Post;
use App\Models\Track;
use App\Services\PrivateMediaService;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function homepageArtworkDisplay(Artwork $artwork, PrivateMediaService $media): BinaryFileResponse
    {
        abort_unless($artwork->isHomepageHeroEligible(), 404);
        $path = $artwork->availableDisplayPath();
        abort_if(blank($path), 404);

        // Homepage eligibility can be withdrawn independently of standalone
        // publication. Always revalidate this narrow public grant.
        return $this->file($media, $path, public: true, revalidatePublic: true);
    }

    public function artwork(Artwork $artwork, string $variant, Request $request, PrivateMediaService $media): BinaryFileResponse
    {
        $public = $artwork->isPubliclyAvailable();
        abort_unless($public || $this->isAdministrator($request), 404);
        $path = match ($variant) {
            'display' => $artwork->availableDisplayPath(),
            'thumb' => $artwork->availableThumbPath(),
            default => $artwork->image_path,
        };
        abort_if(blank($path), 404);

        return $this->file(
            $media,
            $path,
            $public,
            revalidatePublic: $public && ! $artwork->isPubliclyPublished(),
        );
    }

    public function trackAudio(Track $track, Request $request, PrivateMediaService $media): BinaryFileResponse
    {
        $public = $track->isPubliclyAvailable();
        abort_unless($public || $this->isAdministrator($request), 404);
        abort_if(blank($track->audio_path), 404);

        return $this->file($media, $track->audio_path, $public, revalidatePublic: true);
    }

    public function albumEmbeddedCover(Album $album, Request $request, PrivateMediaService $media): BinaryFileResponse
    {
        abort_unless($album->isPubliclyPublished() || $this->isAdministrator($request), 404);
        abort_if(blank($album->embedded_cover_path), 404);

        return $this->file($media, $album->embedded_cover_path, $album->isPubliclyPublished());
    }

    public function postCover(Post $post, Request $request, PrivateMediaService $media): BinaryFileResponse
    {
        $published = Post::query()->published()->whereKey($post)->exists();
        abort_unless($published || $this->isAdministrator($request), 404);
        abort_if(blank($post->cover_image_path), 404);

        return $this->file($media, $post->cover_image_path, $published, revalidatePublic: true);
    }

    protected function file(PrivateMediaService $media, string $path, bool $public, bool $revalidatePublic = false): BinaryFileResponse
    {
        $absolutePath = $media->absolutePath($path);
        abort_unless(is_file($absolutePath), 404);

        $response = response()->file($absolutePath, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($public && $revalidatePublic) {
            // Access can change when a parent album or collection is
            // unpublished. Require browsers to revalidate before reuse so a
            // previously cached response cannot bypass the current policy.
            $response->setPrivate();
            $response->setMaxAge(0);
            $response->headers->addCacheControlDirective('no-cache', true);
            $response->headers->addCacheControlDirective('must-revalidate', true);
        } elseif ($public) {
            $response->setPublic();
            $response->setMaxAge(86400);
        } else {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store', true);
        }

        return $response;
    }

    protected function isAdministrator(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof FilamentUser
            && $user->canAccessPanel(Filament::getPanel('admin'));
    }
}
