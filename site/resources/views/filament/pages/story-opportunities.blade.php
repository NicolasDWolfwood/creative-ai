<x-filament-panels::page>
    @php
        $counts = $this->getOpportunityCounts();
        $opportunities = $this->getOpportunities();
        $typeOptions = $this->getMediaTypeOptions();
    @endphp

    <div class="ca-story-opportunities">
        <section class="ca-story-opportunity-counts" aria-label="Story opportunities by media type">
            @foreach ($typeOptions as $value => $label)
                <button
                    type="button"
                    wire:click="$set('mediaType', '{{ $value }}')"
                    aria-pressed="{{ $mediaType === $value ? 'true' : 'false' }}"
                    @class(['is-active' => $mediaType === $value])
                >
                    <span>{{ $label }}</span>
                    <strong>{{ number_format($counts[$value] ?? 0) }}</strong>
                </button>
            @endforeach
        </section>

        <section class="ca-story-opportunity-panel">
            <div class="ca-story-opportunity-filters">
                <label>
                    <span>Search public sources</span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Title, artist, or description"
                        maxlength="{{ \App\Services\StoryOpportunityService::SEARCH_LIMIT }}"
                    >
                </label>

                <label>
                    <span>Media type</span>
                    <select wire:model.live="mediaType">
                        <option value="all">All media</option>
                        @foreach ($typeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="ca-story-opportunity-table-wrap">
                <table class="ca-story-opportunity-table">
                    <thead>
                        <tr>
                            <th scope="col">Source</th>
                            <th scope="col">Type</th>
                            <th scope="col">Updated</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($opportunities as $record)
                            @php($type = $this->mediaTypeFor($record))
                            <tr wire:key="story-opportunity-{{ $type->value }}-{{ $record->getKey() }}">
                                <td>
                                    <strong>{{ $record->title }}</strong>
                                    @if ($description = $this->sourceDescription($record))
                                        <small>{{ $description }}</small>
                                    @endif
                                </td>
                                <td><span class="ca-story-opportunity-type">{{ $type->label() }}</span></td>
                                <td>{{ $record->updated_at?->diffForHumans() ?: 'Unknown' }}</td>
                                <td>
                                    <div class="ca-story-opportunity-actions">
                                        <a href="{{ $this->publicUrl($record) }}" target="_blank" rel="noopener">View public page</a>
                                        {{ ($this->createJournalDraftAction)(['type' => $type->value, 'id' => $record->getKey()]) }}
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="ca-story-opportunity-empty">
                                    No public, unconnected sources match these filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($opportunities->hasPages())
                <x-filament::pagination :paginator="$opportunities" />
            @endif
        </section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
