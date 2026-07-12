<aside class="post-preview-banner section-inner" aria-label="Private Journal preview notice" role="status">
    <p class="eyebrow">Private preview — not publicly available</p>
    <strong>This renders the last saved version. Unsaved editor changes are not included.</strong>
    <p>
        Stored status: {{ $post->status?->getLabel() ?? 'Unknown' }}.
        Effective status: {{ $post->effectiveStatusAt()->getLabel() }}.
    </p>
</aside>
