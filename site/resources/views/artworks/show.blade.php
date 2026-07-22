@extends('layouts.public')

@section('body-class', 'public-page artwork-detail-page')

@section('content')
<article class="section-shell artwork-page">
    <div class="artwork-viewer-intro">
        <div class="artwork-context-row">
            @if ($collectionContext)
                <a class="text-link artwork-back-link" href="{{ route('collections.show', $collectionContext) }}#gallery" wire:navigate>Back to {{ $collectionContext->title }}</a>
                <span class="artwork-sequence-label">Browsing {{ $collectionContext->title }} · Use ← → keys</span>
            @else
                <a class="text-link artwork-back-link" href="{{ route('gallery') }}#gallery" wire:navigate>Back to all artwork</a>
                <span class="artwork-sequence-label">Browsing all published artwork · Use ← → keys</span>
            @endif
        </div>

        <section
            class="artwork-viewer"
            id="artwork-viewer"
            data-artwork-viewer
            tabindex="-1"
            aria-label="Artwork viewer for {{ $artwork->title }}"
        >
            <div class="artwork-browser-grid">
                <figure class="artwork-figure">
                    <img
                        src="{{ $artwork->display_url }}"
                        alt="{{ $artwork->image_alt }}"
                        @if ($artwork->width) width="{{ $artwork->width }}" @endif
                        @if ($artwork->height) height="{{ $artwork->height }}" @endif
                    >
                </figure>

                <nav class="artwork-browser-navigation" aria-label="{{ $collectionContext ? 'Browse '.$collectionContext->title : 'Browse all published artwork' }}">
                    @if ($previousArtwork)
                        <a
                            class="artwork-browser-control artwork-browser-previous"
                            href="{{ route('artworks.show', $collectionContext ? ['artwork' => $previousArtwork, 'collection' => $collectionContext->slug] : $previousArtwork) }}"
                            rel="prev"
                            data-artwork-previous
                            aria-label="Previous artwork: {{ $previousArtwork->title }}"
                            aria-keyshortcuts="ArrowLeft"
                            wire:navigate
                        >
                            <i data-lucide="chevron-left" aria-hidden="true"></i>
                            <span>Previous artwork</span>
                            <strong>{{ $previousArtwork->title }}</strong>
                        </a>
                    @else
                        <div class="artwork-browser-control artwork-browser-previous is-unavailable" data-artwork-boundary="start" aria-disabled="true">
                            <i data-lucide="chevron-left" aria-hidden="true"></i>
                            <span>Start of {{ $collectionContext ? 'collection' : 'published archive' }}</span>
                            <strong>No previous artwork</strong>
                        </div>
                    @endif

                    @if ($nextArtwork)
                        <a
                            class="artwork-browser-control artwork-browser-next"
                            href="{{ route('artworks.show', $collectionContext ? ['artwork' => $nextArtwork, 'collection' => $collectionContext->slug] : $nextArtwork) }}"
                            rel="next"
                            data-artwork-next
                            aria-label="Next artwork: {{ $nextArtwork->title }}"
                            aria-keyshortcuts="ArrowRight"
                            wire:navigate
                        >
                            <i data-lucide="chevron-right" aria-hidden="true"></i>
                            <span>Next artwork</span>
                            <strong>{{ $nextArtwork->title }}</strong>
                        </a>
                    @else
                        <div class="artwork-browser-control artwork-browser-next is-unavailable" data-artwork-boundary="end" aria-disabled="true">
                            <i data-lucide="chevron-right" aria-hidden="true"></i>
                            <span>End of {{ $collectionContext ? 'collection' : 'published archive' }}</span>
                            <strong>No next artwork</strong>
                        </div>
                    @endif
                </nav>
            </div>
        </section>
    </div>

    <div class="artwork-detail-copy">
        <header class="artwork-header" data-artwork-details>
            <span class="eyebrow">Artwork</span>
            <h1>{{ $artwork->title }}</h1>
            @if ($artwork->description)
                <p>{{ $artwork->description }}</p>
            @endif
        </header>

        <div class="artwork-technical-meta" aria-label="Artwork image details">
            <span>
                @if ($artwork->generated_at)
                    Created {{ $artwork->generated_at->format('F j, Y') }}
                @endif
                @if ($artwork->generated_at && $artwork->width && $artwork->height)
                    ·
                @endif
                @if ($artwork->width && $artwork->height)
                    {{ number_format($artwork->width) }} × {{ number_format($artwork->height) }} px
                @endif
            </span>
            <a class="text-link" href="{{ $artwork->public_image_url }}">View full resolution</a>
        </div>
    </div>

    @if ($artwork->collections->isNotEmpty() || $artwork->prompt || $artwork->process_notes || $artwork->tags->isNotEmpty())
        <div class="artwork-story-grid">
            @if ($artwork->collections->isNotEmpty() || $artwork->tags->isNotEmpty())
                <aside class="artwork-taxonomy" aria-label="Artwork context">
                    @if ($artwork->collections->isNotEmpty())
                        <section aria-labelledby="artwork-collections-title">
                            <p class="eyebrow" id="artwork-collections-title">Collections</p>
                            <div class="artwork-link-list">
                                @foreach ($artwork->collections as $collection)
                                    <a href="{{ route('collections.show', $collection) }}#gallery" wire:navigate>{{ $collection->title }}</a>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($artwork->tags->isNotEmpty())
                        <section aria-labelledby="artwork-tags-title">
                            <p class="eyebrow" id="artwork-tags-title">Tags</p>
                            <ul class="artwork-tag-list">
                                @foreach ($artwork->tags as $tag)
                                    <li><a href="{{ route('tags.show', $tag) }}" wire:navigate>{{ $tag->name }}</a></li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </aside>
            @endif

            <div class="artwork-notes">
                @if ($artwork->prompt)
                    <section aria-labelledby="artwork-prompt-title">
                        <p class="eyebrow" id="artwork-prompt-title">Prompt</p>
                        <p>{{ $artwork->prompt }}</p>
                    </section>
                @endif

                @if ($artwork->process_notes)
                    <section aria-labelledby="artwork-process-title">
                        <p class="eyebrow" id="artwork-process-title">Process notes</p>
                        <p>{{ $artwork->process_notes }}</p>
                    </section>
                @endif
            </div>
        </div>
    @endif

    @if ($tracks->isNotEmpty())
        <section class="artwork-music-section" aria-labelledby="artwork-music-title">
            <header class="music-library-heading">
                <div><p class="eyebrow">Connected listening</p><h2 id="artwork-music-title">Music for this artwork</h2></div>
                <p>{{ $recommendationDescription }}</p>
            </header>
            <div><button class="button button-primary" type="button" data-playlist-id="{{ $recommendationPlaylistId }}" aria-label="Play music for {{ $artwork->title }}">Play recommendations</button></div>
            <div class="track-list">
                @foreach ($tracks as $track)
                    <article>
                        <button class="icon-button" type="button" data-play-track-id="{{ $track->id }}" data-track-source-id="{{ $recommendationPlaylistId }}" aria-label="Play {{ $track->title }}">
                            <i data-lucide="play"></i>
                        </button>
                        <div>
                            <a href="{{ route('music.tracks.show', $track) }}" wire:navigate><strong>{{ $track->title }}</strong></a>
                            @if ($track->artist)<span>{{ $track->artist }}</span>@endif
                        </div>
                        <button class="button button-secondary" type="button" data-queue-track-id="{{ $track->id }}" data-track-source-id="{{ $recommendationPlaylistId }}" aria-label="Add {{ $track->title }} to queue">Queue</button>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</article>

@include('partials.connected-stories', ['stories' => $stories, 'headingId' => 'artwork-stories-title'])
@endsection
