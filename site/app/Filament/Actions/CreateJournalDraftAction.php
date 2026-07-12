<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Services\JournalDraftPlanningService;
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

class CreateJournalDraftAction extends Action
{
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
            ->modalDescription('Creates a private Journal draft and connects this public source. The source record, publication state, and files are not changed.')
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
            ])
            ->authorize(fn (): bool => Gate::allows('create', Post::class))
            ->visible(fn (?Model $record): bool => $record !== null
                && app(PublicStoryConnections::class)->mediaIsPublic($record))
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
                    $post = app(JournalDraftPlanningService::class)->createFromPublicSource(
                        source: $record,
                        template: $template,
                        copySharedTags: (bool) ($data['copy_shared_tags'] ?? false),
                    );
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
                }

                Notification::make()
                    ->success()
                    ->title('Journal draft created')
                    ->body('The source is unchanged. Continue writing or open Connections to adjust the draft.')
                    ->send();

                $action->redirect(PostResource::getUrl('edit', ['record' => $post]));
            });
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
