<?php

declare(strict_types=1);

namespace Tito10047\Calendar\DataLoader;

use DateInterval;
use DateTimeImmutable;
use Tito10047\Calendar\Enum\WeekStart;
use Tito10047\Calendar\Interface\DaysGeneratorInterface;

/**
 * Generates every day in an explicit [from, to] range — not padded with ghost cells.
 * Used by Calendar::fromDateRange() to support arbitrary date windows.
 */
final class DateRangeGenerator implements DaysGeneratorInterface
{
    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
    }

    /** @return list<DateTimeImmutable> */
    public function getDays(DateTimeImmutable $day, WeekStart $weekStart): array
    {
        $days    = [];
        $current = $this->from->setTime(0, 0, 0);
        $end     = $this->to->setTime(0, 0, 0);

        while ($current <= $end) {
            $days[]  = $current;
            $current = $current->modify('+1 day');
        }

        return $days;
    }

    public function hasGhostDays(): bool
    {
        return false;
    }

    public function getNavigationStep(): DateInterval
    {
        $diff = $this->from->diff($this->to);
        $days = (int) $diff->days + 1;
        return new DateInterval("P{$days}D");
    }
}
