<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Services\PostSlugRedirectService;
use DomainException;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    /** @var list<string> */
    protected const EDITABLE_FIELDS = [
        'title',
        'slug',
        'featured',
        'excerpt',
        'cover_image_path',
        'cover_alt_text',
        'body',
        'editorial_brief',
        'editorial_notes',
        'seo_title',
        'seo_description',
    ];

    protected ?bool $hasDatabaseTransactions = true;

    protected ?string $requestedSlug = null;

    #[Locked]
    public string $expectedEditableFingerprint = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->refreshExpectedEditableFingerprint();
    }

    protected function getHeaderActions(): array
    {
        return [
            PostResource::previewAction(),
            PostResource::readinessAction(),
            ActionGroup::make(PostResource::workflowActions())
                ->label('Workflow')
                ->icon('heroicon-o-arrows-right-left')
                ->button(),
            PostResource::deleteAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return PostResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currentSlug = (string) $this->getRecord()->slug;
        $requestedSlug = trim((string) ($data['slug'] ?? ''));

        $this->requestedSlug = $requestedSlug !== $currentSlug ? $requestedSlug : null;
        $data['slug'] = $currentSlug;

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $locked = Post::query()
            ->lockForUpdate()
            ->findOrFail($record->getKey());

        if (
            $this->expectedEditableFingerprint === ''
            || ! hash_equals($this->expectedEditableFingerprint, $this->editableFingerprint($locked))
        ) {
            throw ValidationException::withMessages([
                'data.title' => 'This post changed after this editor tab was opened. Reload the page before saving so newer writing or private editorial changes are not overwritten.',
            ]);
        }

        $updated = parent::handleRecordUpdate($locked, $data);
        $this->record = $updated;

        return $updated;
    }

    protected function afterSave(): void
    {
        if ($this->requestedSlug !== null) {
            try {
                $post = app(PostSlugRedirectService::class)->changeSlug(
                    $this->getRecord(),
                    $this->requestedSlug,
                    PostResource::authenticatedUser(),
                );
            } catch (DomainException $exception) {
                throw ValidationException::withMessages([
                    'data.slug' => $exception->getMessage(),
                ]);
            }

            /** @var Post $post */
            $this->record = $post;
            $this->data['slug'] = $post->slug;
            $this->requestedSlug = null;
        }

        $this->refreshExpectedEditableFingerprint();
    }

    protected function refreshExpectedEditableFingerprint(): void
    {
        $this->expectedEditableFingerprint = $this->editableFingerprint($this->getRecord());
    }

    protected function editableFingerprint(Post $post): string
    {
        $state = collect(static::EDITABLE_FIELDS)
            ->mapWithKeys(fn (string $field): array => [
                $field => $field === 'featured'
                    ? (bool) $post->getAttribute($field)
                    : $post->getAttribute($field),
            ])
            ->all();

        return hash('sha256', json_encode(
            $state,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
