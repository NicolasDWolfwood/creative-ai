<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-check">
            Save signature settings
        </x-filament::button>
    </form>

    @php($signatureCounts = $this->signatureCounts())
    <section class="mt-8" aria-labelledby="signature-status-heading">
        <div class="mb-4">
            <p class="text-xs font-bold uppercase tracking-widest text-primary-600 dark:text-primary-400">Rendition queue</p>
            <h2 id="signature-status-heading" class="text-xl font-bold text-gray-950 dark:text-white">Artwork status</h2>
        </div>
        <dl class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                'ready' => 'Ready',
                'queued' => 'Queued',
                'processing' => 'Processing',
                'stale' => 'Stale',
                'failed' => 'Failed',
                'review' => 'Review',
            ] as $key => $label)
                <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-white/10">
                    <dt class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($signatureCounts[$key]) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="mt-8 border-y border-gray-200 py-6 dark:border-white/10" aria-labelledby="signature-preview-heading">
        <div class="mb-4">
            <p class="text-xs font-bold uppercase tracking-widest text-primary-600 dark:text-primary-400">Private asset preview</p>
            <h2 id="signature-preview-heading" class="text-xl font-bold text-gray-950 dark:text-white">Black and white renditions</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                The same saved alpha mask is recolored by the renderer; no second signature file is required.
            </p>
        </div>

        @if ($previewUrl)
            <div class="grid gap-4 md:grid-cols-3">
                <figure class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                    <div
                        class="flex min-h-56 items-center justify-center p-6"
                        style="background-color: #ffffff; background-image: linear-gradient(45deg, #d1d5db 25%, transparent 25%), linear-gradient(-45deg, #d1d5db 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #d1d5db 75%), linear-gradient(-45deg, transparent 75%, #d1d5db 75%); background-position: 0 0, 0 8px, 8px -8px, -8px 0; background-size: 16px 16px;"
                    >
                        <img src="{{ $previewUrl }}" alt="Signature alpha mask on a checkerboard background" class="max-h-48 max-w-full object-contain">
                    </div>
                    <figcaption class="border-t border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700 dark:border-white/10 dark:text-gray-300">Transparency</figcaption>
                </figure>

                <figure class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                    <div class="flex min-h-56 items-center justify-center bg-white p-6">
                        <img src="{{ $previewUrl }}" alt="Black signature rendition on a light background" class="max-h-48 max-w-full object-contain">
                    </div>
                    <figcaption class="border-t border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700 dark:border-white/10 dark:text-gray-300">Black rendition</figcaption>
                </figure>

                <figure class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                    <div class="flex min-h-56 items-center justify-center bg-gray-950 p-6">
                        <img src="{{ $previewUrl }}" alt="White signature rendition on a dark background" class="max-h-48 max-w-full object-contain" style="filter: invert(1);">
                    </div>
                    <figcaption class="border-t border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700 dark:border-white/10 dark:text-gray-300">White rendition</figcaption>
                </figure>
            </div>

            <div class="mt-4 grid gap-2 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-2">
                <p>{{ $assetSummary }}</p>
                <p class="sm:text-right">Recipe revision {{ $revisionSummary }}…</p>
            </div>
        @else
            <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-white/15">
                <p class="font-semibold text-gray-950 dark:text-white">No validated signature asset is saved yet.</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Upload the transparent PNG and save these settings to activate previews.</p>
            </div>
        @endif
    </section>
</x-filament-panels::page>
