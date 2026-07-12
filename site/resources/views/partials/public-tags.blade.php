@if ($tags->isNotEmpty())
    <nav class="public-tag-list" aria-label="{{ $label ?? 'Tags' }}">
        @foreach ($tags as $tag)
            <a href="{{ route('tags.show', $tag) }}" wire:navigate>{{ $tag->name }}</a>
        @endforeach
    </nav>
@endif
