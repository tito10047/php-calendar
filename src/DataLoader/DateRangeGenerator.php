<?php

declare(strict_types=1);

namespace Tito10047\Calendar\DataLoader;

use DateInterval;
use DateTimeImmutable;
use Tito10047\Calendar\Enum\WeekStart;
use Tito10047\Calendar\Interface\DaysGeneratorInterface;

/**
 * Generates a fixed-length window of consecutive days — not padded with ghost cells.
 * Used by Calendar::fromDateRange() to support arbitrary date windows.
 *
 * The window length is taken from the [from, to] range given to the constructor; the window
 * itself starts at the calendar's reference date, so withDate(), nextPeriod() and prevPeriod()
 * move the whole window (by its own length when navigating).
 */
final class DateRangeGenerator implements DaysGeneratorInterface
{
    private readonly int $length;

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
        $length = (int) $from->setTime(0, 0, 0)->diff($to->setTime(0, 0, 0))->format('%r%a') + 1;
        if ($length < 1) {
            throw new \InvalidArgumentException('Date range end must not be before its start');
        }
        $this->length = $length;
    }

    /** @return list<DateTimeImmutable> */
    public function getDays(DateTimeImmutable $day, WeekStart $weekStart): array
    {
        $days    = [];
        $current = $day->setTime(0, 0, 0);

        for ($i = 0; $i < $this->length; $i++) {
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
        return new DateInterval("P{$this->length}D");
    }

    /** Number of days in the window. */
    public function getLength(): int
    {
        return $this->length;
    }

    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): DateTimeImmutable
    {
        return $this->to;
    }
}
