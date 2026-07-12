@if ($paginator->hasPages())
    <nav class="tag-pagination" aria-label="{{ $label }} pagination">
        @if (! $paginator->onFirstPage())
            <a class="text-link" href="{{ $paginator->previousPageUrl() }}" wire:navigate>Previous {{ strtolower($label) }}</a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="text-link" href="{{ $paginator->nextPageUrl() }}" wire:navigate>More {{ strtolower($label) }}</a>
        @endif
    </nav>
@endif
