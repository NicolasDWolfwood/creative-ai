<?php

namespace App\Enums;

enum ArtworkSignaturePosition: string
{
    case BottomRight = 'bottom_right';
    case BottomLeft = 'bottom_left';
    case TopRight = 'top_right';
    case TopLeft = 'top_left';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $position): array => [$position->value => $position->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::BottomRight => 'Bottom-right',
            self::BottomLeft => 'Bottom-left',
            self::TopRight => 'Top-right',
            self::TopLeft => 'Top-left',
        };
    }
}
