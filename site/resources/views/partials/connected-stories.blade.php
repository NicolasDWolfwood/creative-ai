@if ($stories->isNotEmpty())
    <section class="connected-stories section-inner" aria-labelledby="{{ $headingId }}">
        <header class="section-heading split-heading">
            <div><p class="eyebrow">From the Journal</p><h2 id="{{ $headingId }}">Stories behind this work</h2></div>
            <a class="text-link" href="{{ route('posts.index') }}" wire:navigate>Explore the Journal <i data-lucide="arrow-up-right"></i></a>
        </header>
        <div class="post-grid">
            @foreach ($stories as $story)
                @php($storyPublishedAt = $story->effectivePublishedAt())
                <article class="post-teaser">
                    @if ($story->cover_url)
                        <a href="{{ route('posts.show', $story) }}" aria-label="Read {{ $story->title }}" wire:navigate>
                            <img src="{{ $story->cover_url }}" alt="{{ $story->cover_alt_text }}" loading="lazy">
                        </a>
                    @endif
                    <div>
                        <time datetime="{{ $storyPublishedAt?->toDateString() }}">{{ $storyPublishedAt?->format('M j, Y') }}</time>
                        <h3><a href="{{ route('posts.show', $story) }}" wire:navigate>{{ $story->title }}</a></h3>
                        <p>{{ $story->summary }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
