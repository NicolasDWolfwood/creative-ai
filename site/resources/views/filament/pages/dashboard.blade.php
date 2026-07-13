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

        <section class="creative-admin-panel creative-admin-work-queues" aria-labelledby="studio-work-queues-heading">
            <div class="creative-admin-panel-heading">
                <div>
                    <p class="creative-admin-eyebrow">Operator work queues</p>
                    <h3 id="studio-work-queues-heading">What needs attention</h3>
                </div>
                <p>Counts and safe provenance only. Open the source library for record details.</p>
            </div>

            <div class="creative-admin-work-queue-grid">
                @foreach ($this->getWorkQueues() as $queue)
                    <article class="creative-admin-work-queue creative-admin-work-queue-{{ $queue['tone'] }}">
                        <div class="creative-admin-work-queue-heading">
                            <h4>{{ $queue['label'] }}</h4>
                            <strong aria-label="{{ $queue['label'] }} count">{{ number_format($queue['count']) }}</strong>
                        </div>

                        <p>{{ $queue['reason'] }}</p>

                        <div class="creative-admin-work-queue-meta">
                            @if ($queue['oldest_at'])
                                <span>
                                    {{ $queue['timestamp_label'] }}
                                    <time datetime="{{ $queue['oldest_at']->toIso8601String() }}" title="{{ $queue['oldest_at']->toDayDateTimeString() }}">
                                        {{ $queue['oldest_at']->diffForHumans() }}
                                    </time>
                                </span>
                            @else
                                <span>No open items</span>
                            @endif

                            @if (count($queue['links']) > 1)
                                <span class="creative-admin-work-queue-links">
                                    @foreach ($queue['links'] as $link)
                                        <a href="{{ $link['href'] }}">{{ $link['label'] }}</a>
                                    @endforeach
                                </span>
                            @else
                                <a href="{{ $queue['href'] }}">{{ $queue['action_label'] }}</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
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
