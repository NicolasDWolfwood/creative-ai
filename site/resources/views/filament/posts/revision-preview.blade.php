<div class="space-y-6">
    <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Reason</dt>
            <dd class="mt-1">{{ $revision->reason ?: 'No separate reason was recorded.' }}</dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Saved by</dt>
            <dd class="mt-1">{{ $revision->user?->name ?: 'System' }}</dd>
        </div>
    </dl>

    @foreach ($fields as $field => $label)
        @php($value = $revision->snapshot['content'][$field] ?? null)
        <section aria-labelledby="revision-{{ str_replace('_', '-', $field) }}-heading">
            <h3 id="revision-{{ str_replace('_', '-', $field) }}-heading" class="font-semibold">{{ $label }}</h3>
            @if (filled($value))
                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm text-gray-900 dark:bg-white/5 dark:text-gray-100">{{ $value }}</pre>
            @else
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Not set in this revision.</p>
            @endif
        </section>
    @endforeach

    <section aria-labelledby="revision-connection-context-heading">
        <h3 id="revision-connection-context-heading" class="font-semibold">Connection context</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            These references record what was connected when the revision was saved. They are read-only and are not restored.
        </p>

        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
                <h4 class="text-sm font-medium">Shared tag IDs</h4>
                @if ($tagIds = ($revision->snapshot['tag_ids'] ?? []))
                    <p class="mt-1 text-sm">{{ collect($tagIds)->join(', ') }}</p>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No shared tags were connected.</p>
                @endif
            </div>

            <div>
                <h4 class="text-sm font-medium">Ordered media references</h4>
                @if ($media = ($revision->snapshot['media'] ?? []))
                    <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm">
                        @foreach ($media as $reference)
                            <li>
                                {{ \Illuminate\Support\Str::headline((string) ($reference['type'] ?? 'unknown')) }}
                                ID {{ $reference['id'] ?? 'missing' }}
                                <span class="text-gray-500 dark:text-gray-400">(position {{ $reference['position'] ?? 'missing' }})</span>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No media was connected.</p>
                @endif
            </div>
        </div>
    </section>

    <p class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
        Restoring this snapshot is deliberately limited to the fields shown above. Durable URLs, workflow state, featured placement, private notes, tags, and media connections stay as they are now.
    </p>
</div>
