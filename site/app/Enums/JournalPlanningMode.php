<?php

namespace App\Enums;

enum JournalPlanningMode: string
{
    case Off = 'off';
    case Ask = 'ask';
    case Automatic = 'automatic';

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
            self::Off => 'Off',
            self::Ask => 'Ask each time',
            self::Automatic => 'Create automatically',
        };
    }

    public function isEnabled(): bool
    {
        return $this !== self::Off;
    }

    public function isAutomatic(): bool
    {
        return $this === self::Automatic;
    }
}
