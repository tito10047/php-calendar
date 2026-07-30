<?php

declare(strict_types=1);

namespace Tito10047\Calendar\View;

use DateTimeImmutable;
use Tito10047\Calendar\ICal\ICalEvent;

/** A single occurrence of an event on a specific date. */
final class AgendaEntry
{
    public function __construct(
        public readonly ICalEvent $event,
        public readonly DateTimeImmutable $date,
    ) {
    }
}
