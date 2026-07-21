<?php

namespace App\Filament\Forms;

use App\Enums\JournalPlanningMode;
use App\Enums\PostMediaType;
use App\Models\PostTemplate;
use App\Services\JournalPlanningSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class JournalPlanningFields
{
    public static function make(PostMediaType $type): Section
    {
        return Section::make('Journal planning')
            ->description('Optionally create one connected private Journal draft after this source saves. This never runs AI or publishes the story.')
            ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                && app(JournalPlanningSettings::class)->current()->sourceMode($type)->isEnabled()
                && ($type !== PostMediaType::Track
                    || blank($get('album_id'))
                    || (bool) $get('standalone_published')))
            ->schema([
                Toggle::make('journal_create_draft')
                    ->label('Also create a linked Journal draft')
                    ->helperText('Safe to retry: an already-connected source is skipped.')
                    ->default(fn (): bool => app(JournalPlanningSettings::class)
                        ->current()
                        ->sourceMode($type)
                        ->isAutomatic())
                    ->live()
                    ->dehydrated(false)
                    ->inline(false),
                Select::make('journal_post_template_id')
                    ->label('Journal template')
                    ->placeholder('Use the saved planning default')
                    ->options(fn (): array => PostTemplate::query()
                        ->active()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?int => app(JournalPlanningSettings::class)->current()->postTemplateId)
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => (bool) $get('journal_create_draft')),
                Toggle::make('journal_copy_shared_tags')
                    ->label('Copy public shared tags')
                    ->default(fn (): bool => app(JournalPlanningSettings::class)->current()->copySharedTags)
                    ->dehydrated(false)
                    ->inline(false)
                    ->visible(fn (Get $get): bool => (bool) $get('journal_create_draft')),
                Toggle::make('journal_use_source_artwork')
                    ->label('Use suitable source artwork as cover')
                    ->helperText('Only effectively public source imagery can be copied.')
                    ->default(fn (): bool => app(JournalPlanningSettings::class)->current()->useSourceArtworkAsCover)
                    ->dehydrated(false)
                    ->inline(false)
                    ->visible(fn (Get $get): bool => (bool) $get('journal_create_draft')),
            ])
            ->columns(2)
            ->columnSpanFull();
    }

    /** @return array<int, Select|Toggle> */
    public static function actionOptions(
        ?JournalPlanningMode $mode = null,
        bool $includeRequestToggle = true,
    ): array {
        if ($mode === JournalPlanningMode::Off) {
            return [];
        }

        $fields = [];

        if ($includeRequestToggle) {
            $fields[] = Toggle::make('journal_create_draft')
                ->label('Also create a linked Journal draft')
                ->default($mode?->isAutomatic() ?? false)
                ->live()
                ->inline(false);
        }

        $visible = $includeRequestToggle
            ? fn (Get $get): bool => (bool) $get('journal_create_draft')
            : true;
        $defaults = app(JournalPlanningSettings::class)->current();
        $fields[] = Select::make('journal_post_template_id')
            ->label('Journal template')
            ->placeholder('Use the saved planning default')
            ->options(fn (): array => PostTemplate::query()->active()->orderBy('name')->pluck('name', 'id')->all())
            ->default($defaults->postTemplateId)
            ->searchable()
            ->preload()
            ->native(false)
            ->visible($visible);
        $fields[] = Toggle::make('journal_copy_shared_tags')
            ->label('Copy public shared tags')
            ->default($defaults->copySharedTags)
            ->inline(false)
            ->visible($visible);
        $fields[] = Toggle::make('journal_use_source_artwork')
            ->label('Use suitable source artwork as cover')
            ->default($defaults->useSourceArtworkAsCover)
            ->inline(false)
            ->visible($visible);

        return $fields;
    }
}
