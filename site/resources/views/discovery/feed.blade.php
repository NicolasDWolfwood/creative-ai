<rss version="2.0">
    <channel>
        <title>Creative-Ai Journal</title>
        <link>{{ route('posts.index') }}</link>
        <description>Notes and updates from the Creative-Ai studio.</description>
        <language>{{ app()->getLocale() }}</language>
        @foreach ($posts as $post)
            <item>
                <title>{{ $post->title }}</title>
                <link>{{ route('posts.show', $post) }}</link>
                <guid>{{ route('posts.show', $post) }}</guid>
                <pubDate>{{ $post->published_at?->toRssString() }}</pubDate>
                <description><![CDATA[{{ $post->summary }}]]></description>
            </item>
        @endforeach
    </channel>
</rss>
