@extends('layouts.public')

@section('body-class', 'public-page playlist-detail-page')

@section('content')
@php
    $playlistDuration = (int) $playlist->tracks->sum('duration_seconds');
@endphp
<article class="section-shell album-page playlist-page">
    <a class="text-link album-back-link" href="{{ route('music.index') }}" wire:navigate>All music</a>

    <header class="album-hero playlist-hero">
        @if ($playlist->cover_url)
            <img class="music-hero-cover" src="{{ $playlist->cover_url }}" alt="Cover of {{ $playlist->title }}">
        @else
            <div class="music-hero-cover music-cover-placeholder" aria-hidden="true"><i data-lucide="audio-waveform"></i></div>
        @endif

        <div class="album-hero-copy">
            <span class="eyebrow">Playlist</span>
            <h1>{{ $playlist->title }}</h1>
            <p class="album-summary">
                {{ $playlist->tracks->count() }} {{ str('track')->plural($playlist->tracks->count()) }}
                @if ($playlistDuration > 0)
                    · {{ gmdate($playlistDuration >= 3600 ? 'G:i:s' : 'i:s', $playlistDuration) }}
                @endif
            </p>
            @if ($playlist->description)
                <p class="album-description">{{ $playlist->description }}</p>
            @endif
            @include('partials.public-tags', ['tags' => $musicTags, 'label' => 'Playlist tags'])
            @if ($playlist->tracks->isNotEmpty())
                <button class="button button-primary" type="button" data-playlist-id="playlist-{{ $playlist->id }}" aria-label="Play playlist {{ $playlist->title }}"><i data-lucide="play"></i>Play playlist</button>
            @else
                <p class="empty-state">This playlist does not have any playable tracks yet.</p>
            @endif
        </div>
    </header>

    <section class="album-track-section" aria-labelledby="playlist-tracks-title">
        <header class="music-library-heading">
            <div><p class="eyebrow">Track listing</p><h2 id="playlist-tracks-title">Listen in order</h2></div>
            <p>Play any track now or add it to your queue. The player continues through this playlist.</p>
        </header>
        <ol class="track-list album-track-list playlist-track-list">
            @forelse ($playlist->tracks as $track)
                <li id="track-{{ $track->id }}">
                    <span class="album-track-number">{{ $track->pivot->position ?: $loop->iteration }}</span>
                    <button class="icon-button" type="button" data-play-track-id="{{ $track->id }}" data-track-source-id="playlist-{{ $playlist->id }}" aria-label="Play {{ $track->title }}"><i data-lucide="play"></i></button>
                    <div>
                        <a href="{{ route('music.tracks.show', $track) }}" wire:navigate><strong>{{ $track->title }}</strong></a>
                        @if ($track->artist)<span>{{ $track->artist }}</span>@endif
                    </div>
                    @if ($track->duration_seconds)<span class="album-track-duration">{{ gmdate($track->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $track->duration_seconds) }}</span>@endif
                    <button class="button button-secondary" type="button" data-queue-track-id="{{ $track->id }}" data-track-source-id="playlist-{{ $playlist->id }}" aria-label="Add {{ $track->title }} to queue">Queue</button>
                </li>
            @empty
                <li class="empty-state">No playable tracks are available for this playlist.</li>
            @endforelse
        </ol>
    </section>
</article>
@include('partials.connected-stories', ['stories' => $stories, 'headingId' => 'playlist-stories-title'])
@endsection
