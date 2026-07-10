<?php

namespace App\Providers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Observers\ArtworkObserver;
use App\Observers\CollectionObserver;
use App\Observers\PlaylistObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Artwork::observe(ArtworkObserver::class);
        Collection::observe(CollectionObserver::class);
        Playlist::observe(PlaylistObserver::class);
    }
}
