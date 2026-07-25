<?php

namespace App\Enums;

enum ArtworkSignatureMode: string
{
    case Automatic = 'automatic';
    case Black = 'black';
    case White = 'white';
    case Embedded = 'embedded';
    case None = 'none';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic contrast',
            self::Black => 'Force black',
            self::White => 'Force white',
            self::Embedded => 'Already embedded in the master',
            self::None => 'No signature',
        };
    }

    public function usesSignatureAsset(): bool
    {
        return in_array($this, [
            self::Automatic,
            self::Black,
            self::White,
        ], true);
    }
}
