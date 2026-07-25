<x-filament-panels::page>
    <section class="rounded-xl border border-gray-200 p-5 dark:border-white/10" aria-labelledby="homepage-content-scope">
        <p class="text-xs font-bold uppercase tracking-widest text-primary-600 dark:text-primary-400">What this controls</p>
        <h2 id="homepage-content-scope" class="mt-1 text-lg font-bold text-gray-950 dark:text-white">One public introduction</h2>
        <div class="mt-3 grid gap-3 text-sm text-gray-600 dark:text-gray-400 md:grid-cols-3">
            <p><strong class="block text-gray-950 dark:text-white">Displayed on</strong> Homepage, artwork archive, and tag-filtered showcase headers.</p>
            <p><strong class="block text-gray-950 dark:text-white">Search description</strong> Reused as the default page description on those views.</p>
            <p><strong class="block text-gray-950 dark:text-white">Collection pages</strong> A collection's own description takes precedence when one is available.</p>
        </div>
        <p class="mt-4 text-sm font-medium text-warning-700 dark:text-warning-400">Saving publishes this text immediately. It has no separate draft or approval step.</p>
    </section>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-check">
            Save homepage content
        </x-filament::button>
    </form>
</x-filament-panels::page>
