@extends('layouts.public')
@section('content')
@php
    $albumDuration = (int) $album->tracks->sum('duration_seconds');
@endphp
<section class="section-shell album-page">
    <a class="text-link album-back-link" href="{{ route('music.index') }}" wire:navigate>All music</a>
    <header class="album-hero">
        @if ($album->cover_url)
            <img class="music-hero-cover" src="{{ $album->cover_url }}" alt="Cover of {{ $album->title }}">
        @else
            <div class="music-hero-cover music-cover-placeholder" aria-hidden="true"><i data-lucide="disc-3"></i></div>
        @endif
        <div class="album-hero-copy">
            <span class="eyebrow">Album</span>
            <h1>{{ $album->title }}</h1>
            <p>{{ $album->artist ?: $album->album_artist ?: 'Creative-Ai' }}@if ($album->release_year) · {{ $album->release_year }}@endif</p>
            <p class="album-summary">{{ $album->tracks->count() }} {{ str('track')->plural($album->tracks->count()) }}@if ($albumDuration > 0) · {{ gmdate($albumDuration >= 3600 ? 'G:i:s' : 'i:s', $albumDuration) }}@endif</p>
            @if ($album->description)<p class="album-description">{{ $album->description }}</p>@endif
            @if ($album->tracks->isNotEmpty())
                <button class="button button-primary" type="button" data-playlist-id="album-{{ $album->id }}" aria-label="Play album {{ $album->title }}"><i data-lucide="play"></i>Play album</button>
            @else
                <p class="empty-state">This album does not have any playable tracks yet.</p>
            @endif
        </div>
    </header>

    <section class="album-track-section" aria-labelledby="album-tracks-title">
        <header class="music-library-heading">
            <div><p class="eyebrow">Track listing</p><h2 id="album-tracks-title">Listen in order</h2></div>
            <p>Play any track now or add it to your queue. The player continues through the album.</p>
        </header>
        <ol class="track-list album-track-list">
            @forelse ($album->tracks as $track)
                <li id="track-{{ $track->id }}">
                    <span class="album-track-number">{{ $track->disc_number > 1 ? $track->disc_number.'.' : '' }}{{ $track->track_number ?: $loop->iteration }}</span>
                    <button class="icon-button" type="button" data-play-track-id="{{ $track->id }}" data-track-source-id="album-{{ $album->id }}" aria-label="Play {{ $track->title }}"><i data-lucide="play"></i></button>
                    <div>
                        <a href="{{ route('music.tracks.show', $track) }}" wire:navigate><strong>{{ $track->title }}</strong></a>
                        <span>{{ $track->artist ?: $album->artist ?: $album->album_artist }}</span>
                    </div>
                    @if ($track->duration_seconds)<span class="album-track-duration">{{ gmdate($track->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $track->duration_seconds) }}</span>@endif
                    <button class="button button-secondary" type="button" data-queue-track-id="{{ $track->id }}" data-track-source-id="album-{{ $album->id }}" aria-label="Add {{ $track->title }} to queue">Queue</button>
                </li>
            @empty
                <li class="empty-state">No playable tracks are available for this album.</li>
            @endforelse
        </ol>
    </section>
</section>
@endsection
