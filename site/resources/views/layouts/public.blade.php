<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @php
            $meta = $seo ?? [];
            $isPreview = (bool) ($preview ?? false);
            $metaTitle = $meta['title'] ?? config('app.name', 'Creative-Ai');
            $metaDescription = $meta['description'] ?? 'Generative art and original music by John Reijmer.';
            $canonical = $meta['canonical'] ?? request()->url();
            $allowedMetaTypes = ['website', 'article', 'music.album', 'music.song', 'music.playlist'];
            $requestedMetaType = is_string($meta['type'] ?? null) ? $meta['type'] : 'website';
            $metaType = in_array($requestedMetaType, $allowedMetaTypes, true) ? $requestedMetaType : 'website';
            $safeMusicUrl = fn ($url) => is_string($url)
                && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                    ? $url
                    : null;
            $musicDuration = filter_var($meta['music_duration'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $musicAudio = $safeMusicUrl($meta['audio'] ?? null);
            $musicAlbum = $safeMusicUrl($meta['music_album'] ?? null);
            $musicAlbumDisc = filter_var($meta['music_album_disc'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $musicAlbumTrack = filter_var($meta['music_album_track'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $musicReleaseDate = is_string($meta['music_release_date'] ?? null) && filled($meta['music_release_date'])
                ? $meta['music_release_date']
                : null;
            $musicSongs = is_array($meta['music_songs'] ?? null)
                ? collect($meta['music_songs'])
                    ->map($safeMusicUrl)
                    ->filter()
                    ->unique()
                    ->values()
                : collect();
            $defaultStructuredData = [
                '@context' => 'https://schema.org',
                '@type' => $metaType === 'article' ? 'BlogPosting' : 'WebSite',
                'name' => $metaTitle,
                'description' => $metaDescription,
                'url' => $canonical,
                'image' => $meta['image'] ?? null,
                'author' => ['@type' => 'Person', 'name' => 'John Reijmer'],
            ];
            $structuredData = is_array($structured_data ?? null)
                ? $structured_data
                : $defaultStructuredData;
            $adminPanel = \Filament\Facades\Filament::getPanel('admin');
            $authenticatedUser = auth()->user();
            $canAccessAdmin = $authenticatedUser instanceof \Filament\Models\Contracts\FilamentUser
                && $authenticatedUser->canAccessPanel($adminPanel);
        @endphp
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $metaTitle }}</title>
        <meta name="description" content="{{ $metaDescription }}">
        <meta name="robots" content="{{ ! $isPreview && config('creative_ai.allow_indexing') ? 'index,follow,max-image-preview:large' : 'noindex,nofollow,noarchive' }}">
        @unless ($isPreview)
            <link rel="canonical" href="{{ $canonical }}">
        @endunless
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        @unless ($isPreview)
            <link rel="alternate" type="application/rss+xml" title="Creative-Ai Journal" href="{{ route('feed') }}">
        @endunless
        <meta property="og:site_name" content="Creative-Ai">
        <meta property="og:type" content="{{ $metaType }}">
        <meta property="og:title" content="{{ $metaTitle }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        @unless ($isPreview)
            <meta property="og:url" content="{{ $canonical }}">
        @endunless
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $metaTitle }}">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        @if (! empty($meta['image']))
            <meta property="og:image" content="{{ $meta['image'] }}">
            <meta name="twitter:image" content="{{ $meta['image'] }}">
        @endif
        @if (! empty($meta['published_at']))
            <meta property="article:published_time" content="{{ $meta['published_at'] }}">
        @endif
        @if (! empty($meta['modified_at']))
            <meta property="article:modified_time" content="{{ $meta['modified_at'] }}">
        @endif
        @if (str_starts_with($metaType, 'music.'))
            @if ($musicAudio)
                <meta property="og:audio" content="{{ $musicAudio }}">
            @endif
            @if ($musicDuration)
                <meta property="music:duration" content="{{ $musicDuration }}">
            @endif
            @if ($musicAlbum)
                <meta property="music:album" content="{{ $musicAlbum }}">
            @endif
            @if ($musicAlbumDisc)
                <meta property="music:album:disc" content="{{ $musicAlbumDisc }}">
            @endif
            @if ($musicAlbumTrack)
                <meta property="music:album:track" content="{{ $musicAlbumTrack }}">
            @endif
            @if ($musicReleaseDate)
                <meta property="music:release_date" content="{{ $musicReleaseDate }}">
            @endif
            @foreach ($musicSongs as $musicSong)
                <meta property="music:song" content="{{ $musicSong }}">
            @endforeach
        @endif
        @unless ($isPreview)
            <script type="application/ld+json">
                {!! Illuminate\Support\Js::encode($structuredData) !!}
            </script>
        @endunless
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="@yield('body-class', 'public-page')">
        <a class="skip-link" href="#main-content">Skip to content</a>
        <div class="scroll-progress" data-scroll-progress aria-hidden="true"></div>
        <header class="site-header" aria-label="Primary navigation">
            <a class="brand" href="{{ route('home') }}" aria-label="Creative-Ai home" wire:navigate>
                <span class="brand-mark">CA</span>
                <span><strong>Creative-Ai</strong><small>John Reijmer</small></span>
            </a>
            <button class="nav-toggle icon-button" type="button" data-nav-toggle aria-label="Open navigation" aria-expanded="false" aria-controls="primary-navigation">
                <i data-lucide="menu"></i>
            </button>
            <nav class="top-nav" id="primary-navigation" data-nav>
                <a href="{{ route('gallery') }}" @if (request()->routeIs('gallery', 'collections.show')) aria-current="page" @endif wire:navigate>Artwork</a>
                <a href="{{ route('home') }}#collections" wire:navigate>Collections</a>
                <a href="{{ route('music.index') }}" @if (request()->routeIs('music.*')) aria-current="page" @endif wire:navigate>Music</a>
                <a href="{{ route('posts.index') }}" @if (request()->routeIs('posts.*')) aria-current="page" @endif wire:navigate>Journal</a>
                <a href="{{ route('home') }}#about" wire:navigate>About</a>
                @if ($canAccessAdmin)
                    <a href="{{ $adminPanel->getUrl() }}">Admin</a>
                @endif
            </nav>
        </header>

        <main id="main-content" tabindex="-1">@yield('content')</main>

        <footer class="site-footer">
            <div class="footer-brand"><strong>Creative-Ai</strong><span>A living archive by John Reijmer.</span></div>
            <nav aria-label="Footer navigation">
                <a href="{{ route('posts.index') }}" wire:navigate>Journal</a>
                <a href="{{ route('feed') }}">RSS</a>
                @if ($canAccessAdmin)
                    <a href="{{ $adminPanel->getUrl() }}">Admin</a>
                @endif
            </nav>
            <span>&copy; {{ date('Y') }}</span>
        </footer>

        <div class="lightbox" data-lightbox-panel aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="lightbox-title" aria-describedby="lightbox-description">
            <button type="button" class="icon-button lightbox-close" data-lightbox-close aria-label="Close artwork"><i data-lucide="x"></i></button>
            <button type="button" class="icon-button lightbox-prev" data-lightbox-prev aria-label="Previous artwork"><i data-lucide="chevron-left"></i></button>
            <figure>
                <img src="" alt="" data-lightbox-image>
                <figcaption><strong id="lightbox-title" data-lightbox-title></strong><span id="lightbox-description" data-lightbox-description></span></figcaption>
            </figure>
            <button type="button" class="icon-button lightbox-next" data-lightbox-next aria-label="Next artwork"><i data-lucide="chevron-right"></i></button>
        </div>

        @persist('creative-ai-player')
        <aside class="audio-player collapsed" data-player aria-label="Music player">
            <audio data-player-audio preload="metadata"></audio>
            <div class="player-now">
                <div class="player-art" data-player-art></div>
                <button class="player-summary" type="button" data-player-collapse aria-label="Expand music player" aria-expanded="false">
                    <strong data-track-title>Listening room</strong>
                    <span data-track-artist>Select an album or playlist</span>
                </button>
                <button class="icon-button play-button" type="button" data-play aria-label="Play"><i data-lucide="play"></i></button>
                <button class="icon-button player-expand" type="button" data-player-collapse aria-label="Expand music player" aria-expanded="false"><i data-lucide="chevron-up"></i></button>
            </div>
            <div class="player-body">
                <div class="player-select-row">
                    <select data-playlist-select aria-label="Album or playlist"></select>
                    <input type="range" class="volume" data-volume min="0" max="1" value="0.85" step="0.01" aria-label="Volume">
                </div>
                <canvas class="visualizer" data-visualizer width="680" height="64" aria-hidden="true"></canvas>
                <div class="time-row"><span data-current-time>0:00</span><span data-duration>0:00</span></div>
                <input type="range" class="seek" data-seek min="0" max="100" value="0" step="0.1" aria-label="Seek">
                <div class="player-controls">
                    <button class="icon-button" type="button" data-prev aria-label="Previous track"><i data-lucide="skip-back"></i></button>
                    <button class="icon-button" type="button" data-next aria-label="Next track"><i data-lucide="skip-forward"></i></button>
                    <button class="icon-button" type="button" data-shuffle aria-label="Shuffle" aria-pressed="false"><i data-lucide="shuffle"></i></button>
                    <button class="icon-button" type="button" data-repeat aria-label="Repeat" aria-pressed="false"><i data-lucide="repeat"></i></button>
                </div>
                <p class="player-status" data-player-status aria-live="polite" aria-atomic="true"></p>
            </div>
        </aside>
        @endpersist

        <script>window.creativeAi = { playlists: @json($playerPayload ?? []) };</script>
    </body>
</html>
