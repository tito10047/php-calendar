<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Recurrence;

enum Frequency: string
{
    case Secondly = 'SECONDLY';
    case Minutely = 'MINUTELY';
    case Hourly   = 'HOURLY';
    case Daily    = 'DAILY';
    case Weekly   = 'WEEKLY';
    case Monthly  = 'MONTHLY';
    case Yearly   = 'YEARLY';

    /** True for HOURLY, MINUTELY and SECONDLY. */
    public function isSubDaily(): bool
    {
        return match ($this) {
            self::Secondly, self::Minutely, self::Hourly => true,
            default                                      => false,
        };
    }

    /** Length of one sub-daily period in seconds (0 for DAILY and coarser). */
    public function seconds(): int
    {
        return match ($this) {
            self::Secondly => 1,
            self::Minutely => 60,
            self::Hourly   => 3600,
            default        => 0,
        };
    }
}
