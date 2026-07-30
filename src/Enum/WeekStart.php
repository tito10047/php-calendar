<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Enum;

/**
 * The three culturally recognised week-start conventions.
 * Value is the ISO 8601 weekday number (Monday = 1 … Sunday = 7).
 */
enum WeekStart: int
{
    case Monday   = 1;
    case Saturday = 6; // Middle East (e.g. Saudi Arabia, UAE)
    case Sunday   = 7; // North America, much of Latin America

    /**
     * ISO weekday number of the last day in a week that starts on $this.
     *
     * Monday(1) → 7 (Sunday)
     * Saturday(6) → 5 (Friday)
     * Sunday(7)  → 6 (Saturday)
     */
    public function lastDayIsoNumber(): int
    {
        return ($this->value + 5) % 7 + 1;
    }

    /**
     * Convert to the corresponding DayName — useful when rendering week headers.
     */
    public function toDayName(): DayName
    {
        return DayName::from($this->value);
    }
}
