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
    public function artwork(Artwork $artwork, string $variant, Request $request, PrivateMediaService $media): BinaryFileResponse
    {
        abort_unless($artwork->isPubliclyPublished() || $this->isAdministrator($request), 404);
        $path = match ($variant) {
            'display' => $artwork->availableDisplayPath(),
            'thumb' => $artwork->availableThumbPath(),
            default => $artwork->image_path,
        };
        abort_if(blank($path), 404);

        return $this->file($media, $path, $artwork->isPubliclyPublished());
    }

    public function trackAudio(Track $track, Request $request, PrivateMediaService $media): BinaryFileResponse
    {
        abort_unless($track->isPubliclyPublished() || $this->isAdministrator($request), 404);
        abort_if(blank($track->audio_path), 404);

        return $this->file($media, $track->audio_path, $track->isPubliclyPublished());
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

        return $this->file($media, $post->cover_image_path, $published);
    }

    protected function file(PrivateMediaService $media, string $path, bool $public): BinaryFileResponse
    {
        $absolutePath = $media->absolutePath($path);
        abort_unless(is_file($absolutePath), 404);

        $response = response()->file($absolutePath, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($public) {
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
