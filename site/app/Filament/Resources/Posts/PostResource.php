<?php

namespace App\Filament\Resources\Posts;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\Pages\ManagePostConnections;
use App\Models\Post;
use App\Services\PostReadiness;
use App\Services\PostWorkflowService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Journal posts';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Story')
                ->description('Build the public article. Drafts may be saved before the body or metadata is complete.')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('slug')
                        ->maxLength(255)
                        ->helperText('Leave empty to generate it from the title. Avoid changing a shared slug until redirect support is available.'),
                    Toggle::make('featured')
                        ->label('Feature this post')
                        ->helperText('Featuring controls placement only; it never publishes the post.')
                        ->inline(false),
                    Textarea::make('excerpt')
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('Used on Journal cards and as the default sharing description.')
                        ->columnSpanFull(),
                    FileUpload::make('cover_image_path')
                        ->label('Cover image')
                        ->disk('local')
                        ->directory('posts/covers')
                        ->visibility('private')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(25600),
                    TextInput::make('cover_alt_text')
                        ->label('Cover image alternative text')
                        ->maxLength(500)
                        ->helperText('Describe meaningful visual content. The readiness check flags a missing description when a cover is present.'),
                    MarkdownEditor::make('body')
                        ->label('Post content')
                        ->helperText('You can save an incomplete draft. Visible content is required before marking it ready.')
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? '')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Private editorial workspace')
                ->description('Private — never published or sent to AI unless explicitly selected.')
                ->columns(2)
                ->schema([
                    Textarea::make('editorial_brief')
                        ->label('Private editorial brief')
                        ->rows(6)
                        ->helperText('Capture the story goal, audience, angle, and desired outcome.'),
                    Textarea::make('editorial_notes')
                        ->label('Private editorial notes')
                        ->rows(6)
                        ->helperText('Keep research reminders, open questions, and follow-up ideas here.'),
                ])
                ->columnSpanFull(),
            Section::make('Search and sharing')
                ->description('Optional overrides. Empty fields use the post title and excerpt.')
                ->columns(2)
                ->schema([
                    TextInput::make('seo_title')->maxLength(70),
                    Textarea::make('seo_description')->rows(2)->maxLength(320),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('cover_image_path')
                    ->getStateUsing(fn (Post $record): ?string => $record->cover_url)
                    ->square()
                    ->label('Cover'),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Post $record): string => $record->summary)
                    ->wrap(),
                TextColumn::make('workflow_status')
                    ->label('Status')
                    ->getStateUsing(fn (Post $record): PostStatus => $record->effectiveStatusAt())
                    ->formatStateUsing(fn (PostStatus $state, Post $record): string => static::effectiveStatusLabel($record))
                    ->badge()
                    ->color(fn (PostStatus $state): string => $state->getColor())
                    ->icon(fn (PostStatus $state): string => $state->getIcon()),
                TextColumn::make('effective_publication_at')
                    ->label('Publication time')
                    ->getStateUsing(fn (Post $record) => $record->effectiveStatusAt() === PostStatus::Scheduled
                        ? $record->scheduled_at
                        : $record->effectivePublishedAt())
                    ->dateTime('M j, Y H:i')
                    ->placeholder('Not set')
                    ->description(fn (Post $record): ?string => match (true) {
                        $record->status === PostStatus::Scheduled && $record->effectiveStatusAt() === PostStatus::Published => 'Published from schedule',
                        $record->effectiveStatusAt() === PostStatus::Scheduled => 'Scheduled',
                        $record->effectiveStatusAt() === PostStatus::Published => 'Published',
                        default => null,
                    }),
                TextColumn::make('tags.name')
                    ->label('Tags')
                    ->badge()
                    ->limitList(3)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('media_items_count')
                    ->counts('mediaItems')
                    ->label('Media')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('featured')->boolean(),
            ])
            ->filters([
                SelectFilter::make('workflow_status')
                    ->label('Status')
                    ->options(collect(PostStatus::cases())->mapWithKeys(
                        fn (PostStatus $status): array => [$status->value => $status->getLabel()],
                    )->all())
                    ->query(fn (Builder $query, array $data): Builder => static::applyEffectiveStatusFilter(
                        $query,
                        PostStatus::tryFrom((string) ($data['value'] ?? '')),
                    )),
                TernaryFilter::make('featured'),
                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::previewAction(),
                    static::readinessAction(),
                    ...static::workflowActions(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Post actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview saved version')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->authorize('preview')
            ->url(
                fn (Post $record): string => route('admin.posts.preview', $record),
                shouldOpenInNewTab: true,
            );
    }

    public static function readinessAction(): Action
    {
        return Action::make('readiness')
            ->label('Readiness check')
            ->icon('heroicon-o-clipboard-document-check')
            ->authorize('view')
            ->modalHeading('Publication readiness')
            ->modalContent(fn (Post $record) => view('filament.posts.readiness-checklist', [
                'report' => app(PostReadiness::class)->evaluate($record),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /** @return list<Action> */
    public static function workflowActions(): array
    {
        return [
            Action::make('markReady')
                ->label('Mark ready')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->authorize('markReady')
                ->visible(fn (Post $record): bool => $record->effectiveStatusAt() === PostStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('The shared readiness checks will run before this post can enter the Ready state.')
                ->action(fn (Post $record) => static::performTransition(
                    $record,
                    fn (PostWorkflowService $workflow): Post => $workflow->markReady($record),
                    'Post marked ready.',
                )),
            Action::make('returnToDraft')
                ->label('Return to draft')
                ->icon('heroicon-o-pencil-square')
                ->authorize('revertToDraft')
                ->visible(fn (Post $record): bool => $record->effectiveStatusAt() === PostStatus::Ready)
                ->requiresConfirmation()
                ->action(fn (Post $record) => static::performTransition(
                    $record,
                    fn (PostWorkflowService $workflow): Post => $workflow->revertToDraft($record),
                    'Post returned to draft.',
                )),
            static::scheduleAction(),
            static::rescheduleAction(),
            Action::make('cancelSchedule')
                ->label('Cancel schedule')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->authorize('cancelSchedule')
                ->visible(fn (Post $record): bool => $record->effectiveStatusAt() === PostStatus::Scheduled)
                ->requiresConfirmation()
                ->modalDescription('The post will return to Ready and will not become public at the scheduled time.')
                ->action(fn (Post $record) => static::performTransition(
                    $record,
                    fn (PostWorkflowService $workflow): Post => $workflow->cancelSchedule($record),
                    'Publication schedule cancelled.',
                )),
            Action::make('publishNow')
                ->label('Publish now')
                ->icon('heroicon-o-globe-alt')
                ->color('success')
                ->authorize('publishNow')
                ->visible(fn (Post $record): bool => in_array($record->effectiveStatusAt(), [PostStatus::Ready, PostStatus::Scheduled], true))
                ->requiresConfirmation()
                ->modalDescription('This immediately makes the last saved version public.')
                ->action(fn (Post $record) => static::performTransition(
                    $record,
                    fn (PostWorkflowService $workflow): Post => $workflow->publishNow($record),
                    'Post published.',
                )),
            Action::make('unpublish')
                ->label('Unpublish')
                ->icon('heroicon-o-eye-slash')
                ->color('danger')
                ->authorize('unpublish')
                ->visible(fn (Post $record): bool => $record->effectiveStatusAt() === PostStatus::Published)
                ->requiresConfirmation()
                ->modalDescription('The post will be removed from every public Journal surface and return to Ready.')
                ->action(fn (Post $record) => static::performTransition(
                    $record,
                    fn (PostWorkflowService $workflow): Post => $workflow->unpublish($record),
                    'Post unpublished.',
                )),
        ];
    }

    protected static function scheduleAction(): Action
    {
        return Action::make('schedule')
            ->label('Schedule publication')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->authorize('schedule')
            ->visible(fn (Post $record): bool => $record->effectiveStatusAt() === PostStatus::Ready)
            ->schema([static::publicationTimePicker()])
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Schedule')
            ->action(fn (array $data, Post $record) => static::performTransition(
                $record,
                fn (PostWorkflowService $workflow): Post => $workflow->schedule(
                    $record,
                    CarbonImmutable::parse((string) $data['scheduled_at'], config('app.timezone')),
                ),
                'Post scheduled.',
            ));
    }

    protected static function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->label('Reschedule')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->authorize('schedule')
            ->visible(fn (Post $record): bool => $record->effectiveStatusAt() === PostStatus::Scheduled)
            ->fillForm(fn (Post $record): array => ['scheduled_at' => $record->scheduled_at])
            ->schema([static::publicationTimePicker()])
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Update schedule')
            ->action(fn (array $data, Post $record) => static::performTransition(
                $record,
                fn (PostWorkflowService $workflow): Post => $workflow->schedule(
                    $record,
                    CarbonImmutable::parse((string) $data['scheduled_at'], config('app.timezone')),
                ),
                'Publication schedule updated.',
            ));
    }

    protected static function publicationTimePicker(): DateTimePicker
    {
        return DateTimePicker::make('scheduled_at')
            ->label('Publication time (UTC)')
            ->helperText('Times are stored in UTC. Choose a future date and time.')
            ->required()
            ->seconds(false)
            ->native(false)
            ->minDate(now()->addMinute());
    }

    /** @param Closure(PostWorkflowService): Post $transition */
    protected static function performTransition(Post $record, Closure $transition, string $successMessage): void
    {
        try {
            $transition(app(PostWorkflowService::class));
            $record->refresh();

            Notification::make()
                ->success()
                ->title($successMessage)
                ->send();
        } catch (DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('Workflow change could not be completed')
                ->body($exception->getMessage())
                ->send();
        }
    }

    protected static function effectiveStatusLabel(Post $record): string
    {
        $effective = $record->effectiveStatusAt();

        return $record->status === PostStatus::Scheduled && $effective === PostStatus::Published
            ? 'Published (scheduled)'
            : $effective->getLabel();
    }

    protected static function applyEffectiveStatusFilter(Builder $query, ?PostStatus $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        $now = now();

        return match ($status) {
            PostStatus::Draft => $query->whereNot(function (Builder $query) use ($now): void {
                $query->where(function (Builder $query) use ($now): void {
                    static::applyValidReadyState($query, $now);
                })->orWhere(function (Builder $query): void {
                    static::applyValidScheduledState($query);
                })->orWhere(function (Builder $query) use ($now): void {
                    static::applyValidPublishedState($query, $now);
                });
            }),
            PostStatus::Ready => static::applyValidReadyState($query, $now),
            PostStatus::Scheduled => $query
                ->where('status', PostStatus::Scheduled->value)
                ->where('published', true)
                ->whereNotNull('scheduled_at')
                ->whereColumn('published_at', 'scheduled_at')
                ->where('scheduled_at', '>', $now),
            PostStatus::Published => $query->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $query) use ($now): void {
                    $query
                        ->where('status', PostStatus::Published->value)
                        ->where('published', true)
                        ->whereNull('scheduled_at')
                        ->whereNotNull('published_at')
                        ->where('published_at', '<=', $now);
                })->orWhere(function (Builder $query) use ($now): void {
                    $query
                        ->where('status', PostStatus::Scheduled->value)
                        ->where('published', true)
                        ->whereNotNull('scheduled_at')
                        ->whereColumn('published_at', 'scheduled_at')
                        ->where('scheduled_at', '<=', $now);
                });
            }),
        };
    }

    protected static function applyValidReadyState(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where('status', PostStatus::Ready->value)
            ->where('published', false)
            ->whereNull('scheduled_at')
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', $at);
            });
    }

    protected static function applyValidScheduledState(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Scheduled->value)
            ->where('published', true)
            ->whereNotNull('scheduled_at')
            ->whereNotNull('published_at')
            ->whereColumn('published_at', 'scheduled_at');
    }

    protected static function applyValidPublishedState(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where('status', PostStatus::Published->value)
            ->where('published', true)
            ->whereNull('scheduled_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
            'connections' => ManagePostConnections::route('/{record}/connections'),
        ];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditPost::class,
            ManagePostConnections::class,
        ]);
    }
}
