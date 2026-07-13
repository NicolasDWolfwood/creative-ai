@php
    $manifest = is_array($pending['context_manifest'] ?? null) ? $pending['context_manifest'] : [];
    $outbound = is_array($manifest['outbound'] ?? null) ? $manifest['outbound'] : [];
    $included = is_array($manifest['included_fields'] ?? null) ? $manifest['included_fields'] : [];
    $omitted = is_array($manifest['omitted_fields'] ?? null) ? $manifest['omitted_fields'] : [];
    $budgets = is_array($manifest['budgets'] ?? null) ? $manifest['budgets'] : [];
    $outboundJson = json_encode($outbound, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

<div class="space-y-6">
    <div @class([
        'rounded-xl border p-4',
        'border-warning-300 bg-warning-50 dark:border-warning-700 dark:bg-warning-950/30' => (bool) ($pending['external_processing'] ?? false),
        'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => ! (bool) ($pending['external_processing'] ?? false),
    ])>
        <p class="text-sm font-semibold text-gray-950 dark:text-white">
            {{ (bool) ($pending['external_processing'] ?? false) ? 'External processing destination' : 'Private processing destination' }}
        </p>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Provider</dt>
                <dd class="mt-1 break-words text-gray-950 dark:text-white">{{ $pending['provider'] ?? 'Unknown' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Model</dt>
                <dd class="mt-1 break-words text-gray-950 dark:text-white">{{ $pending['model'] ?? 'Unknown' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Endpoint</dt>
                <dd class="mt-1 break-all text-gray-950 dark:text-white">{{ $pending['endpoint'] ?? 'Unknown' }}</dd>
            </div>
        </dl>

        @if ((bool) ($pending['external_processing'] ?? false))
            <p class="mt-3 text-sm text-warning-800 dark:text-warning-200">
                The exact content below will leave this application and be processed by the named provider.
            </p>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Included context</h3>
            @if ($included === [])
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No context fields were included.</p>
            @else
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($included as $field)
                        <li><code>{{ $field }}</code></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Deliberately omitted</h3>
            @if ($omitted === [])
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Nothing is marked as omitted.</p>
            @else
                <dl class="mt-2 space-y-2 text-sm">
                    @foreach ($omitted as $field => $reason)
                        <div>
                            <dt><code class="text-gray-800 dark:text-gray-200">{{ $field }}</code></dt>
                            <dd class="text-gray-500 dark:text-gray-400">{{ str((string) $reason)->replace('_', ' ')->headline() }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    </div>

    <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Exact outbound JSON</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format((int) ($budgets['total_bytes_used'] ?? 0)) }} of
                {{ number_format((int) ($budgets['total_bytes_limit'] ?? 0)) }} bytes
            </p>
        </div>
        <pre class="mt-2 max-h-[32rem] overflow-auto whitespace-pre-wrap break-words rounded-xl bg-gray-950 p-4 text-xs leading-5 text-gray-100">{{ is_string($outboundJson) ? $outboundJson : '{}' }}</pre>
    </div>
</div>
