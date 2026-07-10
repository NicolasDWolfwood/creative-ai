<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Creative-Ai') }}</title>
        <meta name="description" content="{{ $intro['body'] ?? 'Generative art and music by John Reijmer.' }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ config('app.name', 'Creative-Ai') }}">
        <meta property="og:description" content="{{ $intro['body'] ?? 'Generative art and music by John Reijmer.' }}">
        @if ($heroArtwork)
            <meta property="og:image" content="{{ $heroArtwork->display_url }}">
        @endif
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="site-shell">
            <header class="site-header" aria-label="Primary navigation">
                <a class="brand" href="{{ route('home') }}">
                    <span class="brand-mark">CA</span>
                    <span>
                        <strong>Creative-Ai</strong>
                        <small>John Reijmer</small>
                    </span>
                </a>
                <nav class="top-nav">
                    <a href="{{ route('gallery') }}">Gallery</a>
                    <a href="#music">Music</a>
                    <a href="#about">About</a>
                </nav>
            </header>

            <main>
                <section class="hero" @if ($heroArtwork) style="--hero-image: url('{{ $heroArtwork->display_url }}')" @endif>
                    <div class="hero-copy">
                        <p class="eyebrow">Generative art / AI / music</p>
                        <h1>{{ $selectedCollection?->title ?? ($intro['title'] ?? 'Creative Thoughts') }}</h1>
                        <p>{{ $selectedCollection?->description ?? ($intro['body'] ?? 'A living showcase of generated art and experimental music.') }}</p>
                        <div class="hero-actions">
                            <a href="#gallery" class="button button-primary">Explore work</a>
                            <button class="button button-secondary" type="button" data-player-focus>
                                <i data-lucide="audio-lines"></i>
                                Open player
                            </button>
                        </div>
                    </div>
                    @if ($heroArtwork)
                        <a class="hero-credit" href="#gallery">{{ $heroArtwork->title }}</a>
                    @endif
                </section>

                <section class="section-band collections-band" aria-labelledby="collections-title">
                    <div class="section-inner">
                        <div class="section-heading">
                            <p class="eyebrow">Curated views</p>
                            <h2 id="collections-title">Collections</h2>
                        </div>
                        <div class="collection-strip">
                            <a class="collection-link {{ $selectedCollection ? '' : 'active' }}" href="{{ route('home') }}">
                                <span>All work</span>
                                <small>{{ $collections->sum('artworks_count') }} pieces</small>
                            </a>
                            @foreach ($collections as $collection)
                                <a class="collection-link {{ $selectedCollection?->is($collection) ? 'active' : '' }}" href="{{ route('collections.show', $collection) }}">
                                    <span>{{ $collection->title }}</span>
                                    <small>{{ $collection->artworks_count }} pieces</small>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="gallery-section" id="gallery" aria-labelledby="gallery-title">
                    <div class="section-inner">
                        <div class="section-heading split-heading">
                            <div>
                                <p class="eyebrow">Visual archive</p>
                                <h2 id="gallery-title">{{ $selectedTag ? $selectedTag->name : ($selectedCollection?->title ?? 'Latest artwork') }}</h2>
                            </div>
                            <p>{{ $artworks->count() }} visible pieces</p>
                        </div>

                        @if ($tags->isNotEmpty())
                            <div class="tag-filter-strip" aria-label="Artwork tags">
                                <a class="tag-filter {{ $selectedTag ? '' : 'active' }}" href="{{ request()->url() }}">All tags</a>
                                @foreach ($tags as $tag)
                                    <a class="tag-filter {{ $selectedTag?->is($tag) ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['tag' => $tag->slug]) }}">
                                        {{ $tag->name }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        <div class="art-grid">
                            @forelse ($artworks as $artwork)
                                <button
                                    class="art-tile"
                                    type="button"
                                    data-lightbox
                                    data-title="{{ $artwork->title }}"
                                    data-description="{{ $artwork->description }}"
                                    data-alt="{{ $artwork->image_alt }}"
                                    data-full="{{ $artwork->display_url }}"
                                >
                                    <img src="{{ $artwork->thumb_url }}" alt="{{ $artwork->image_alt }}" loading="lazy">
                                    <span>{{ $artwork->title }}</span>
                                </button>
                            @empty
                                <p class="empty-state">No published artwork yet.</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="section-band music-band" id="music" aria-labelledby="music-title">
                    <div class="section-inner music-layout">
                        <div class="section-heading">
                            <p class="eyebrow">Sound archive</p>
                            <h2 id="music-title">Playlists</h2>
                            <p>Pick a playlist and keep browsing while the player stays with you.</p>
                        </div>
                        <div class="playlist-list">
                            @forelse ($playlists as $playlist)
                                <button class="playlist-row" type="button" data-playlist-id="{{ $playlist->id }}">
                                    <span>
                                        <strong>{{ $playlist->title }}</strong>
                                        <small>{{ $playlist->tracks->count() }} tracks</small>
                                    </span>
                                    <i data-lucide="play"></i>
                                </button>
                            @empty
                                <p class="empty-state">No published playlists yet.</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="about-section" id="about" aria-labelledby="about-title">
                    <div class="section-inner about-grid">
                        <div>
                            <p class="eyebrow">About</p>
                            <h2 id="about-title">Built for a living creative archive</h2>
                        </div>
                        <div class="about-copy">
                            <p>I'm someone who likes to be busy at all times. I dabble in code, AI, art, photography and more. This site showcases freely available generative work and the experiments around it.</p>
                            <div class="social-links">
                                <a href="https://x.com/johnreijmer" target="_blank" rel="noreferrer">X</a>
                                <a href="https://facebook.com/johnreijmer" target="_blank" rel="noreferrer">Facebook</a>
                                <a href="https://instagram.com/johnreijmer" target="_blank" rel="noreferrer">Instagram</a>
                                <a href="https://www.linkedin.com/in/johnreijmer/" target="_blank" rel="noreferrer">LinkedIn</a>
                                <a href="https://www.paypal.com/paypalme/johnreijmer" target="_blank" rel="noreferrer">Support</a>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <footer class="site-footer">
                <span>&copy; {{ date('Y') }} John Reijmer</span>
                <a href="/admin">Admin</a>
            </footer>
        </div>

        <div class="lightbox" data-lightbox-panel aria-hidden="true">
            <button type="button" class="icon-button lightbox-close" data-lightbox-close aria-label="Close artwork">
                <i data-lucide="x"></i>
            </button>
            <img src="" alt="" data-lightbox-image>
            <div class="lightbox-caption">
                <strong data-lightbox-title></strong>
                <span data-lightbox-description></span>
            </div>
        </div>

        <aside class="audio-player" data-player aria-label="Music player">
            <div class="player-now">
                <div class="player-art" data-player-art></div>
                <div>
                    <strong data-track-title>No track selected</strong>
                    <span data-track-artist>Choose a playlist</span>
                </div>
                <button class="icon-button" type="button" data-player-collapse aria-label="Collapse player">
                    <i data-lucide="chevron-down"></i>
                </button>
            </div>
            <div class="player-body">
                <select data-playlist-select aria-label="Playlist"></select>
                <canvas class="visualizer" data-visualizer width="520" height="72"></canvas>
                <div class="time-row">
                    <span data-current-time>0:00</span>
                    <span data-duration>0:00</span>
                </div>
                <input type="range" class="seek" data-seek min="0" max="100" value="0" step="0.1" aria-label="Seek">
                <div class="player-controls">
                    <button class="icon-button" type="button" data-prev aria-label="Previous track"><i data-lucide="skip-back"></i></button>
                    <button class="icon-button play-button" type="button" data-play aria-label="Play"><i data-lucide="play"></i></button>
                    <button class="icon-button" type="button" data-next aria-label="Next track"><i data-lucide="skip-forward"></i></button>
                    <button class="icon-button" type="button" data-shuffle aria-label="Shuffle"><i data-lucide="shuffle"></i></button>
                    <button class="icon-button" type="button" data-repeat aria-label="Repeat"><i data-lucide="repeat"></i></button>
                    <input type="range" class="volume" data-volume min="0" max="1" value="0.85" step="0.01" aria-label="Volume">
                </div>
            </div>
        </aside>

        <script>
            window.creativeAi = {
                playlists: @json($playerPayload),
            };
        </script>
    </body>
</html>
