@extends('layouts.public')
@section('content')
    <section class="section-shell music-library" aria-labelledby="music-title">
        <header class="section-heading">
            <p class="eyebrow">Listening room</p>
            <h1 id="music-title">Music</h1>
            <p>Browse albums and individual tracks, or search the complete published library.</p>
        </header>

        <form class="music-filters" method="get" role="search" data-navigate-form>
            <label for="music-search">Search albums, tracks, or artists</label>
            <div>
                <input id="music-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Try an album, track, or artist">
                <button class="button button-primary" type="submit">Search</button>
                @if ($search !== '')
                    <a class="button button-secondary" href="{{ route('music.index') }}" wire:navigate>Clear</a>
                @endif
            </div>
        </form>

        @if ($search !== '')
            <p class="search-summary" role="status">{{ $albums->count() }} {{ str('album')->plural($albums->count()) }} and {{ $tracks->total() }} {{ str('track')->plural($tracks->total()) }} matching “{{ $search }}”.</p>
        @endif

        <section aria-labelledby="albums-title">
            <h2 id="albums-title">Albums</h2>
            <div class="collection-grid">
                @forelse ($albums as $album)
                    <article class="collection-card">
                        @if ($album->cover_url)<img src="{{ $album->cover_url }}" alt="" loading="lazy">@endif
                        <h3><a href="{{ route('music.albums.show', $album) }}" wire:navigate>{{ $album->title }}</a></h3>
                        <p>{{ $album->artist ?: $album->album_artist }}</p>
                        <button class="button button-secondary" type="button" data-playlist-id="album-{{ $album->id }}">Play album</button>
                    </article>
                @empty
                    <p class="empty-state">No published albums match this search.</p>
                @endforelse
            </div>
        </section>

        <section aria-labelledby="tracks-title">
            <h2 id="tracks-title">Tracks</h2>
            <div class="track-list">
                @forelse ($tracks as $track)
                    <article>
                        <button class="icon-button" type="button" data-play-track-id="{{ $track->id }}" aria-label="Play {{ $track->title }}"><i data-lucide="play"></i></button>
                        <div><a href="{{ route('music.tracks.show', $track) }}" wire:navigate><strong>{{ $track->title }}</strong></a><span>{{ $track->artist }}@if ($track->album) · {{ $track->album->title }}@endif</span></div>
                        <button class="button button-secondary" type="button" data-queue-track-id="{{ $track->id }}">Add to queue</button>
                    </article>
                @empty
                    <p class="empty-state">No published tracks match this search.</p>
                @endforelse
            </div>
            <div data-navigate-pagination>{{ $tracks->links() }}</div>
        </section>
    </section>
@endsection
