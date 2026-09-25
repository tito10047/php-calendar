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
 * Each Day::$data will be a list<ICalEvent> for that date — one entry per *instance*, carrying the
 * instance's own dtStart/dtEnd (RECURRENCE-ID overrides applied). Multi-day events appear on every
 * day they cover; timed events are bucketed by the calendar's timezone (the timezone of the range
 * passed to load()), all-day events by their calendar date.
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
        $tz       = $from->getTimezone();
        $firstKey = $from->format('Y-m-d');
        $lastKey  = $to->format('Y-m-d');
        $byDate   = [];

        foreach ($this->events as $event) {
            foreach ($event->expandOccurrences($from, $to) as $instance) {
                foreach (self::coveredDays($instance, $tz) as $key) {
                    if ($key >= $firstKey && $key <= $lastKey) {
                        $byDate[$key][] = $instance;
                    }
                }
            }
        }

        return new static($this->events, $byDate);
    }

    /**
     * Y-m-d keys of every day the instance covers (end is exclusive).
     *
     * @return list<string>
     */
    private static function coveredDays(ICalEvent $instance, \DateTimeZone $tz): array
    {
        $start = $instance->dtStart;
        $end   = $instance->getEnd($start);

        if ($instance->allDay) {
            $first = new DateTimeImmutable($start->format('Y-m-d'));
            $last  = $end !== null && $end > $start
                ? (new DateTimeImmutable($end->format('Y-m-d')))->modify('-1 day')
                : $first;
        } else {
            $first = new DateTimeImmutable($start->setTimezone($tz)->format('Y-m-d'));
            $last  = $end !== null && $end > $start
                ? new DateTimeImmutable($end->setTimezone($tz)->modify('-1 second')->format('Y-m-d'))
                : $first;
        }

        $days = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }
        return $days === [] ? [$first->format('Y-m-d')] : $days;
    }

    /**
     * @return list<ICalEvent>
     */
    public function getData(DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }
}
