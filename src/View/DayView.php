<?php

declare(strict_types=1);

namespace Tito10047\Calendar\View;

use DateTimeImmutable;
use Tito10047\Calendar\ICal\ICalEvent;

/**
 * Hourly / time-slot grid for a single day.
 *
 * Usage:
 *   $view = DayView::forDate(new DateTimeImmutable('2025-06-15', new DateTimeZone('Europe/Bratislava')))
 *       ->withSlotDuration(30)   // minutes per slot
 *       ->withRange(8, 20)       // 08:00 – 20:00
 *       ->setEvents($events);
 *
 *   foreach ($view->getAllDayEvents() as $event) {   // render in an "all-day" header row
 *       echo $event->summary;
 *   }
 *   foreach ($view->getSlots() as $slot) {
 *       echo $slot->startTime->format('H:i');
 *       foreach ($slot->events as $event) {
 *           echo $event->summary;
 *       }
 *   }
 *
 * The view works in the timezone of the date passed to forDate(): timed events are converted to
 * it, multi-day and overnight events occupy every slot they overlap, recurring events are expanded
 * (RECURRENCE-ID overrides applied) and all-day events are reported separately via
 * getAllDayEvents(). Slots are measured in elapsed time, so DST-change days have 23 or 25 hourly
 * slots. Events are per-instance ICalEvent objects carrying the occurrence's own start/end.
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
     * All-day instances on this day (not included in any time slot).
     *
     * @return list<ICalEvent>
     */
    public function getAllDayEvents(): array
    {
        return array_values(array_filter($this->instancesOnDay(), static fn (ICalEvent $e) => $e->allDay));
    }

    /**
     * @return list<TimeSlot>
     */
    public function getSlots(): array
    {
        $dayStart = $this->date->setTime($this->hourFrom, 0, 0);
        $dayEnd   = $this->hourTo === 24
            ? $this->date->modify('+1 day')
            : $this->date->setTime($this->hourTo, 0, 0);
        $stepSecs = $this->slotDuration * 60;

        $timed = array_values(array_filter($this->instancesOnDay(), static fn (ICalEvent $e) => !$e->allDay));

        $slots   = [];
        $current = $dayStart;

        while ($current < $dayEnd) {
            $slotEnd = $current->setTimestamp(min($current->getTimestamp() + $stepSecs, $dayEnd->getTimestamp()));

            $slotEvents = [];
            foreach ($timed as $event) {
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

    /** @return list<ICalEvent> instances overlapping this day, timed ones converted to the view's timezone */
    private function instancesOnDay(): array
    {
        $tz     = $this->date->getTimezone();
        $result = [];

        foreach ($this->events as $event) {
            foreach ($event->expandOccurrences($this->date, $this->date) as $instance) {
                if (!$instance->allDay) {
                    $instance = self::inTimezone($instance, $tz);
                }
                $result[] = $instance;
            }
        }

        usort($result, static fn (ICalEvent $a, ICalEvent $b) => $a->dtStart <=> $b->dtStart);

        return $result;
    }

    private static function inTimezone(ICalEvent $event, \DateTimeZone $tz): ICalEvent
    {
        if ($event->dtStart->getTimezone()->getName() === $tz->getName()) {
            return $event;
        }
        return $event->withDates($event->dtStart->setTimezone($tz), $event->dtEnd?->setTimezone($tz));
    }

    private function overlaps(ICalEvent $event, DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd): bool
    {
        $evStart = $event->dtStart;
        $evEnd   = $event->getEnd($evStart);

        // Events without an end are shown as one hour long; zero-length events occupy their start slot
        if ($evEnd === null) {
            $evEnd = $evStart->modify('+1 hour');
        }
        if ($evEnd <= $evStart) {
            return $evStart >= $slotStart && $evStart < $slotEnd;
        }

        // Overlap: event starts before slot ends AND event ends after slot starts
        return $evStart < $slotEnd && $evEnd > $slotStart;
    }
}
