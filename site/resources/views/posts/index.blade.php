@extends('layouts.public')

@section('body-class', 'journal-page')

@section('content')
    <header class="page-intro section-inner" data-reveal>
        <p class="eyebrow">From the studio</p>
        <h1>Journal</h1>
        <p>Progress notes, releases, process fragments, and the ideas behind new work.</p>
    </header>
    <section class="journal-index section-inner" aria-label="Journal posts">
        <div class="post-index-grid">
            @forelse ($posts as $post)
                @php($publishedAt = $post->effectivePublishedAt())
                <article class="post-index-item" data-reveal>
                    <a class="post-index-image" href="{{ route('posts.show', $post) }}" aria-label="Read {{ $post->title }}" wire:navigate>
                        @if ($post->cover_url)<img src="{{ $post->cover_url }}" alt="{{ $post->cover_alt_text }}" loading="lazy">@else<span aria-hidden="true">CA</span>@endif
                    </a>
                    <div>
                        <time datetime="{{ $publishedAt?->toDateString() }}">{{ $publishedAt?->format('F j, Y') }} · {{ $post->reading_minutes }} min</time>
                        <h2><a href="{{ route('posts.show', $post) }}" wire:navigate>{{ $post->title }}</a></h2>
                        <p>{{ $post->summary }}</p>
                        <a class="text-link" href="{{ route('posts.show', $post) }}" aria-label="Read {{ $post->title }}" wire:navigate>Read entry <i data-lucide="arrow-up-right"></i></a>
                    </div>
                </article>
            @empty
                <p class="empty-state">No journal entries have been published yet.</p>
            @endforelse
        </div>
        <div data-navigate-pagination>{{ $posts->links() }}</div>
    </section>
@endsection
