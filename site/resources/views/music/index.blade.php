@extends('layouts.public')
@section('content')
<section class="section-shell"><div class="section-heading"><div><span class="eyebrow">Listening room</span><h1>Music</h1></div></div>
<form class="music-filters" method="get" data-navigate-form><input name="q" value="{{ request('q') }}" placeholder="Search title or artist"><button class="button" type="submit">Search</button></form>
<div class="collection-grid">
@foreach($albums as $album)<article class="collection-card">@if($album->cover_url)<img src="{{ $album->cover_url }}" alt="">@endif<h2><a href="{{ route('music.albums.show',$album) }}" wire:navigate>{{ $album->title }}</a></h2><p>{{ $album->artist }}</p><button class="button" data-playlist-id="album-{{ $album->id }}">Play album</button></article>@endforeach
</div>
<div class="track-list">@foreach($tracks as $track)<article><button class="icon-button" data-play-track-id="{{ $track->id }}" aria-label="Play {{ $track->title }}"><i data-lucide="play"></i></button><div><a href="{{ route('music.tracks.show',$track) }}" wire:navigate><strong>{{ $track->title }}</strong></a><span>{{ $track->artist }}@if($track->album) · {{ $track->album->title }}@endif</span></div><button class="button secondary" data-queue-track-id="{{ $track->id }}">Queue</button></article>@endforeach</div>
<div data-navigate-pagination>{{ $tracks->links() }}</div></section>
@endsection
