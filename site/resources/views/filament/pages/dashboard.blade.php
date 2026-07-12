<x-filament-panels::page>
    <div class="creative-admin-dashboard">
        <section class="creative-admin-hero">
            <div>
                <p class="creative-admin-eyebrow">Creative-Ai control room</p>
                <h2>Shape the gallery and soundtrack from one place.</h2>
                <p>
                    Keep the public showcase alive with new visuals, music, playlists, and collection edits.
                </p>
            </div>
            <div class="creative-admin-hero-actions">
                <a href="{{ \App\Filament\Resources\Artworks\ArtworkResource::getUrl() }}">Manage art</a>
                <a href="{{ \App\Filament\Resources\Tracks\TrackResource::getUrl() }}">Manage music</a>
            </div>
        </section>

        <section class="creative-admin-stats" aria-label="Content overview">
            @foreach ($this->getStats() as $stat)
                <a class="creative-admin-stat creative-admin-stat-{{ $stat['accent'] }}" href="{{ $stat['href'] }}">
                    <span>{{ $stat['label'] }}</span>
                    <strong>{{ number_format($stat['value']) }}</strong>
                </a>
            @endforeach
        </section>

        <section class="creative-admin-grid">
            <div class="creative-admin-panel creative-admin-panel-wide">
                <div class="creative-admin-panel-heading">
                    <div>
                        <p class="creative-admin-eyebrow">Recently published</p>
                        <h3>Latest visuals</h3>
                    </div>
                    <a href="{{ \App\Filament\Resources\Artworks\ArtworkResource::getUrl() }}">Open gallery</a>
                </div>

                <div class="creative-admin-art-strip">
                    @forelse ($this->getFeaturedArtworks() as $artwork)
                        <a href="{{ \App\Filament\Resources\Artworks\ArtworkResource::getUrl() }}" aria-label="{{ $artwork->title }}">
                            <img src="{{ $artwork->thumb_url }}" alt="{{ $artwork->title }}" loading="lazy">
                        </a>
                    @empty
                        <p class="creative-admin-empty">No artwork has been published yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="creative-admin-panel">
                <div class="creative-admin-panel-heading">
                    <div>
                        <p class="creative-admin-eyebrow">Recently published</p>
                        <h3>Latest tracks</h3>
                    </div>
                    <a href="{{ \App\Filament\Resources\Playlists\PlaylistResource::getUrl() }}">Playlists</a>
                </div>

                <div class="creative-admin-track-list">
                    @forelse ($this->getRecentTracks() as $track)
                        <a href="{{ \App\Filament\Resources\Tracks\TrackResource::getUrl() }}">
                            <span>{{ $track->title }}</span>
                            <small>{{ $track->artist ?: 'Creative-Ai' }}</small>
                        </a>
                    @empty
                        <p class="creative-admin-empty">No standalone tracks have been published yet.</p>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
