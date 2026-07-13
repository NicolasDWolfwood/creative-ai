<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;
use App\Services\JournalAiApplicationService;
use App\Services\JournalAiPresentation;
use App\Services\JournalAiRunService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class ManagePostAssistant extends ManageRelatedRecords
{
    protected static string $resource = PostResource::class;

    protected static string $relationship = 'aiRuns';

    protected static ?string $navigationLabel = 'AI assistant';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    /** @var array<string, mixed> */
    #[Locked]
    public array $pendingAiRequest = [];

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Post
            && ! $record->trashed()
            && Gate::allows('viewAny', PostAiRun::class);
    }

    public function getTitle(): string
    {
        return 'AI assistant for '.$this->post()->title;
    }

    protected function getHeaderActions(): array
    {
        $requestActions = collect(PostAiOperation::cases())
            ->map(fn (PostAiOperation $operation): Action => $this->requestAction($operation))
            ->all();

        return [
            ActionGroup::make($requestActions)
                ->label('Ask AI')
                ->icon('heroicon-o-sparkles')
                ->button(),
            Action::make('confirmAiRequest')
                ->label('Confirm Journal AI request')
                ->visible(fn (): bool => $this->pendingAiRequest !== [])
                ->modalHeading(fn (): string => 'Review '.($this->pendingAiRequest['operation_label'] ?? 'Journal AI request'))
                ->modalDescription('This is the exact saved context and destination that will be used. No result can edit or publish the post automatically.')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalContent(fn () => view('filament.posts.ai-context-preview', [
                    'pending' => $this->pendingAiRequest,
                ]))
                ->schema([
                    Checkbox::make('acknowledged')
                        ->label('I reviewed this exact outbound content and destination, and I want to queue this request.')
                        ->accepted()
                        ->required(),
                ])
                ->modalSubmitActionLabel('Acknowledge and queue')
                ->action(fn (): mixed => $this->confirmPendingRequest()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Saved-post assistant runs')
            ->description('AI works only from the last saved version. Suggestions remain inert until an administrator deliberately applies an eligible result.')
            ->defaultSort('id', 'desc')
            ->poll(fn (): ?string => $this->post()->aiRuns()
                ->whereIn('status', [
                    PostAiRunStatus::Queued->value,
                    PostAiRunStatus::Processing->value,
                ])
                ->exists() ? '3s' : null)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'requester',
                'appliedBy',
                'sourceRevision',
                'appliedRevision',
            ]))
            ->columns([
                TextColumn::make('operation')
                    ->label('Operation')
                    ->formatStateUsing(fn (PostAiOperation $state): string => $state->label())
                    ->badge()
                    ->color('info'),
                TextColumn::make('status')
                    ->formatStateUsing(fn (PostAiRunStatus $state): string => str($state->value)->headline()->toString())
                    ->badge()
                    ->color(fn (PostAiRunStatus $state): string => $this->statusColor($state))
                    ->description(fn (PostAiRun $record): ?string => $record->stale_reason ?: $record->error_category),
                TextColumn::make('provider')
                    ->formatStateUsing(fn (string $state, PostAiRun $record): string => $state.' · '.$record->model)
                    ->description(fn (PostAiRun $record): string => $record->external_processing ? 'External processing' : 'Private destination')
                    ->wrap(),
                TextColumn::make('queued_at')
                    ->label('Requested')
                    ->dateTime('M j, Y H:i:s')
                    ->description(fn (PostAiRun $record): string => $record->queued_at?->diffForHumans() ?? 'Unknown time')
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state).' ms')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->viewResultAction(),
                    $this->applyResultAction(),
                    $this->undoResultAction(),
                    $this->cancelRunAction(),
                    $this->prioritizeRunAction(),
                    $this->dismissRunAction(),
                    $this->repeatRunAction(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Assistant run actions'),
            ])
            ->emptyStateHeading('No Journal AI runs yet')
            ->emptyStateDescription('Choose Ask AI to review the exact saved context before queueing an editorial suggestion.');
    }

    protected function post(): Post
    {
        /** @var Post */
        return $this->getRecord();
    }

    private function requestAction(PostAiOperation $operation): Action
    {
        return Action::make('request_'.$operation->value)
            ->label($operation->label())
            ->icon($this->operationIcon($operation))
            ->authorize(fn (): bool => Gate::allows('request', [PostAiRun::class, $this->post()]))
            ->modalHeading($operation->label())
            ->modalDescription('Select only the saved context this request needs. Private fields and detailed artwork process context stay excluded unless you opt in explicitly.')
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn (): array => [
                'fields' => $this->defaultFields($operation),
                'include_editorial_brief' => false,
                'include_editorial_notes' => false,
                'include_tags' => false,
                'include_connected_media' => false,
                'include_connected_media_prompts' => false,
                'include_connected_media_process_notes' => false,
                'passage_field' => 'body',
                'passage_text' => null,
            ])
            ->schema($this->requestSchema($operation))
            ->modalSubmitActionLabel('Review exact request')
            ->action(function (array $data) use ($operation): void {
                $this->prepareRequest($operation, $this->selection($operation, $data));
                $this->replaceMountedAction('confirmAiRequest');
            });
    }

    /** @return array<int, mixed> */
    private function requestSchema(PostAiOperation $operation): array
    {
        return [
            CheckboxList::make('fields')
                ->label('Saved Journal fields')
                ->options([
                    'title' => 'Title',
                    'excerpt' => 'Excerpt',
                    'body' => 'Body',
                    'cover_alt_text' => 'Cover alternative text',
                    'seo_title' => 'SEO title',
                    'seo_description' => 'SEO description',
                ])
                ->columns(2)
                ->bulkToggleable()
                ->helperText('The next step shows the exact outbound values before anything is queued.'),
            Select::make('passage_field')
                ->label('Passage source')
                ->options([
                    'title' => 'Title',
                    'excerpt' => 'Excerpt',
                    'body' => 'Body',
                ])
                ->required()
                ->native(false)
                ->visible($operation === PostAiOperation::ImprovePassage),
            Textarea::make('passage_text')
                ->label('Exact saved passage')
                ->rows(8)
                ->required()
                ->helperText('Paste one unique passage from the saved field. The server derives and protects its Unicode character offsets; no replacement text is accepted from the browser.')
                ->visible($operation === PostAiOperation::ImprovePassage),
            Toggle::make('include_editorial_brief')
                ->label('Include private editorial brief')
                ->helperText('Private — explicit opt-in for this request only.')
                ->inline(false),
            Toggle::make('include_editorial_notes')
                ->label('Include private editorial notes')
                ->helperText('Private — explicit opt-in for this request only.')
                ->inline(false),
            Toggle::make('include_tags')
                ->label('Include current shared tags')
                ->inline(false),
            Toggle::make('include_connected_media')
                ->label('Include public connected-media metadata')
                ->helperText('Effectively private or scheduled media is excluded by the server.')
                ->live()
                ->inline(false),
            Toggle::make('include_connected_media_prompts')
                ->label('Also include public artwork prompts')
                ->visible(fn (Get $get): bool => (bool) $get('include_connected_media'))
                ->inline(false),
            Toggle::make('include_connected_media_process_notes')
                ->label('Also include public artwork process notes')
                ->visible(fn (Get $get): bool => (bool) $get('include_connected_media'))
                ->inline(false),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function selection(PostAiOperation $operation, array $data): array
    {
        $selection = [
            'fields' => collect($data['fields'] ?? [])->filter(fn (mixed $field): bool => is_string($field))->values()->all(),
            'include_editorial_brief' => (bool) ($data['include_editorial_brief'] ?? false),
            'include_editorial_notes' => (bool) ($data['include_editorial_notes'] ?? false),
            'include_tags' => (bool) ($data['include_tags'] ?? false),
            'include_connected_media' => (bool) ($data['include_connected_media'] ?? false),
            'include_connected_media_prompts' => (bool) ($data['include_connected_media_prompts'] ?? false),
            'include_connected_media_process_notes' => (bool) ($data['include_connected_media_process_notes'] ?? false),
        ];

        if ($operation !== PostAiOperation::ImprovePassage) {
            return $selection;
        }

        $field = (string) ($data['passage_field'] ?? '');

        if (! in_array($field, ['title', 'excerpt', 'body'], true)) {
            throw ValidationException::withMessages([
                'passage_field' => 'Choose a supported saved passage field.',
            ]);
        }

        $needle = str_replace(["\r\n", "\r"], "\n", (string) ($data['passage_text'] ?? ''));
        $source = str_replace(["\r\n", "\r"], "\n", (string) $this->post()->getAttribute($field));
        $start = $needle === '' ? false : mb_strpos($source, $needle, 0, 'UTF-8');

        if ($start === false) {
            throw ValidationException::withMessages([
                'passage_text' => 'The passage was not found in the selected saved field. Save or copy the exact passage and try again.',
            ]);
        }

        if (mb_strpos($source, $needle, $start + 1, 'UTF-8') !== false) {
            throw ValidationException::withMessages([
                'passage_text' => 'That passage appears more than once. Select a longer unique passage.',
            ]);
        }

        $selection['passage'] = [
            'field' => $field,
            'start' => $start,
            'end' => $start + mb_strlen($needle, 'UTF-8'),
        ];

        return $selection;
    }

    /** @param array<string, mixed> $selection */
    private function prepareRequest(
        PostAiOperation $operation,
        array $selection,
        ?PostAiRun $repeatOf = null,
    ): void {
        $actor = $this->actor();

        try {
            $preview = app(JournalAiRunService::class)->preview($this->post(), $operation, $selection, $actor);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'fields' => $exception->getMessage(),
            ]);
        }

        $this->pendingAiRequest = [
            'operation' => $operation->value,
            'operation_label' => $operation->label(),
            'selection' => $selection,
            'repeat_of_id' => $repeatOf?->getKey(),
            'context_hash' => $preview->contextHash,
            'provider_profile_hash' => $preview->providerProfileHash,
            'request_hash' => $preview->requestHash,
            'source_hash' => $preview->sourceHash,
            'context_manifest' => $preview->contextManifest,
            'provider' => $preview->provider,
            'model' => $preview->model,
            'endpoint' => $preview->endpoint,
            'external_processing' => $preview->externalProcessing,
        ];
    }

    private function confirmPendingRequest(): void
    {
        $operation = PostAiOperation::tryFrom((string) ($this->pendingAiRequest['operation'] ?? ''));
        $selection = $this->pendingAiRequest['selection'] ?? null;

        if ($operation === null || ! is_array($selection)) {
            throw ValidationException::withMessages([
                'acknowledged' => 'The request preview expired. Review the exact context again.',
            ]);
        }

        $runs = app(JournalAiRunService::class);
        $repeatId = $this->pendingAiRequest['repeat_of_id'] ?? null;

        try {
            if (is_int($repeatId) || (is_string($repeatId) && ctype_digit($repeatId))) {
                $parent = PostAiRun::query()
                    ->whereBelongsTo($this->post())
                    ->findOrFail((int) $repeatId);
                $run = in_array($parent->status, [PostAiRunStatus::Ready, PostAiRunStatus::Applied], true)
                    ? $runs->regenerate(
                        $parent,
                        $this->actor(),
                        (string) $this->pendingAiRequest['context_hash'],
                        (string) $this->pendingAiRequest['provider_profile_hash'],
                        (string) $this->pendingAiRequest['request_hash'],
                    )
                    : $runs->retry(
                        $parent,
                        $this->actor(),
                        (string) $this->pendingAiRequest['context_hash'],
                        (string) $this->pendingAiRequest['provider_profile_hash'],
                        (string) $this->pendingAiRequest['request_hash'],
                    );
            } else {
                $run = $runs->request(
                    $this->post(),
                    $operation,
                    $selection,
                    $this->actor(),
                    (string) $this->pendingAiRequest['context_hash'],
                    (string) $this->pendingAiRequest['provider_profile_hash'],
                    (string) $this->pendingAiRequest['request_hash'],
                );
            }
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'acknowledged' => $exception->getMessage(),
            ]);
        }

        $this->pendingAiRequest = [];

        Notification::make()
            ->success()
            ->title('Journal AI request queued.')
            ->body('Run #'.$run->getKey().' uses the exact acknowledged saved context. It cannot apply or publish itself.')
            ->send();
    }

    private function viewResultAction(): Action
    {
        return Action::make('viewResult')
            ->label('View result')
            ->icon('heroicon-o-eye')
            ->authorize(fn (PostAiRun $record): bool => Gate::allows('view', $record))
            ->visible(fn (PostAiRun $record): bool => is_array($record->structured_result))
            ->modalHeading(fn (PostAiRun $record): string => $record->operation->label().' · run #'.$record->getKey())
            ->modalDescription('Read-only AI suggestion. Claims listed for verification have not been fact-checked.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalContent(fn (PostAiRun $record) => $this->resultView($record))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function applyResultAction(): Action
    {
        return Action::make('applyResult')
            ->label(fn (PostAiRun $record): string => match ($record->operation) {
                PostAiOperation::Outline => 'Insert outline',
                PostAiOperation::ImprovePassage => 'Replace passage',
                PostAiOperation::Metadata => 'Apply metadata',
                default => 'Apply',
            })
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->authorize(fn (PostAiRun $record): bool => Gate::allows('apply', $record))
            ->visible(fn (PostAiRun $record): bool => $record->status === PostAiRunStatus::Ready
                && in_array($record->operation, [
                    PostAiOperation::Outline,
                    PostAiOperation::ImprovePassage,
                    PostAiOperation::Metadata,
                ], true))
            ->disabled(fn (PostAiRun $record): bool => ! app(JournalAiApplicationService::class)->canApply($record))
            ->requiresConfirmation()
            ->modalHeading('Apply this suggestion to the saved post?')
            ->modalDescription('Only the operation-specific fields selected below can change. The URL, workflow, schedule, publication state, featured placement, private notes, tags, and media connections stay untouched. A revision is created for undo.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalContent(fn (PostAiRun $record) => $this->resultView($record, comparisonOnly: true))
            ->schema(fn (PostAiRun $record): array => $this->applySchema($record))
            ->modalSubmitActionLabel('Apply and create revision')
            ->action(function (array $data, PostAiRun $record): void {
                try {
                    app(JournalAiApplicationService::class)->apply($record, $this->actor(), $data);
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('Suggestion was not applied.')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('AI suggestion applied with a revision.')
                    ->body('Use Undo on this run or Journal History while no newer writing has replaced it.')
                    ->send();
            });
    }

    /** @return array<int, mixed> */
    private function applySchema(PostAiRun $run): array
    {
        if ($run->operation === PostAiOperation::Outline) {
            return [
                Select::make('mode')
                    ->label('Place the outline')
                    ->options([
                        'prepend' => 'Insert before the current body',
                        'append' => 'Append after the current body',
                    ])
                    ->required()
                    ->native(false),
            ];
        }

        if ($run->operation === PostAiOperation::Metadata) {
            return [
                CheckboxList::make('fields')
                    ->label('Apply only these suggestions')
                    ->options($this->metadataApplyOptions($run))
                    ->required()
                    ->minItems(1)
                    ->columns(2),
            ];
        }

        return [];
    }

    /** @return array<string, string> */
    private function metadataApplyOptions(PostAiRun $run): array
    {
        $labels = [
            'excerpt' => 'Excerpt',
            'cover_alt_text' => 'Cover alternative text',
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
        ];
        $limits = [
            'excerpt' => 500,
            'cover_alt_text' => 500,
            'seo_title' => 70,
            'seo_description' => 320,
        ];

        try {
            $result = app(JournalAiPresentation::class)->result($run);
        } catch (DomainException) {
            return [];
        }

        return collect($labels)
            ->filter(fn (string $label, string $field): bool => is_string($result[$field] ?? null)
                && trim($result[$field]) !== ''
                && mb_strlen($result[$field], 'UTF-8') <= $limits[$field]
                && $run->post?->getAttribute($field) !== $result[$field])
            ->all();
    }

    private function undoResultAction(): Action
    {
        return Action::make('undoResult')
            ->label('Undo AI application')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->authorize(fn (PostAiRun $record): bool => Gate::allows('undo', $record))
            ->visible(fn (PostAiRun $record): bool => $record->status === PostAiRunStatus::Applied)
            ->disabled(fn (PostAiRun $record): bool => ! app(JournalAiApplicationService::class)->canUndo($record))
            ->requiresConfirmation()
            ->modalHeading('Undo this AI-assisted edit?')
            ->modalDescription('Restores the exact safe-content revision from before this run was applied. Newer writing blocks undo so it cannot be overwritten. URL, workflow, scheduling, publication, featured placement, private notes, tags, and connections are never restored by this action.')
            ->action(function (PostAiRun $record): void {
                try {
                    app(JournalAiApplicationService::class)->undo($record, $this->actor());
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('AI application was not undone.')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('AI-assisted edit undone with a new revision.')->send();
            });
    }

    private function cancelRunAction(): Action
    {
        return Action::make('cancelRun')
            ->label('Cancel')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize(fn (PostAiRun $record): bool => Gate::allows('cancel', $record))
            ->visible(fn (PostAiRun $record): bool => in_array($record->status, [
                PostAiRunStatus::Queued,
                PostAiRunStatus::Processing,
            ], true))
            ->requiresConfirmation()
            ->action(fn (PostAiRun $record): PostAiRun => app(JournalAiRunService::class)->cancel($record, $this->actor()));
    }

    private function prioritizeRunAction(): Action
    {
        return Action::make('prioritizeRun')
            ->label('Prioritize')
            ->icon('heroicon-o-arrow-up-circle')
            ->authorize(fn (PostAiRun $record): bool => Gate::allows('prioritize', $record))
            ->visible(fn (PostAiRun $record): bool => $record->status === PostAiRunStatus::Queued)
            ->action(fn (PostAiRun $record): PostAiRun => app(JournalAiRunService::class)->prioritize($record, $this->actor()));
    }

    private function dismissRunAction(): Action
    {
        return Action::make('dismissRun')
            ->label('Dismiss')
            ->icon('heroicon-o-archive-box')
            ->authorize(fn (PostAiRun $record): bool => Gate::allows('dismiss', $record))
            ->visible(fn (PostAiRun $record): bool => $record->status === PostAiRunStatus::Ready)
            ->requiresConfirmation()
            ->action(fn (PostAiRun $record): PostAiRun => app(JournalAiRunService::class)->dismiss($record, $this->actor()));
    }

    private function repeatRunAction(): Action
    {
        return Action::make('repeatRun')
            ->label(fn (PostAiRun $record): string => in_array($record->status, [PostAiRunStatus::Ready, PostAiRunStatus::Applied], true)
                ? 'Regenerate'
                : 'Retry')
            ->icon('heroicon-o-arrow-path')
            ->authorize(fn (PostAiRun $record): bool => Gate::allows('retry', $record))
            ->visible(fn (PostAiRun $record): bool => in_array($record->status, [
                PostAiRunStatus::Ready,
                PostAiRunStatus::Applied,
                PostAiRunStatus::Failed,
                PostAiRunStatus::Stale,
                PostAiRunStatus::Cancelled,
                PostAiRunStatus::Dismissed,
            ], true))
            ->action(function (PostAiRun $record): void {
                $selection = $record->context_manifest['selection'] ?? null;

                if (! is_array($selection)) {
                    Notification::make()->danger()->title('This run has no reusable context selection.')->send();

                    return;
                }

                if (! $this->repeatSelectionIsCurrent($record)) {
                    Notification::make()
                        ->danger()
                        ->title('The selected saved passage changed.')
                        ->body('Start a new Improve selected passage request and choose the exact current text.')
                        ->send();

                    return;
                }

                $this->prepareRequest($record->operation, $selection, $record);
                $this->replaceMountedAction('confirmAiRequest');
            });
    }

    private function repeatSelectionIsCurrent(PostAiRun $run): bool
    {
        if ($run->operation !== PostAiOperation::ImprovePassage) {
            return true;
        }

        $passage = $run->context_manifest['outbound']['selected_passage'] ?? null;

        if (! is_array($passage)
            || ! is_string($passage['field'] ?? null)
            || ! in_array($passage['field'], ['title', 'excerpt', 'body'], true)
            || ! is_int($passage['start'] ?? null)
            || ! is_int($passage['end'] ?? null)
            || ! is_string($passage['content'] ?? null)) {
            return false;
        }

        $source = str_replace(
            ["\r\n", "\r"],
            "\n",
            (string) $this->post()->getAttribute($passage['field']),
        );
        $length = mb_strlen($source, 'UTF-8');

        if ($passage['start'] < 0 || $passage['end'] <= $passage['start'] || $passage['end'] > $length) {
            return false;
        }

        $current = mb_substr(
            $source,
            $passage['start'],
            $passage['end'] - $passage['start'],
            'UTF-8',
        );

        return hash_equals($passage['content'], $current);
    }

    private function resultView(PostAiRun $run, bool $comparisonOnly = false): mixed
    {
        $presentation = app(JournalAiPresentation::class);

        try {
            $result = $presentation->result($run);
            $copyText = $presentation->copyText($run->operation, $result);
        } catch (Throwable) {
            $result = null;
            $copyText = null;
        }

        try {
            $fresh = $presentation->isFresh($run);
        } catch (Throwable) {
            $fresh = false;
        }

        return view('filament.posts.ai-result', [
            'run' => $run,
            'post' => $this->post()->refresh(),
            'result' => $result,
            'copyText' => $copyText,
            'fresh' => $fresh,
            'comparisonOnly' => $comparisonOnly,
        ]);
    }

    private function actor(): User
    {
        $actor = PostResource::authenticatedUser();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    /** @return list<string> */
    private function defaultFields(PostAiOperation $operation): array
    {
        return match ($operation) {
            PostAiOperation::Metadata => [
                'title',
                'excerpt',
                'body',
                'cover_alt_text',
                'seo_title',
                'seo_description',
            ],
            default => ['title', 'excerpt', 'body'],
        };
    }

    private function operationIcon(PostAiOperation $operation): string
    {
        return match ($operation) {
            PostAiOperation::Directions => 'heroicon-o-light-bulb',
            PostAiOperation::Outline => 'heroicon-o-list-bullet',
            PostAiOperation::EditorialReview => 'heroicon-o-document-magnifying-glass',
            PostAiOperation::ImprovePassage => 'heroicon-o-pencil-square',
            PostAiOperation::Metadata => 'heroicon-o-tag',
        };
    }

    private function statusColor(PostAiRunStatus $status): string
    {
        return match ($status) {
            PostAiRunStatus::Ready => 'success',
            PostAiRunStatus::Queued, PostAiRunStatus::Processing => 'info',
            PostAiRunStatus::Failed => 'danger',
            PostAiRunStatus::Stale, PostAiRunStatus::Cancelled => 'warning',
            PostAiRunStatus::Applied => 'primary',
            PostAiRunStatus::Dismissed => 'gray',
        };
    }
}
