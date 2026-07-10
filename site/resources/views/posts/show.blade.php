@extends('layouts.public')

@section('body-class', 'post-page')

@section('content')
    <article class="post-article">
        <header class="post-header" @if ($post->cover_url) style="--post-image:url('{{ $post->cover_url }}')" @endif>
            <div class="post-header-shade" aria-hidden="true"></div>
            <div data-reveal>
                <a class="eyebrow" href="{{ route('posts.index') }}">Journal</a>
                <h1>{{ $post->title }}</h1>
                <p>{{ $post->summary }}</p>
                <time datetime="{{ $post->published_at?->toDateString() }}">{{ $post->published_at?->format('F j, Y') }} · {{ $post->reading_minutes }} min read</time>
            </div>
        </header>
        <div class="post-body" data-reveal>
            {!! Str::markdown($post->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </div>
    </article>

    @if ($morePosts->isNotEmpty())
        <section class="more-posts section-inner">
            <header class="section-heading"><p class="eyebrow">Continue reading</p><h2>More from the studio</h2></header>
            <div class="post-grid">
                @foreach ($morePosts as $entry)
                    <article class="post-teaser"><div><time>{{ $entry->published_at?->format('M j, Y') }}</time><h3><a href="{{ route('posts.show', $entry) }}">{{ $entry->title }}</a></h3><p>{{ $entry->summary }}</p></div></article>
                @endforeach
            </div>
        </section>
    @endif
@endsection
