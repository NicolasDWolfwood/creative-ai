<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @php
            $meta = $seo ?? [];
            $metaTitle = $meta['title'] ?? config('app.name', 'Creative-Ai');
            $metaDescription = $meta['description'] ?? 'Generative art and original music by John Reijmer.';
            $canonical = $meta['canonical'] ?? request()->url();
            $structuredData = [
                '@context' => 'https://schema.org',
                '@type' => ($meta['type'] ?? 'website') === 'article' ? 'BlogPosting' : 'WebSite',
                'name' => $metaTitle,
                'description' => $metaDescription,
                'url' => $canonical,
                'image' => $meta['image'] ?? null,
                'author' => ['@type' => 'Person', 'name' => 'John Reijmer'],
            ];
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
        <meta name="robots" content="{{ config('creative_ai.allow_indexing') ? 'index,follow,max-image-preview:large' : 'noindex,nofollow,noarchive' }}">
        <link rel="canonical" href="{{ $canonical }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        <link rel="alternate" type="application/rss+xml" title="Creative-Ai Journal" href="{{ route('feed') }}">
        <meta property="og:site_name" content="Creative-Ai">
        <meta property="og:type" content="{{ $meta['type'] ?? 'website' }}">
        <meta property="og:title" content="{{ $metaTitle }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ $canonical }}">
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
        <script type="application/ld+json">
            @json($structuredData, JSON_UNESCAPED_SLASHES)
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="@yield('body-class', 'public-page')">
        <div class="scroll-progress" data-scroll-progress aria-hidden="true"></div>
        <header class="site-header" aria-label="Primary navigation">
            <a class="brand" href="{{ route('home') }}" aria-label="Creative-Ai home" wire:navigate>
                <span class="brand-mark">CA</span>
                <span><strong>Creative-Ai</strong><small>John Reijmer</small></span>
            </a>
            <button class="nav-toggle icon-button" type="button" data-nav-toggle aria-label="Open navigation" aria-expanded="false">
                <i data-lucide="menu"></i>
            </button>
            <nav class="top-nav" data-nav>
                <a href="{{ route('gallery') }}" wire:navigate>Artwork</a>
                <a href="{{ route('home') }}#collections" wire:navigate>Collections</a>
                <a href="{{ route('music.index') }}" wire:navigate>Music</a>
                <a href="{{ route('posts.index') }}" wire:navigate>Journal</a>
                <a href="{{ route('home') }}#about" wire:navigate>About</a>
                @if ($canAccessAdmin)
                    <a href="{{ $adminPanel->getUrl() }}">Admin</a>
                @endif
            </nav>
        </header>

        <main>@yield('content')</main>

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

        <div class="lightbox" data-lightbox-panel aria-hidden="true" role="dialog" aria-modal="true" aria-label="Artwork viewer">
            <button type="button" class="icon-button lightbox-close" data-lightbox-close aria-label="Close artwork"><i data-lucide="x"></i></button>
            <button type="button" class="icon-button lightbox-prev" data-lightbox-prev aria-label="Previous artwork"><i data-lucide="chevron-left"></i></button>
            <figure>
                <img src="" alt="" data-lightbox-image>
                <figcaption><strong data-lightbox-title></strong><span data-lightbox-description></span></figcaption>
            </figure>
            <button type="button" class="icon-button lightbox-next" data-lightbox-next aria-label="Next artwork"><i data-lucide="chevron-right"></i></button>
        </div>

        @persist('creative-ai-player')
        <aside class="audio-player collapsed" data-player aria-label="Music player">
            <audio data-player-audio preload="metadata"></audio>
            <div class="player-now">
                <div class="player-art" data-player-art></div>
                <button class="player-summary" type="button" data-player-collapse aria-label="Open music player">
                    <strong data-track-title>Listening room</strong>
                    <span data-track-artist>Select an album or playlist</span>
                </button>
                <button class="icon-button play-button" type="button" data-play aria-label="Play"><i data-lucide="play"></i></button>
                <button class="icon-button player-expand" type="button" data-player-collapse aria-label="Expand player"><i data-lucide="chevron-up"></i></button>
            </div>
            <div class="player-body">
                <div class="player-select-row">
                    <select data-playlist-select aria-label="Album or playlist"></select>
                    <input type="range" class="volume" data-volume min="0" max="1" value="0.85" step="0.01" aria-label="Volume">
                </div>
                <canvas class="visualizer" data-visualizer width="680" height="64"></canvas>
                <div class="time-row"><span data-current-time>0:00</span><span data-duration>0:00</span></div>
                <input type="range" class="seek" data-seek min="0" max="100" value="0" step="0.1" aria-label="Seek">
                <div class="player-controls">
                    <button class="icon-button" type="button" data-prev aria-label="Previous track"><i data-lucide="skip-back"></i></button>
                    <button class="icon-button" type="button" data-next aria-label="Next track"><i data-lucide="skip-forward"></i></button>
                    <button class="icon-button" type="button" data-shuffle aria-label="Shuffle"><i data-lucide="shuffle"></i></button>
                    <button class="icon-button" type="button" data-repeat aria-label="Repeat"><i data-lucide="repeat"></i></button>
                </div>
            </div>
        </aside>
        @endpersist

        <script>window.creativeAi = { playlists: @json($playerPayload ?? []) };</script>
    </body>
</html>
