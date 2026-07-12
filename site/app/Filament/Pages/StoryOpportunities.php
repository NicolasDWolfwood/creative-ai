<?php

namespace App\Filament\Pages;

use App\Enums\PostMediaType;
use App\Filament\Actions\CreateJournalDraftAction;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\StoryOpportunityService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class StoryOpportunities extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.story-opportunities';

    protected static ?string $title = 'Story opportunities';

    protected static ?string $navigationLabel = 'Story opportunities';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'story-opportunities';

    #[Url(as: 'type', except: 'all')]
    public string $mediaType = 'all';

    #[Url(except: '')]
    public string $search = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin;
    }

    public static function getNavigationBadge(): ?string
    {
        return number_format(app(StoryOpportunityService::class)->count());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Story opportunities';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Find public work that has not yet been connected to a Journal story.';
    }

    /** @return LengthAwarePaginator<int, Model> */
    public function getOpportunities(): LengthAwarePaginator
    {
        return app(StoryOpportunityService::class)->paginate(
            type: PostMediaType::tryFrom($this->mediaType),
            search: $this->search,
            page: $this->getPage('opportunities'),
        );
    }

    /** @return array<string, int> */
    public function getOpportunityCounts(): array
    {
        return app(StoryOpportunityService::class)->counts();
    }

    /** @return array<string, string> */
    public function getMediaTypeOptions(): array
    {
        return collect(PostMediaType::cases())
            ->mapWithKeys(fn (PostMediaType $type): array => [$type->value => $type->label()])
            ->all();
    }

    public function updatedMediaType(): void
    {
        if ($this->mediaType !== 'all' && PostMediaType::tryFrom($this->mediaType) === null) {
            $this->mediaType = 'all';
        }

        $this->resetPage('opportunities');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('opportunities');
    }

    public function createJournalDraftAction(): Action
    {
        return CreateJournalDraftAction::make()
            ->record(fn (array $arguments): ?Model => $this->resolveOpportunityRecord($arguments));
    }

    /** @param array<string, mixed> $arguments */
    protected function resolveOpportunityRecord(array $arguments): ?Model
    {
        $type = PostMediaType::tryFrom((string) ($arguments['type'] ?? ''));
        $id = filter_var($arguments['id'] ?? null, FILTER_VALIDATE_INT);

        if ($type === null || $id === false || $id < 1) {
            return null;
        }

        return app(StoryOpportunityService::class)->find($type, $id);
    }

    public function mediaTypeFor(Model $record): PostMediaType
    {
        return PostMediaType::forModel($record)
            ?? throw new \LogicException('Unsupported story opportunity model.');
    }

    public function sourceDescription(Model $record): ?string
    {
        $description = match (true) {
            $record instanceof Album => $record->album_artist ?: $record->artist,
            $record instanceof Track => $record->artist,
            $record instanceof Artwork,
            $record instanceof Collection,
            $record instanceof Playlist => $record->description,
            default => null,
        };

        return filled($description) ? str((string) $description)->squish()->limit(120)->toString() : null;
    }

    public function publicUrl(Model $record): string
    {
        return match (true) {
            $record instanceof Artwork => route('artworks.show', $record),
            $record instanceof Collection => route('collections.show', $record),
            $record instanceof Album => route('music.albums.show', $record),
            $record instanceof Playlist => route('music.playlists.show', $record),
            $record instanceof Track => route('music.tracks.show', $record),
            default => throw new \LogicException('Unsupported story opportunity model.'),
        };
    }
}
