<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Services\JournalDraftPlanningService;
use App\Services\JournalSourceImageResolver;
use App\Services\PublicStoryConnections;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class CreateJournalDraftAction extends Action
{
    protected bool $allowsPrivateSources = false;

    public static function getDefaultName(): ?string
    {
        return 'createJournalDraft';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Create Journal draft')
            ->icon('heroicon-o-document-plus')
            ->color('info')
            ->modalHeading('Create a Journal draft from this source')
            ->modalDescription(fn (?Model $record): string => $record !== null
                && app(PublicStoryConnections::class)->mediaIsPublic($record)
                    ? 'Creates a private Journal draft and connects this public source. The source record, publication state, and files are not changed.'
                    : 'Creates a private Journal draft for advance planning. This source remains private, and its artwork will not be copied until it is public.')
            ->schema([
                Select::make('post_template_id')
                    ->label('Template')
                    ->placeholder('Start without a template')
                    ->helperText('Optional starting copy for the new draft. The template itself is not changed.')
                    ->options(fn (): array => $this->templateOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->templateOptions($search))
                    ->optionsLimit(50)
                    ->searchable()
                    ->native(false),
                Toggle::make('copy_shared_tags')
                    ->label('Copy shared source tags')
                    ->helperText('Adds public archive tags that already describe this source. Existing source tags are not changed.')
                    ->default(false)
                    ->inline(false),
                Toggle::make('use_source_artwork')
                    ->label('Use source artwork as Journal cover')
                    ->helperText('Copies a stable private snapshot into the Journal post. Later source changes will not replace it.')
                    ->default(fn (?Model $record): bool => $this->hasSourceArtwork($record))
                    ->visible(fn (?Model $record): bool => $this->hasSourceArtwork($record))
                    ->inline(false),
            ])
            ->authorize(fn (): bool => Gate::allows('create', Post::class))
            ->visible(fn (?Model $record): bool => $record !== null
                && $record->exists
                && ($this->allowsPrivateSources
                    || app(PublicStoryConnections::class)->mediaIsPublic($record)))
            ->action(function (?Model $record, array $data, self $action): void {
                Gate::authorize('create', Post::class);

                if ($record === null) {
                    Notification::make()
                        ->danger()
                        ->title('Journal draft could not be created')
                        ->body('The selected source is no longer available. Reload the page and try again.')
                        ->send();
                    $action->failure();

                    return;
                }

                try {
                    $templateId = $data['post_template_id'] ?? null;
                    $template = filled($templateId)
                        ? PostTemplate::query()->findOrFail((int) $templateId)
                        : null;
                    $planning = app(JournalDraftPlanningService::class);
                    $arguments = [
                        'source' => $record,
                        'template' => $template,
                        'copySharedTags' => (bool) ($data['copy_shared_tags'] ?? false),
                        'useSourceArtwork' => (bool) ($data['use_source_artwork'] ?? false),
                    ];
                    $post = $this->allowsPrivateSources
                        ? $planning->createFromSavedSource(...$arguments)
                        : $planning->createFromPublicSource(...$arguments);
                } catch (DomainException|ModelNotFoundException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Journal draft could not be created')
                        ->body($exception instanceof DomainException
                            ? $exception->getMessage()
                            : 'The selected Journal template is no longer available. Reload the page and try again.')
                        ->send();
                    $action->failure();

                    return;
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title('Journal draft could not be created')
                        ->body('The source remains unchanged. Check its artwork file and try again.')
                        ->send();
                    $action->failure();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Journal draft created')
                    ->body('The source is unchanged. Continue writing or open Connections to adjust the draft.')
                    ->send();

                $action->redirect(PostResource::getUrl('edit', ['record' => $post]));
            });
    }

    public function allowPrivateSources(bool $condition = true): static
    {
        $this->allowsPrivateSources = $condition;

        return $this;
    }

    private function hasSourceArtwork(?Model $record): bool
    {
        return $record !== null
            && app(PublicStoryConnections::class)->mediaIsPublic($record)
            && app(JournalSourceImageResolver::class)->resolve($record) !== null;
    }

    /** @return array<int, string> */
    private function templateOptions(?string $search = null): array
    {
        $search = Str::of((string) $search)->squish()->limit(100, '')->toString();

        return PostTemplate::query()
            ->active()
            ->when($search !== '', fn ($query) => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }
}
