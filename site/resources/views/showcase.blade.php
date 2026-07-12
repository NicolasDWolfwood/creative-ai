@extends('layouts.public')

@section('body-class', 'showcase-page')

@section('content')
    <section class="hero" @if ($heroArtwork) style="--hero-image: url('{{ $heroArtwork->display_url }}')" @endif>
        <div class="hero-shade" aria-hidden="true"></div>
        <div class="hero-copy" data-reveal>
            <p class="eyebrow">Generative artwork · original sound · experiments</p>
            <h1>Creative-Ai</h1>
            @if ($selectedCollection || $selectedTag)
                <p class="hero-edition">{{ $selectedCollection?->title ?? ucfirst($selectedTag->name) }}</p>
            @endif
            <p class="hero-intro">{{ $selectedCollection?->description ?? ($intro['body'] ?? 'A living archive of generated art and experimental music.') }}</p>
            <div class="hero-actions">
                <a href="#gallery" class="button button-primary"><i data-lucide="move-down"></i>View artwork</a>
                <button class="button button-secondary" type="button" data-player-focus><i data-lucide="audio-lines"></i>Listening room</button>
            </div>
        </div>
        @if ($heroArtwork)
            <button class="hero-credit" type="button" data-lightbox data-title="{{ $heroArtwork->title }}" data-description="{{ $heroArtwork->description }}" data-alt="{{ $heroArtwork->image_alt }}" data-full="{{ $heroArtwork->display_url }}">
                <span>Featured frame</span><strong>{{ $heroArtwork->title }}</strong>
            </button>
        @endif
    </section>

    <section class="archive-signal" aria-label="Archive overview">
        <div><strong>{{ number_format($totalArtworkCount) }}</strong><span>published works</span></div>
        <div><strong>{{ number_format($collections->count()) }}</strong><span>visual collections</span></div>
        <div><strong>{{ number_format($playlists->sum(fn ($playlist) => $playlist->tracks->count())) }}</strong><span>original tracks</span></div>
        <p>Made through curiosity, iteration, and a willingness to follow unexpected results.</p>
    </section>

    @if ($collections->isNotEmpty())
        <section class="collections-section" id="collections" aria-labelledby="collections-title">
            <div class="section-inner">
                <header class="section-heading split-heading" data-reveal>
                    <div><p class="eyebrow">Curated worlds</p><h2 id="collections-title">Collections</h2></div>
                    <a class="text-link" href="{{ route('gallery') }}" wire:navigate>Explore the full archive <i data-lucide="arrow-up-right"></i></a>
                </header>
                <div class="collection-grid">
                    @foreach ($collections as $collection)
                        @php($coverArtwork = $collectionCovers->get($collection->getKey()))
                        @php($cover = $coverArtwork?->thumb_url ?: $collectionCoverPlaceholder)
                        <a class="collection-tile {{ $selectedCollection?->is($collection) ? 'active' : '' }}" href="{{ route('collections.show', $collection) }}" data-reveal wire:navigate>
                            <img src="{{ $cover }}" alt="" loading="lazy" @if ($coverArtwork) data-cover-artwork-id="{{ $coverArtwork->getKey() }}" @else data-collection-cover-placeholder @endif>
                            <span><strong>{{ $collection->title }}</strong><small>{{ $collection->artworks_count }} works</small></span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="gallery-section" id="gallery" aria-labelledby="gallery-title">
        <div class="section-inner section-inner-wide">
            <header class="section-heading split-heading" data-reveal>
                <div><p class="eyebrow">Visual archive</p><h2 id="gallery-title">{{ $selectedTag ? ucfirst($selectedTag->name) : ($selectedCollection?->title ?? 'Recent work') }}</h2></div>
                <p>{{ $artworks->count() }} frames in this view</p>
            </header>

            @if ($tags->isNotEmpty())
                <nav class="tag-filter-strip" aria-label="Artwork tags" data-reveal>
                    <a class="tag-filter {{ $selectedTag ? '' : 'active' }}" href="{{ request()->url() }}" wire:navigate>All</a>
                    @foreach ($tags as $tag)
                        <a class="tag-filter {{ $selectedTag?->is($tag) ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['tag' => $tag->slug]) }}" wire:navigate>{{ $tag->name }}<span>{{ $tag->artworks_count }}</span></a>
                    @endforeach
                </nav>
            @endif

            <div class="art-grid">
                @forelse ($artworks as $artwork)
                    <button class="art-tile" type="button" data-lightbox data-title="{{ $artwork->title }}" data-description="{{ $artwork->description }}" data-alt="{{ $artwork->image_alt }}" data-full="{{ $artwork->display_url }}" data-reveal>
                        <img src="{{ $artwork->thumb_url }}" alt="{{ $artwork->image_alt }}" loading="lazy" width="{{ $artwork->width ?: 720 }}" height="{{ $artwork->height ?: 900 }}">
                        <span><strong>{{ $artwork->title }}</strong><small>{{ $artwork->tags->take(2)->pluck('name')->implode(' · ') }}</small></span>
                    </button>
                @empty
                    <p class="empty-state">No published artwork yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="music-section" id="music" aria-labelledby="music-title">
        <div class="section-inner music-layout">
            <header class="section-heading" data-reveal>
                <p class="eyebrow">Listening room</p>
                <h2 id="music-title">Soundtracks for imagined places</h2>
                <p>Original pieces and generated sound experiments grouped into listening sessions.</p>
                <button class="button button-primary" type="button" data-player-focus><i data-lucide="headphones"></i>Open player</button>
            </header>
            <div class="playlist-list">
                @foreach ($albums as $album)
                    <button class="playlist-row" type="button" data-playlist-id="album-{{ $album->id }}" data-reveal>
                        <span class="playlist-cover" @if ($album->cover_url) style="background-image:url('{{ $album->cover_url }}')" @endif><i data-lucide="disc-3"></i></span>
                        <span><strong>{{ $album->title }}</strong><small>Album · {{ $album->tracks->count() }} tracks{{ $album->artist ? ' · '.$album->artist : '' }}</small></span>
                        <i data-lucide="play"></i>
                    </button>
                @endforeach
                @forelse ($playlists as $playlist)
                    <button class="playlist-row" type="button" data-playlist-id="playlist-{{ $playlist->id }}" data-reveal>
                        <span class="playlist-cover" @if ($playlist->cover_url) style="background-image:url('{{ $playlist->cover_url }}')" @endif><i data-lucide="audio-waveform"></i></span>
                        <span><strong>{{ $playlist->title }}</strong><small>{{ $playlist->tracks->count() }} tracks · {{ $playlist->description ?: 'Creative-Ai session' }}</small></span>
                        <i data-lucide="play"></i>
                    </button>
                @empty
                    @if ($albums->isEmpty())<p class="empty-state">No published albums or playlists yet.</p>@endif
                @endforelse
            </div>
        </div>
    </section>

    @if ($posts->isNotEmpty())
        <section class="journal-section" aria-labelledby="journal-title">
            <div class="section-inner">
                <header class="section-heading split-heading" data-reveal>
                    <div><p class="eyebrow">From the studio</p><h2 id="journal-title">Journal</h2></div>
                    <a class="text-link" href="{{ route('posts.index') }}" wire:navigate>All updates <i data-lucide="arrow-up-right"></i></a>
                </header>
                <div class="post-grid">
                    @foreach ($posts as $post)
                        <article class="post-teaser" data-reveal>
                            @if ($post->cover_url)<a href="{{ route('posts.show', $post) }}" wire:navigate><img src="{{ $post->cover_url }}" alt="" loading="lazy"></a>@endif
                            <div><time datetime="{{ $post->published_at?->toDateString() }}">{{ $post->published_at?->format('M j, Y') }}</time><h3><a href="{{ route('posts.show', $post) }}" wire:navigate>{{ $post->title }}</a></h3><p>{{ $post->summary }}</p></div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="about-section" id="about" aria-labelledby="about-title">
        <div class="section-inner about-grid">
            <div data-reveal><p class="eyebrow">Behind the archive</p><h2 id="about-title">Ideas move between image, code, and sound.</h2></div>
            <div class="about-copy" data-reveal>
                <p>I'm John Reijmer. I work across generative art, photography, code, AI, and music. Creative-Ai is where those experiments become a public, evolving body of work.</p>
                <nav class="social-links" aria-label="Social links">
                    <a href="https://x.com/johnreijmer" target="_blank" rel="noreferrer">X <i data-lucide="arrow-up-right"></i></a>
                    <a href="https://instagram.com/johnreijmer" target="_blank" rel="noreferrer">Instagram <i data-lucide="arrow-up-right"></i></a>
                    <a href="https://www.paypal.com/paypalme/johnreijmer" target="_blank" rel="noreferrer">Support <i data-lucide="heart"></i></a>
                </nav>
            </div>
        </div>
    </section>
@endsection
