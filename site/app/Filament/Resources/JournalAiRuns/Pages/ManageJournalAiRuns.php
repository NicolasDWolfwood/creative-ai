<?php

namespace App\Filament\Resources\JournalAiRuns\Pages;

use App\Filament\Resources\JournalAiRuns\JournalAiRunResource;
use Filament\Resources\Pages\ManageRecords;

class ManageJournalAiRuns extends ManageRecords
{
    protected static string $resource = JournalAiRunResource::class;

    public function getTitle(): string
    {
        return 'Journal AI queue';
    }

    public function getSubheading(): ?string
    {
        return 'Review results and acknowledge fresh context for retries in each post’s AI assistant.';
    }
}
