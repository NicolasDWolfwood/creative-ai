<div class="space-y-5">
    @if ($report->isReady())
        <p class="text-sm text-success-600 dark:text-success-400">
            No blocking issues were found. The post can enter a publishable workflow state.
        </p>
    @else
        <section aria-labelledby="readiness-blockers-heading">
            <h3 id="readiness-blockers-heading" class="font-semibold text-danger-600 dark:text-danger-400">Blocking issues</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($report->blockers() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section aria-labelledby="readiness-warnings-heading">
        <h3 id="readiness-warnings-heading" class="font-semibold">Recommended improvements</h3>
        @if ($report->warnings() === [])
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">No advisory improvements are pending.</p>
        @else
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($report->warnings() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
