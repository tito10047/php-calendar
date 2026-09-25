<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Serializer;

use DateTimeImmutable;
use Tito10047\Calendar\ICal\ICalEvent;

/**
 * Converts ICalEvent collections to JSON compatible with FullCalendar, Toast UI Calendar and DHTMLX Scheduler.
 *
 * Output schema per event:
 *   id            — event UID
 *   title         — SUMMARY
 *   start         — ISO 8601: date-only (Y-m-d) for all-day events, otherwise a datetime with
 *                   UTC offset (2025-06-15T09:00:00+02:00) so clients never guess the timezone
 *   end           — ISO 8601 or null; exclusive end date for all-day events
 *   allDay        — true for all-day (DATE-valued) events — ICalEvent::$allDay
 *   color         — COLOR property or null
 *   extendedProps — description, location, categories, status
 *
 * Usage:
 *   $json = JsonSerializer::fromEvents($events)
 *       ->forRange($from, $to)                        // occurrences overlapping the range
 *       ->inTimezone(new DateTimeZone('Europe/Bratislava')) // optional: convert timed events
 *       ->toJson();
 */
final class JsonSerializer
{
    /** @var list<ICalEvent> */
    private array $events;

    private ?DateTimeImmutable $from = null;
    private ?DateTimeImmutable $to   = null;
    private ?\DateTimeZone $timezone = null;

    /** @param list<ICalEvent> $events */
    private function __construct(array $events)
    {
        $this->events = $events;
    }

    /** @param list<ICalEvent> $events */
    public static function fromEvents(array $events): self
    {
        return new self($events);
    }

    public function forRange(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        $clone       = clone $this;
        $clone->from = $from->setTime(0, 0, 0);
        $clone->to   = $to->setTime(23, 59, 59);
        return $clone;
    }

    /** Convert timed events to this timezone before formatting (all-day events are unaffected). */
    public function inTimezone(\DateTimeZone $timezone): self
    {
        $clone           = clone $this;
        $clone->timezone = $timezone;
        return $clone;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->events as $event) {
            if ($this->from !== null && $this->to !== null) {
                // expandOccurrences returns one ICalEvent per instance overlapping the range
                // (including events that started earlier) and applies RECURRENCE-ID overrides
                foreach ($event->expandOccurrences($this->from, $this->to) as $occurrence) {
                    $result[] = $this->buildEntry($occurrence);
                }
            } else {
                $result[] = $this->buildEntry($event);
            }
        }

        return $result;
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        $encoded = json_encode($this->toArray(), $flags);
        if ($encoded === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $encoded;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function buildEntry(ICalEvent $event): array
    {
        $allDay = $event->allDay;

        return [
            'id'            => $event->uid,
            'title'         => $event->summary ?? '',
            'start'         => $this->formatDate($event->dtStart, $allDay),
            'end'           => $event->dtEnd !== null ? $this->formatDate($event->dtEnd, $allDay) : null,
            'allDay'        => $allDay,
            'color'         => $event->color,
            'extendedProps' => [
                'description' => $event->description,
                'location'    => $event->location,
                'categories'  => $event->categories,
                'status'      => $event->status?->value,
                'url'         => $event->url,
            ],
        ];
    }

    private function formatDate(DateTimeImmutable $date, bool $allDay): string
    {
        if ($allDay) {
            return $date->format('Y-m-d');
        }
        if ($this->timezone !== null) {
            $date = $date->setTimezone($this->timezone);
        }
        return $date->format('Y-m-d\TH:i:sP');
    }
}
