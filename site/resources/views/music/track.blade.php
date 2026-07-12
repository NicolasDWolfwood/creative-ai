@extends('layouts.public')
@section('content')
<section class="section-shell">
    <span class="eyebrow">Track</span>
    <h1>{{ $track->title }}</h1>
    <p>
        {{ $track->artist }}
        @if ($track->album?->isPubliclyPublished())
            ·
            <a href="{{ route('music.albums.show', $track->album) }}" wire:navigate>{{ $track->album->title }}</a>
        @endif
    </p>
    <button class="button" type="button" data-playlist-id="track-{{ $track->id }}" aria-label="Play {{ $track->title }}">Play track</button>
    <p>{{ $track->description }}</p>
    @if ($artworks->isNotEmpty())
        <h2>Artwork for this sound</h2>
        <div class="gallery-grid">
            @foreach ($artworks as $artwork)
                <figure class="gallery-card"><img src="{{ $artwork->thumb_url }}" alt="{{ $artwork->alt_text ?: $artwork->title }}"><figcaption>{{ $artwork->title }}</figcaption></figure>
            @endforeach
        </div>
    @endif
</section>
@endsection
