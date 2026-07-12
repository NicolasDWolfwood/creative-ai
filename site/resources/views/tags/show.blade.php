@extends('layouts.public')

@section('body-class', 'public-page tag-page')

@section('content')
    <header class="page-intro section-inner">
        <p class="eyebrow">Shared archive tag</p>
        <h1>{{ ucfirst($tag->name) }}</h1>
        <p>Stories, images, collections, and music connected by a shared idea.</p>
    </header>

    <div class="tag-page-sections section-inner">
        @if ($posts->isNotEmpty())
            <section aria-labelledby="tag-posts-title">
                <header class="section-heading"><p class="eyebrow">Journal</p><h2 id="tag-posts-title">Stories</h2></header>
                <div class="post-grid">
                    @foreach ($posts as $post)
                        @php($publishedAt = $post->effectivePublishedAt())
                        <article class="post-teaser">
                            @if ($post->cover_url)<a href="{{ route('posts.show', $post) }}" aria-label="Read {{ $post->title }}" wire:navigate><img src="{{ $post->cover_url }}" alt="{{ $post->cover_alt_text }}" loading="lazy"></a>@endif
                            <div><time datetime="{{ $publishedAt?->toDateString() }}">{{ $publishedAt?->format('M j, Y') }}</time><h3><a href="{{ route('posts.show', $post) }}" wire:navigate>{{ $post->title }}</a></h3><p>{{ $post->summary }}</p></div>
                        </article>
                    @endforeach
                </div>
                @include('tags.partials.pagination', ['paginator' => $posts, 'label' => 'Journal stories'])
            </section>
        @endif

        @if ($artworks->isNotEmpty())
            <section aria-labelledby="tag-artworks-title">
                <header class="section-heading"><p class="eyebrow">Visual archive</p><h2 id="tag-artworks-title">Artwork</h2></header>
                <div class="tag-card-grid">
                    @foreach ($artworks as $artwork)
                        <a class="tag-media-card" href="{{ route('artworks.show', $artwork) }}" wire:navigate><img src="{{ $artwork->thumb_url }}" alt="{{ $artwork->image_alt }}" loading="lazy"><span><small>Artwork</small><strong>{{ $artwork->title }}</strong></span></a>
                    @endforeach
                </div>
                @include('tags.partials.pagination', ['paginator' => $artworks, 'label' => 'Artwork'])
            </section>
        @endif

        @if ($collections->isNotEmpty())
            <section aria-labelledby="tag-collections-title">
                <header class="section-heading"><p class="eyebrow">Curated worlds</p><h2 id="tag-collections-title">Collections</h2></header>
                <div class="tag-link-grid">@foreach ($collections as $collection)<a href="{{ route('collections.show', $collection) }}" wire:navigate><small>Collection</small><strong>{{ $collection->title }}</strong>@if ($collection->description)<span>{{ $collection->description }}</span>@endif</a>@endforeach</div>
                @include('tags.partials.pagination', ['paginator' => $collections, 'label' => 'Collections'])
            </section>
        @endif

        @if ($albums->isNotEmpty() || $playlists->isNotEmpty() || $tracks->isNotEmpty())
            <section aria-labelledby="tag-music-title">
                <header class="section-heading"><p class="eyebrow">Listening room</p><h2 id="tag-music-title">Music</h2></header>
                <div class="tag-link-grid">
                    @foreach ($albums as $album)<a href="{{ route('music.albums.show', $album) }}" wire:navigate><small>Album</small><strong>{{ $album->title }}</strong></a>@endforeach
                    @foreach ($playlists as $playlist)<a href="{{ route('music.playlists.show', $playlist) }}" wire:navigate><small>Playlist</small><strong>{{ $playlist->title }}</strong></a>@endforeach
                    @foreach ($tracks as $track)<a href="{{ route('music.tracks.show', $track) }}" wire:navigate><small>Track</small><strong>{{ $track->title }}</strong>@if ($track->artist)<span>{{ $track->artist }}</span>@endif</a>@endforeach
                </div>
                @include('tags.partials.pagination', ['paginator' => $albums, 'label' => 'Albums'])
                @include('tags.partials.pagination', ['paginator' => $playlists, 'label' => 'Playlists'])
                @include('tags.partials.pagination', ['paginator' => $tracks, 'label' => 'Tracks'])
            </section>
        @endif
    </div>
@endsection
