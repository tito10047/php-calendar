<?php

declare(strict_types=1);

namespace Tito10047\Calendar\View;

use DateTimeImmutable;
use Tito10047\Calendar\ICal\ICalEvent;

/** One time slot in a DayView grid. */
final class TimeSlot
{
    /** @param list<ICalEvent> $events */
    public function __construct(
        public readonly DateTimeImmutable $startTime,
        public readonly DateTimeImmutable $endTime,
        public readonly array $events,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }
}
