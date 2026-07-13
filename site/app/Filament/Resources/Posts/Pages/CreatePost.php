<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected static bool $canCreateAnother = false;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getRedirectUrl(): string
    {
        return PostResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'data.slug' => $exception->getMessage(),
            ]);
        }
    }
}
