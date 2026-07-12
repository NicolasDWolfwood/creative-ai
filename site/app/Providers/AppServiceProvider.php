<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAdministrator;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use App\Observers\AlbumObserver;
use App\Observers\ArtworkObserver;
use App\Observers\CollectionObserver;
use App\Observers\PlaylistObserver;
use App\Observers\TrackObserver;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $maxUploadSize = (int) config('creative_ai.uploads.max_track_size_kb');

        config()->set('livewire.temporary_file_upload.rules', [
            'required',
            'file',
            'max:'.$maxUploadSize,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::matched(function (RouteMatched $event): void {
            if ($event->route->getName() === 'storage.local') {
                $event->route->middleware(['web', EnsureAdministrator::class]);
            }
        });

        Album::observe(AlbumObserver::class);
        Artwork::observe(ArtworkObserver::class);
        Collection::observe(CollectionObserver::class);
        Playlist::observe(PlaylistObserver::class);
        Track::observe(TrackObserver::class);
    }
}
