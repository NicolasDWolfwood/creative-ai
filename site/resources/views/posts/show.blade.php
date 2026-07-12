@extends('layouts.public')

@section('body-class', 'post-page')

@section('content')
    @php($publishedAt = $post->effectivePublishedAt())

    @if ($preview ?? false)
        @include('posts.partials.preview-banner')
    @endif

    <article class="post-article">
        <header class="post-header">
            @if ($post->cover_url)
                <img class="post-header-media" src="{{ $post->cover_url }}" alt="{{ $post->cover_alt_text }}" fetchpriority="high">
            @endif
            <div class="post-header-shade" aria-hidden="true"></div>
            <div data-reveal>
                <a class="eyebrow" href="{{ route('posts.index') }}" wire:navigate>Journal</a>
                <h1>{{ $post->title }}</h1>
                <p>{{ $post->summary }}</p>
                @if ($publishedAt)
                    <time datetime="{{ $publishedAt->toDateString() }}">{{ $publishedAt->format('F j, Y') }} · {{ $post->reading_minutes }} min read</time>
                @else
                    <span class="post-reading-time">{{ $post->reading_minutes }} min read</span>
                @endif
            </div>
        </header>
        <div class="post-body" data-reveal>
            {!! Str::markdown((string) $post->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </div>
    </article>

    @if ($morePosts->isNotEmpty())
        <section class="more-posts section-inner">
            <header class="section-heading"><p class="eyebrow">Continue reading</p><h2>More from the studio</h2></header>
            <div class="post-grid">
                @foreach ($morePosts as $entry)
                    @php($entryPublishedAt = $entry->effectivePublishedAt())
                    <article class="post-teaser"><div><time datetime="{{ $entryPublishedAt?->toDateString() }}">{{ $entryPublishedAt?->format('M j, Y') }}</time><h3><a href="{{ route('posts.show', $entry) }}" wire:navigate>{{ $entry->title }}</a></h3><p>{{ $entry->summary }}</p></div></article>
                @endforeach
            </div>
        </section>
    @endif
@endsection
