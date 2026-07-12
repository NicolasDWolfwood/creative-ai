@extends('layouts.public')
@section('content')
    <section class="section-shell music-library" aria-labelledby="music-title">
        <header class="section-heading">
            <p class="eyebrow">Listening room</p>
            <h1 id="music-title">Music</h1>
            <p>Start with a complete release, follow a curated playlist, or explore music published as a standalone track.</p>
        </header>

        <form class="music-filters" method="get" role="search" data-navigate-form>
            <label for="music-search">Search albums, playlists, tracks, or artists</label>
            <div>
                <input id="music-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Try an album, playlist, track, or artist">
                <button class="button button-primary" type="submit">Search</button>
                @if ($search !== '')
                    <a class="button button-secondary" href="{{ route('music.index') }}" wire:navigate>Clear</a>
                @endif
            </div>
        </form>

        @if ($search !== '')
            <p class="search-summary" role="status">{{ $albums->count() }} {{ str('album')->plural($albums->count()) }}, {{ $playlists->count() }} {{ str('playlist')->plural($playlists->count()) }}, and {{ $tracks->total() }} standalone {{ str('track')->plural($tracks->total()) }} matching “{{ $search }}”. Album and playlist results also search their track listings.</p>
        @endif

        <section class="music-library-section" aria-labelledby="albums-title">
            <header class="music-library-heading">
                <div><p class="eyebrow">Complete releases</p><h2 id="albums-title">Albums</h2></div>
                <p>Choose an album to see its ordered track listing, or begin listening immediately.</p>
            </header>
            <div class="collection-grid music-release-grid">
                @forelse ($albums as $album)
                    @php
                        $albumDuration = (int) $album->tracks->sum('duration_seconds');
                        $matchingTrack = $search === '' ? null : $album->tracks->first(
                            fn ($track) => str(implode(' ', [$track->title, $track->artist]))
                                ->lower()
                                ->contains(str($search)->lower()->toString()),
                        );
                    @endphp
                    <article class="collection-card music-release-card">
                        <div class="music-release-cover">
                            @if ($album->cover_url)
                                <img src="{{ $album->cover_url }}" alt="" loading="lazy">
                            @else
                                <span aria-hidden="true"><i data-lucide="disc-3"></i></span>
                            @endif
                        </div>
                        <div class="music-release-copy">
                            <h3><a href="{{ route('music.albums.show', $album) }}" wire:navigate>{{ $album->title }}</a></h3>
                            <p>{{ $album->artist ?: $album->album_artist ?: 'Creative-Ai' }}@if ($album->release_year) · {{ $album->release_year }}@endif</p>
                            <small>{{ $album->tracks->count() }} {{ str('track')->plural($album->tracks->count()) }}@if ($albumDuration > 0) · {{ gmdate($albumDuration >= 3600 ? 'G:i:s' : 'i:s', $albumDuration) }}@endif</small>
                            @if ($matchingTrack)
                                <small class="music-search-match">Includes “{{ $matchingTrack->title }}”@if ($matchingTrack->artist) by {{ $matchingTrack->artist }}@endif</small>
                            @endif
                        </div>
                        <div class="music-release-actions">
                            <button class="button button-primary" type="button" data-playlist-id="album-{{ $album->id }}" aria-label="Play album {{ $album->title }}">Play album</button>
                        </div>
                    </article>
                @empty
                    <p class="empty-state">{{ $search === '' ? 'No albums have been published yet.' : 'No published albums match this search.' }}</p>
                @endforelse
            </div>
        </section>

        <section class="music-library-section" aria-labelledby="playlists-title">
            <header class="music-library-heading">
                <div><p class="eyebrow">Curated listening</p><h2 id="playlists-title">Playlists</h2></div>
                <p>Follow a listening sequence assembled around a mood, theme, or creative session.</p>
            </header>
            <div class="collection-grid music-release-grid">
                @forelse ($playlists as $playlist)
                    @php
                        $playlistDuration = (int) $playlist->tracks->sum('duration_seconds');
                        $matchingTrack = $search === '' ? null : $playlist->tracks->first(
                            fn ($track) => str(implode(' ', [$track->title, $track->artist]))
                                ->lower()
                                ->contains(str($search)->lower()->toString()),
                        );
                    @endphp
                    <article class="collection-card music-release-card">
                        <div class="music-release-cover">
                            @if ($playlist->cover_url)
                                <img src="{{ $playlist->cover_url }}" alt="" loading="lazy">
                            @else
                                <span aria-hidden="true"><i data-lucide="audio-waveform"></i></span>
                            @endif
                        </div>
                        <div class="music-release-copy">
                            <h3><a href="{{ route('music.playlists.show', $playlist) }}" wire:navigate>{{ $playlist->title }}</a></h3>
                            @if ($playlist->description)<p>{{ $playlist->description }}</p>@endif
                            <small>{{ $playlist->tracks->count() }} {{ str('track')->plural($playlist->tracks->count()) }}@if ($playlistDuration > 0) · {{ gmdate($playlistDuration >= 3600 ? 'G:i:s' : 'i:s', $playlistDuration) }}@endif</small>
                            @if ($matchingTrack)
                                <small class="music-search-match">Includes “{{ $matchingTrack->title }}”@if ($matchingTrack->artist) by {{ $matchingTrack->artist }}@endif</small>
                            @endif
                        </div>
                        @if ($playlist->tracks->isNotEmpty())
                            <div class="music-release-actions">
                                <button class="button button-primary" type="button" data-playlist-id="playlist-{{ $playlist->id }}" aria-label="Play playlist {{ $playlist->title }}">Play playlist</button>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="empty-state">{{ $search === '' ? 'No playlists have been published yet.' : 'No published playlists match this search.' }}</p>
                @endforelse
            </div>
        </section>

        @if ($tracks->total() > 0 || $search !== '')
        <section class="music-library-section" aria-labelledby="tracks-title">
            <header class="music-library-heading">
                <div><p class="eyebrow">Outside the album shelf</p><h2 id="tracks-title">Singles &amp; standalone tracks</h2></div>
                <p>Only tracks deliberately released on their own appear here. Album tracks stay with their album.</p>
            </header>
            <div class="track-list">
                @forelse ($tracks as $track)
                    <article>
                        <button class="icon-button" type="button" data-play-track-id="{{ $track->id }}" data-track-source-id="standalone-tracks" aria-label="Play {{ $track->title }}"><i data-lucide="play"></i></button>
                        <div><a href="{{ route('music.tracks.show', $track) }}" wire:navigate><strong>{{ $track->title }}</strong></a><span>{{ $track->artist }}@if ($track->album?->isPubliclyPublished()) · {{ $track->album->title }}@endif</span></div>
                        <button class="button button-secondary" type="button" data-queue-track-id="{{ $track->id }}" data-track-source-id="standalone-tracks" aria-label="Add {{ $track->title }} to queue">Add to queue</button>
                    </article>
                @empty
                    <p class="empty-state">No standalone tracks match this search.</p>
                @endforelse
            </div>
            <div data-navigate-pagination>{{ $tracks->links() }}</div>
        </section>
        @endif
    </section>
@endsection
