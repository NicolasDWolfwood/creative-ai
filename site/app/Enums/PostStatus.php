<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PostStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case Published = 'published';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ready => 'info',
            self::Scheduled => 'warning',
            self::Published => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::Ready => 'heroicon-o-clipboard-document-check',
            self::Scheduled => 'heroicon-o-calendar-days',
            self::Published => 'heroicon-o-globe-alt',
        };
    }
}
