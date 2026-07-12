@extends('layouts.public')
@section('content')
<section class="section-shell"><span class="eyebrow">Album</span><h1>{{ $album->title }}</h1><p>{{ $album->artist }} @if($album->release_year)· {{ $album->release_year }}@endif</p>
@if($album->cover_url)<img class="music-hero-cover" src="{{ $album->cover_url }}" alt="Cover of {{ $album->title }}">@endif
<button class="button" data-playlist-id="album-{{ $album->id }}">Play album</button>
<div class="track-list">@foreach($album->tracks as $track)<article><span>{{ $track->track_number }}</span><div><a href="{{ route('music.tracks.show',$track) }}" wire:navigate><strong>{{ $track->title }}</strong></a><span>{{ $track->artist }}</span></div><button class="button secondary" data-queue-track-id="{{ $track->id }}">Queue</button></article>@endforeach</div></section>
@endsection
