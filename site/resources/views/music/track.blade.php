@extends('layouts.public')

@section('body-class', 'public-page track-detail-page')

@section('content')
<article class="section-shell track-page">
    <a class="text-link album-back-link" href="{{ route('music.index') }}" wire:navigate>All music</a>

    <header class="track-header">
        <span class="eyebrow">Track</span>
        <h1>{{ $track->title }}</h1>
        @if ($track->artist || $track->album?->isPubliclyPublished())
            <p class="track-byline">
                @if ($track->artist){{ $track->artist }}@endif
                @if ($track->album?->isPubliclyPublished())
                    @if ($track->artist) · @endif<a href="{{ route('music.albums.show', $track->album) }}" wire:navigate>{{ $track->album->title }}</a>
                @endif
            </p>
        @endif
        @if ($track->description)
            <p class="track-description">{{ $track->description }}</p>
        @endif
        @include('partials.public-tags', ['tags' => $musicTags, 'label' => 'Track tags'])
        <button class="button button-primary" type="button" data-playlist-id="track-{{ $track->id }}" aria-label="Play {{ $track->title }}"><i data-lucide="play"></i>Play track</button>
    </header>

    @if (isset($playlists) && $playlists->isNotEmpty())
        <section class="track-connections" aria-labelledby="track-playlists-title">
            <header class="music-library-heading">
                <div><p class="eyebrow">Keep listening</p><h2 id="track-playlists-title">Playlists featuring this track</h2></div>
                <p>Continue with a public listening sequence that includes this piece.</p>
            </header>
            <ul class="artwork-link-list" aria-label="Public playlists containing {{ $track->title }}">
                @foreach ($playlists as $playlist)
                    <li><a href="{{ route('music.playlists.show', $playlist) }}" wire:navigate>{{ $playlist->title }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($artworks->isNotEmpty())
        <section class="track-artwork-section" aria-labelledby="track-artwork-title">
            <header class="music-library-heading">
                <div><p class="eyebrow">Connected artwork</p><h2 id="track-artwork-title">Artwork for this sound</h2></div>
                <p>Explore the visual work connected to this track.</p>
            </header>
            <div class="track-artwork-grid">
                @foreach ($artworks as $artwork)
                    <a class="track-artwork-card" href="{{ route('artworks.show', $artwork) }}" wire:navigate>
                        <img src="{{ $artwork->thumb_url }}" alt="{{ $artwork->image_alt }}" loading="lazy">
                        <span><strong>{{ $artwork->title }}</strong><small>View artwork</small></span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</article>
@include('partials.connected-stories', ['stories' => $stories, 'headingId' => 'track-stories-title'])
@endsection
