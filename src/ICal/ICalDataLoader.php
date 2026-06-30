<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;

/**
 * DayDataLoaderInterface adapter that expands iCal events into per-day data.
 *
 * Usage:
 *   $parser = new ICalParser();
 *   $loader = ICalDataLoader::fromEvents($parser->parseFile('calendar.ics'));
 *   $calendar = (new Calendar(new DateTimeImmutable(), CalendarType::Monthly))
 *       ->setDataLoader($loader);
 */
final class ICalDataLoader implements DayDataLoaderInterface
{
    /** @var array<string, list<array<string, mixed>>> Keyed by Y-m-d */
    private array $byDate = [];

    /** @param list<ICalEvent> $events */
    private function __construct(private readonly array $events) {}

    /** @param list<ICalEvent> $events */
    public static function fromEvents(array $events): self
    {
        return new self($events);
    }

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->byDate = [];

        foreach ($this->events as $event) {
            foreach ($event->occurrences($from, $to) as $date) {
                $this->byDate[$date->format('Y-m-d')][] = $event->toArray();
            }
        }
    }

    /**
     * @return array<mixed>
     */
    public function getData(DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }
}
