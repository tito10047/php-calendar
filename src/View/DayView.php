<?php

declare(strict_types=1);

namespace Tito10047\Calendar\View;

use DateTimeImmutable;
use Tito10047\Calendar\ICal\ICalEvent;

/**
 * Hourly / time-slot grid for a single day.
 *
 * Usage:
 *   $slots = DayView::forDate(new DateTimeImmutable('2025-06-15'))
 *       ->withSlotDuration(30)   // minutes per slot
 *       ->withRange(8, 20)       // 08:00 – 20:00
 *       ->setEvents($events)
 *       ->getSlots();
 *
 *   foreach ($slots as $slot) {
 *       echo $slot->startTime->format('H:i');
 *       foreach ($slot->events as $event) {
 *           echo $event->summary;
 *       }
 *   }
 */
final class DayView
{
    /** @var list<ICalEvent> */
    private array $events = [];

    private int $slotDuration = 60;
    private int $hourFrom     = 0;
    private int $hourTo       = 24;

    private function __construct(private readonly DateTimeImmutable $date)
    {
    }

    public static function forDate(DateTimeImmutable $date): self
    {
        return new self($date->setTime(0, 0, 0));
    }

    /** Slot size in minutes (must be a divisor of 60). */
    public function withSlotDuration(int $minutes): self
    {
        if ($minutes < 1 || 60 % $minutes !== 0) {
            throw new \InvalidArgumentException("Slot duration must be a positive divisor of 60, got {$minutes}");
        }
        $clone               = clone $this;
        $clone->slotDuration = $minutes;
        return $clone;
    }

    /** Visible hour range [fromHour, toHour). Both 0–24. */
    public function withRange(int $fromHour, int $toHour): self
    {
        if ($fromHour < 0 || $toHour > 24 || $fromHour >= $toHour) {
            throw new \InvalidArgumentException("Invalid hour range [{$fromHour}, {$toHour})");
        }
        $clone           = clone $this;
        $clone->hourFrom = $fromHour;
        $clone->hourTo   = $toHour;
        return $clone;
    }

    /** @param list<ICalEvent> $events */
    public function setEvents(array $events): self
    {
        $clone         = clone $this;
        $clone->events = $events;
        return $clone;
    }

    /**
     * @return list<TimeSlot>
     */
    public function getSlots(): array
    {
        $dayStart  = $this->date->setTime($this->hourFrom, 0, 0);
        $dayEnd    = $this->date->setTime($this->hourTo, 0, 0);
        $stepSecs  = $this->slotDuration * 60;

        // Collect all events occurring on this day
        $dayEvents = $this->eventsOnDay();

        $slots   = [];
        $current = $dayStart;

        while ($current < $dayEnd) {
            $slotEnd    = $current->modify("+{$this->slotDuration} minutes");
            $slotEvents = [];

            foreach ($dayEvents as $event) {
                if ($this->overlaps($event, $current, $slotEnd)) {
                    $slotEvents[] = $event;
                }
            }

            $slots[] = new TimeSlot(
                startTime: $current,
                endTime:   $slotEnd,
                events:    $slotEvents,
            );

            $current = $slotEnd;
        }

        return $slots;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return list<ICalEvent> */
    private function eventsOnDay(): array
    {
        $dayStart = $this->date->setTime(0, 0, 0);
        $dayEnd   = $this->date->setTime(23, 59, 59);
        $result   = [];

        foreach ($this->events as $event) {
            $occurrences = $event->occurrences($dayStart, $dayEnd);
            if ($occurrences !== []) {
                $result[] = $event;
            }
        }

        return $result;
    }

    private function overlaps(ICalEvent $event, DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd): bool
    {
        $evStart = $event->dtStart;
        $evEnd   = $event->dtEnd ?? $evStart->modify('+1 hour');

        // Normalize to the day of this view (for recurring events the occurrence is on this day)
        $evStartNorm = $this->date->setTime(
            (int) $evStart->format('H'),
            (int) $evStart->format('i'),
            (int) $evStart->format('s'),
        );
        $evEndNorm = $this->date->setTime(
            (int) $evEnd->format('H'),
            (int) $evEnd->format('i'),
            (int) $evEnd->format('s'),
        );
        if ($evEndNorm <= $evStartNorm) {
            $evEndNorm = $evStartNorm->modify('+1 hour');
        }

        // Overlap: event starts before slot ends AND event ends after slot starts
        return $evStartNorm < $slotEnd && $evEndNorm > $slotStart;
    }
}
