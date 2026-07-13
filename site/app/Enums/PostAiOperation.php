<?php

namespace App\Enums;

enum PostAiOperation: string
{
    case Directions = 'directions';
    case Outline = 'outline';
    case EditorialReview = 'editorial_review';
    case ImprovePassage = 'improve_passage';
    case Metadata = 'metadata';

    public function label(): string
    {
        return match ($this) {
            self::Directions => 'Writing directions',
            self::Outline => 'Outline',
            self::EditorialReview => 'Editorial review',
            self::ImprovePassage => 'Improve passage',
            self::Metadata => 'Metadata suggestions',
        };
    }
}
