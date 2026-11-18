<?php

namespace App\Enums;

enum PlanArea: string
{
    case Reading = 'reading';
    case Venue = 'venue';
    case Schedule = 'schedule';
    case Food = 'food';
    case Judging = 'judging';
    case Budget = 'budget';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
