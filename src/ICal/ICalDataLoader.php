<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;

/**
 * DayDataLoaderInterface adapter that expands iCal events into per-day data.
 *
 * Immutable: load() returns a new instance with expanded event data; the original
 * instance is never mutated. Safe to share across multiple Calendar objects.
 *
 * Usage:
 *   $parser = new ICalParser();
 *   $loader = ICalDataLoader::fromEvents($parser->parseFile('calendar.ics'));
 *   $calendar = (new Calendar(new DateTimeImmutable(), CalendarType::Monthly))
 *       ->setDataLoader($loader);
 *
 * Each Day::$data will be a list<ICalEvent> for that date.
 */
final class ICalDataLoader implements DayDataLoaderInterface
{
    /** @var array<string, list<ICalEvent>> Keyed by Y-m-d; populated only in loaded instances. */
    private readonly array $byDate;

    /**
     * @param list<ICalEvent>                $events
     * @param array<string, list<ICalEvent>> $byDate
     */
    private function __construct(
        private readonly array $events,
        array $byDate = [],
    ) {
        $this->byDate = $byDate;
    }

    /** @param list<ICalEvent> $events */
    public static function fromEvents(array $events): self
    {
        return new self($events);
    }

    /**
     * Expand all events into the requested range and return a new loaded instance.
     * The original ICalDataLoader is not modified.
     */
    public function load(DateTimeImmutable $from, DateTimeImmutable $to): static
    {
        $byDate = [];
        foreach ($this->events as $event) {
            foreach ($event->occurrences($from, $to) as $date) {
                $byDate[$date->format('Y-m-d')][] = $event;
            }
        }
        return new static($this->events, $byDate);
    }

    /**
     * @return list<ICalEvent>
     */
    public function getData(DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }
}
