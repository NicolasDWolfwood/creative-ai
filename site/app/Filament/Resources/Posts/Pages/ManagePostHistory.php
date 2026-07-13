<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\PostRevision;
use App\Services\PostRevisionService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ManagePostHistory extends ManageRelatedRecords
{
    protected static string $resource = PostResource::class;

    protected static string $relationship = 'revisions';

    protected static ?string $navigationLabel = 'History';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    #[Locked]
    public string $expectedContentFingerprint = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->refreshExpectedContentFingerprint();
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Post
            && Gate::allows('view', $record);
    }

    public function getTitle(): string
    {
        return 'History for '.$this->post()->title;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Immutable revisions')
            ->description('Journal revisions are an audit trail. Preview any saved snapshot, or deliberately restore only its public writing and metadata fields.')
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Saved')
                    ->dateTime('M j, Y H:i:s')
                    ->description(fn (PostRevision $record): string => $record->created_at?->diffForHumans() ?? 'Unknown time')
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->formatStateUsing(fn (?string $state, PostRevision $record): string => filled($state)
                        ? $state
                        : static::provenanceLabel($record->provenance))
                    ->wrap(),
                TextColumn::make('changed_fields')
                    ->label('Changed')
                    ->getStateUsing(fn (PostRevision $record): string => static::changeSummary($record))
                    ->badge()
                    ->color('info')
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label('Saved by')
                    ->placeholder('System'),
                TextColumn::make('provenance')
                    ->label('Source')
                    ->formatStateUsing(fn (?string $state): string => static::provenanceLabel($state))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('previewRevision')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (PostRevision $record): string => 'Revision from '.($record->created_at?->format('M j, Y H:i:s') ?? 'an unknown time'))
                    ->modalDescription('This is a read-only historical snapshot. Private editorial notes and workflow controls are not included in restorable content.')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalContent(fn (PostRevision $record) => view('filament.posts.revision-preview', [
                        'revision' => $record,
                        'fields' => static::previewFields(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('restoreRevision')
                    ->label('Restore safe fields')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->authorize(fn (): bool => Gate::allows('update', $this->post()))
                    ->hidden(fn (): bool => $this->post()->trashed())
                    ->requiresConfirmation()
                    ->modalHeading('Restore this revision’s safe fields?')
                    ->modalDescription('Restores title, excerpt, body, cover image, cover alternative text, SEO title, and SEO description. It never restores the slug, publication or scheduling state, featured placement, private editorial notes, tags, or media connections.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Restore note (optional)')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Describe why this historical writing is being restored. The restore itself creates a new immutable revision.'),
                    ])
                    ->modalSubmitActionLabel('Restore safe fields')
                    ->action(function (array $data, PostRevision $record, Action $action): void {
                        try {
                            app(PostRevisionService::class)->restore(
                                $this->post(),
                                $record,
                                PostResource::authenticatedUser(),
                                filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
                                expectedContentFingerprint: $this->expectedContentFingerprint,
                            );
                        } catch (DomainException $exception) {
                            throw ValidationException::withMessages([
                                'mountedActions.'.($action->getNestingIndex() ?? 0).'.data.reason' => $exception->getMessage(),
                            ]);
                        }

                        $this->post()->refresh();
                        $this->refreshExpectedContentFingerprint();

                        Notification::make()
                            ->success()
                            ->title('Safe revision fields restored.')
                            ->body('The post slug, workflow, private notes, featured placement, tags, and connections were left unchanged.')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No revisions recorded yet')
            ->emptyStateDescription('The first tracked content change will create an immutable Journal revision.');
    }

    protected function post(): Post
    {
        /** @var Post */
        return $this->getRecord();
    }

    protected function refreshExpectedContentFingerprint(): void
    {
        $this->expectedContentFingerprint = app(PostRevisionService::class)
            ->contentFingerprint($this->post());
    }

    protected static function changeSummary(PostRevision $revision): string
    {
        $fields = collect($revision->changed_fields ?? [])
            ->map(fn (mixed $value, mixed $key): string => is_string($key) ? $key : (string) $value)
            ->map(fn (string $field): string => static::fieldLabel($field))
            ->filter()
            ->unique()
            ->values();

        return $fields->isEmpty() ? 'Snapshot' : $fields->implode(', ');
    }

    protected static function provenanceLabel(?string $provenance): string
    {
        return match ($provenance) {
            'content_edit' => 'Content edit',
            'ai_apply' => 'AI-assisted edit',
            'revision_restore' => 'Revision restore',
            'trash' => 'Moved to trash',
            'trash_restore' => 'Restored from trash',
            'force_delete' => 'Permanent deletion',
            'slug_change' => 'Slug change',
            'history_baseline' => 'History baseline',
            'workflow' => 'Workflow change',
            default => filled($provenance) ? Str::headline($provenance) : 'Journal update',
        };
    }

    protected static function fieldLabel(string $field): string
    {
        if (Str::startsWith($field, 'content.')) {
            $field = Str::after($field, 'content.');
        }

        return static::previewFields()[$field] ?? match ($field) {
            'tags' => 'Shared tags',
            'media', 'media_items' => 'Media connections',
            default => Str::headline($field),
        };
    }

    /** @return array<string, string> */
    protected static function previewFields(): array
    {
        return [
            'title' => 'Title',
            'excerpt' => 'Excerpt',
            'body' => 'Body',
            'cover_image_path' => 'Cover image source',
            'cover_alt_text' => 'Cover alternative text',
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
        ];
    }
}
