@extends('layouts.public')

@section('body-class', 'public-page artwork-detail-page')

@section('content')
<article class="section-shell artwork-page">
    <a class="text-link artwork-back-link" href="{{ route('gallery') }}#gallery" wire:navigate>All artwork</a>

    <header class="artwork-header">
        <span class="eyebrow">Artwork</span>
        <h1>{{ $artwork->title }}</h1>
        @if ($artwork->description)
            <p>{{ $artwork->description }}</p>
        @endif
    </header>

    <figure class="artwork-figure">
        <img
            src="{{ $artwork->display_url }}"
            alt="{{ $artwork->image_alt }}"
            @if ($artwork->width) width="{{ $artwork->width }}" @endif
            @if ($artwork->height) height="{{ $artwork->height }}" @endif
        >
        <figcaption>
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
        </figcaption>
    </figure>

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
                                    <li>{{ $tag->name }}</li>
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

    <nav class="artwork-neighbors" aria-label="Artwork archive navigation">
        @if ($previousArtwork)
            <a class="artwork-neighbor artwork-neighbor-previous" href="{{ route('artworks.show', $previousArtwork) }}" rel="prev" wire:navigate>
                <span>Previous artwork</span>
                <strong>{{ $previousArtwork->title }}</strong>
            </a>
        @endif
        @if ($nextArtwork)
            <a class="artwork-neighbor artwork-neighbor-next" href="{{ route('artworks.show', $nextArtwork) }}" rel="next" wire:navigate>
                <span>Next artwork</span>
                <strong>{{ $nextArtwork->title }}</strong>
            </a>
        @endif
    </nav>

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
@endsection
