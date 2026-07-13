@php
    use App\Enums\PostAiOperation;
    use App\Enums\PostAiRunStatus;

    $claims = is_array($result['claims_requiring_verification'] ?? null)
        ? $result['claims_requiring_verification']
        : [];
    $sourcePassage = data_get($run->context_manifest, 'outbound.selected_passage.content');
    $metadataLabels = [
        'excerpt' => 'Excerpt',
        'cover_alt_text' => 'Cover alternative text',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
    ];
@endphp

<div class="space-y-6">
    @if ($result === null)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950/30 dark:text-danger-200">
            <p class="font-semibold">Unsupported Journal AI result</p>
            <p class="mt-1">This saved result cannot be displayed or applied because its contract or data is no longer supported. Its raw payload is intentionally hidden.</p>
        </div>
    @else
        <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div>
                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                    @if ($run->status === PostAiRunStatus::Applied)
                        Applied suggestion
                    @elseif ($fresh)
                        Current saved-post suggestion
                    @else
                        Outdated saved-post suggestion
                    @endif
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $fresh ? 'The saved source still matches this run.' : 'The source or contract has changed. Review or copy only; application is blocked.' }}
                </p>
            </div>

            @if (! $comparisonOnly && is_string($copyText))
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-clipboard-document"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(@js($copyText)).then(() => { copied = true; setTimeout(() => copied = false, 1800) })"
                >
                    <span x-text="copied ? 'Copied' : 'Copy suggestion'">Copy suggestion</span>
                </x-filament::button>
            @endif
        </div>

        @if ($run->operation === PostAiOperation::Directions)
            <section class="space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Editorial directions</h3>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $result['summary'] }}</p>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($result['directions'] as $direction)
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <h4 class="font-semibold text-gray-950 dark:text-white">{{ $direction['title'] }}</h4>
                            <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $direction['rationale'] }}</p>
                            <p class="mt-3 text-sm"><span class="font-medium">Suggested angle:</span> {{ $direction['suggested_angle'] }}</p>
                            @if ($direction['questions'] !== [])
                                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                                    @foreach ($direction['questions'] as $question)
                                        <li>{{ $question }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @elseif ($run->operation === PostAiOperation::Outline)
            <section class="space-y-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Working title</p>
                    <h3 class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $result['working_title'] }}</h3>
                    <p class="mt-3 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $result['thesis'] }}</p>
                </div>
                @foreach ($result['sections'] as $section)
                    <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <h4 class="font-semibold text-gray-950 dark:text-white">{{ $section['heading'] }}</h4>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $section['purpose'] }}</p>
                        @if ($section['key_points'] !== [])
                            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($section['key_points'] as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </article>
                @endforeach
            </section>
        @elseif ($run->operation === PostAiOperation::EditorialReview)
            <section class="space-y-5">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Editorial review</h3>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $result['summary'] }}</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Strengths</h4>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        @foreach ($result['strengths'] as $strength)
                            <li>{{ $strength }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="space-y-3">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Issues to consider</h4>
                    @foreach ($result['issues'] as $issue)
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ str($issue['severity'])->headline() }} · {{ str($issue['category'])->replace('_', ' ')->headline() }}
                            </p>
                            <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $issue['feedback'] }}</p>
                            @if (filled($issue['passage']))
                                <blockquote class="mt-3 border-s-2 border-gray-300 ps-3 text-sm italic text-gray-600 dark:border-gray-600 dark:text-gray-400">{{ $issue['passage'] }}</blockquote>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @elseif ($run->operation === PostAiOperation::ImprovePassage)
            <section class="space-y-4">
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Original saved passage</h3>
                        <pre class="mt-3 whitespace-pre-wrap break-words font-sans text-sm text-gray-700 dark:text-gray-300">{{ is_string($sourcePassage) ? $sourcePassage : 'Original passage unavailable.' }}</pre>
                    </div>
                    <div class="rounded-xl border border-primary-300 bg-primary-50/50 p-4 dark:border-primary-700 dark:bg-primary-950/20">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Suggested replacement</h3>
                        <pre class="mt-3 whitespace-pre-wrap break-words font-sans text-sm text-gray-700 dark:text-gray-300">{{ $result['replacement_markdown'] }}</pre>
                    </div>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ $result['preserved_meaning'] ? 'The model reports that it preserved the intended meaning.' : 'The model reports that the intended meaning may have changed.' }}
                    </p>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $result['rationale'] }}</p>
                </div>
            </section>
        @elseif ($run->operation === PostAiOperation::Metadata)
            <section class="space-y-4">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Current and suggested metadata</h3>
                @foreach ($metadataLabels as $field => $label)
                    <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $label }}</h4>
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Current saved value</p>
                                <pre class="mt-1 whitespace-pre-wrap break-words font-sans text-sm text-gray-700 dark:text-gray-300">{{ filled($post->getAttribute($field)) ? $post->getAttribute($field) : 'No value' }}</pre>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">AI suggestion</p>
                                <pre class="mt-1 whitespace-pre-wrap break-words font-sans text-sm text-gray-700 dark:text-gray-300">{{ is_string($result[$field]) && trim($result[$field]) !== '' ? $result[$field] : 'No suggestion' }}</pre>
                            </div>
                        </div>
                    </article>
                @endforeach
                @if ($result['rationale'] !== [])
                    <div>
                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Rationale</h4>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($result['rationale'] as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        <section @class([
            'rounded-xl border p-4',
            'border-warning-300 bg-warning-50 dark:border-warning-700 dark:bg-warning-950/30' => $claims !== [],
            'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $claims === [],
        ])>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Claims requiring human verification</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">AI feedback is not fact validation. Check these claims before relying on or publishing them.</p>
            @if ($claims === [])
                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">The model did not identify a claim to verify. This is not a guarantee of factual accuracy.</p>
            @else
                <div class="mt-3 space-y-3">
                    @foreach ($claims as $claim)
                        <article>
                            <p class="whitespace-pre-wrap text-sm font-medium text-gray-950 dark:text-white">{{ $claim['claim'] }}</p>
                            <p class="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $claim['reason'] }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
