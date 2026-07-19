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
 *   start         — ISO 8601 (date-only for all-day events, datetime otherwise)
 *   end           — ISO 8601 or null
 *   allDay        — true when DTSTART has no time component
 *   color         — COLOR property or null
 *   extendedProps — description, location, categories, status
 *
 * Usage:
 *   $json = JsonSerializer::fromEvents($events)
 *       ->forRange($from, $to)
 *       ->toJson();
 */
final class JsonSerializer
{
    /** @var list<ICalEvent> */
    private array $events;

    private ?DateTimeImmutable $from = null;
    private ?DateTimeImmutable $to   = null;

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

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->events as $event) {
            if ($this->from !== null && $this->to !== null) {
                $occurrences = $event->occurrences($this->from, $this->to);
                foreach ($occurrences as $occurrence) {
                    $result[] = $this->buildEntry($event, $occurrence);
                }
            } else {
                $result[] = $this->buildEntry($event, $event->dtStart);
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
    private function buildEntry(ICalEvent $event, DateTimeImmutable $occurrenceStart): array
    {
        $allDay = $this->isAllDay($event->dtStart);

        // expand() strips time to 00:00:00; restore original time for timed events
        if (!$allDay) {
            $occurrenceStart = $occurrenceStart->setTime(
                (int) $event->dtStart->format('H'),
                (int) $event->dtStart->format('i'),
                (int) $event->dtStart->format('s'),
            );
        }

        // Preserve event duration across every occurrence
        $dtEnd = null;
        if ($event->dtEnd !== null) {
            $duration = $event->dtStart->diff($event->dtEnd);
            $dtEnd    = $occurrenceStart->add($duration);
        }

        return [
            'id'            => $event->uid,
            'title'         => $event->summary ?? '',
            'start'         => $allDay
                ? $occurrenceStart->format('Y-m-d')
                : $occurrenceStart->format('Y-m-d\TH:i:s'),
            'end'           => $dtEnd !== null
                ? ($allDay ? $dtEnd->format('Y-m-d') : $dtEnd->format('Y-m-d\TH:i:s'))
                : null,
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

    private function isAllDay(DateTimeImmutable $dt): bool
    {
        return $dt->format('H:i:s') === '00:00:00';
    }
}
