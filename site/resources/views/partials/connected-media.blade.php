@if ($connectedMedia->isNotEmpty())
    <section class="post-connections section-inner" aria-labelledby="post-connections-title">
        <header class="section-heading split-heading">
            <div><p class="eyebrow">Connected archive</p><h2 id="post-connections-title">Work featured in this story</h2></div>
            <p>Follow the story into the artwork and music it discusses.</p>
        </header>
        <ol class="connected-media-list">
            @foreach ($connectedMedia as $connection)
                <li>
                    <a href="{{ $connection->mediaUrl() }}" wire:navigate>
                        <span>{{ $connection->type()?->label() }}</span>
                        <strong>{{ $connection->mediaTitle() }}</strong>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endif
