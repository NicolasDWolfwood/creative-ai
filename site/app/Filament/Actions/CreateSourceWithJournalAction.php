<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Posts\PostResource;
use App\Services\JournalDraftAutomationService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateSourceWithJournalAction extends CreateAction
{
    protected ?Closure $afterSourceCreated = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->after(function (Model $record): void {
            if ($this->afterSourceCreated instanceof Closure) {
                $this->evaluate($this->afterSourceCreated, ['record' => $record]);
            }

            $data = $this->getRawData();

            if (! (bool) ($data['journal_create_draft'] ?? false)) {
                return;
            }

            $automation = app(JournalDraftAutomationService::class);

            if (! $automation->isEligibleSource($record)) {
                return;
            }

            try {
                $result = $automation->createFor($record, $data);
            } catch (Throwable $exception) {
                report($exception);

                Notification::make()
                    ->warning()
                    ->title('Source saved; Journal draft needs attention')
                    ->body('The source was created successfully. Use its Create Journal draft action to retry.')
                    ->send();

                return;
            }

            Notification::make()
                ->success()
                ->title($result->created ? 'Source and Journal draft created' : 'Source created; existing Journal plan kept')
                ->body($result->created
                    ? 'The linked story is a private Draft. Review it before changing its workflow.'
                    : 'This source was already connected, so no duplicate draft was created.')
                ->actions([
                    Action::make('openJournalDraft')
                        ->label('Open Journal draft')
                        ->url(PostResource::getUrl('edit', ['record' => $result->post])),
                ])
                ->send();
        });
    }

    public function afterSourceCreated(?Closure $callback): static
    {
        $this->afterSourceCreated = $callback;

        return $this;
    }
}
