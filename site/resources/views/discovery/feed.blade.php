@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>Creative-Ai Journal</title>
        <link>{{ route('posts.index') }}</link>
        <atom:link href="{{ route('feed') }}" rel="self" type="application/rss+xml" />
        <description>Notes and updates from the Creative-Ai studio.</description>
        <language>{{ app()->getLocale() }}</language>
        @if ($lastBuildDate)
            <lastBuildDate>{{ $lastBuildDate->toRssString() }}</lastBuildDate>
        @endif
        @foreach ($posts as $post)
            @php($publishedAt = $post->effectivePublishedAt())
            <item>
                <title>{{ $post->title }}</title>
                <link>{{ route('posts.show', $post) }}</link>
                <guid isPermaLink="true">{{ route('posts.show', $post) }}</guid>
                <pubDate>{{ $publishedAt->toRssString() }}</pubDate>
                <description>{{ $post->summary }}</description>
            </item>
        @endforeach
    </channel>
</rss>
