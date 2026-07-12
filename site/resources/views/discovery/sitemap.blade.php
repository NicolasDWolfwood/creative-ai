<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @php
        $lastModified = static function (string $type, $record) use ($storyLastModified) {
            $storyDate = $storyLastModified[$type][$record->getKey()] ?? null;

            return $storyDate && $storyDate->gt($record->updated_at)
                ? $storyDate
                : $record->updated_at;
        };
    @endphp
    <url><loc>{{ route('home') }}</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>
    <url><loc>{{ route('gallery') }}</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>
    <url><loc>{{ route('music.index') }}</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>
    <url><loc>{{ route('posts.index') }}</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>
    @foreach ($collections as $collection)
        <url><loc>{{ route('collections.show', $collection) }}</loc><lastmod>{{ $lastModified('collection', $collection)->toAtomString() }}</lastmod><priority>0.8</priority></url>
    @endforeach
    @foreach ($artworks as $artwork)
        <url><loc>{{ route('artworks.show', $artwork) }}</loc><lastmod>{{ $lastModified('artwork', $artwork)->toAtomString() }}</lastmod><priority>0.8</priority></url>
    @endforeach
    @foreach ($albums as $album)
        <url><loc>{{ route('music.albums.show', $album) }}</loc><lastmod>{{ $lastModified('album', $album)->toAtomString() }}</lastmod><priority>0.8</priority></url>
    @endforeach
    @foreach ($playlists as $playlist)
        <url><loc>{{ route('music.playlists.show', $playlist) }}</loc><lastmod>{{ $lastModified('playlist', $playlist)->toAtomString() }}</lastmod><priority>0.7</priority></url>
    @endforeach
    @foreach ($tracks as $track)
        <url><loc>{{ route('music.tracks.show', $track) }}</loc><lastmod>{{ $lastModified('track', $track)->toAtomString() }}</lastmod><priority>0.7</priority></url>
    @endforeach
    @foreach ($posts as $post)
        <url><loc>{{ route('posts.show', $post) }}</loc><lastmod>{{ $post->effectivePublicContentUpdatedAt()->toAtomString() }}</lastmod><priority>0.7</priority></url>
    @endforeach
    @foreach ($tags as $tag)
        <url><loc>{{ route('tags.show', $tag) }}</loc><priority>0.6</priority></url>
    @endforeach
</urlset>
