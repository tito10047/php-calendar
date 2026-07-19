<?php

declare(strict_types=1);

namespace Tito10047\Calendar\View;

use DateTimeImmutable;
use Tito10047\Calendar\ICal\ICalEvent;

/**
 * Chronological list view — groups event occurrences by day, week, or month.
 *
 * Usage:
 *   $groups = AgendaView::fromEvents($events)
 *       ->forRange(new DateTimeImmutable('today'), new DateTimeImmutable('+30 days'))
 *       ->groupBy(AgendaGrouping::Day)
 *       ->getGroups();
 *
 *   foreach ($groups as $group) {
 *       echo $group->label;
 *       foreach ($group->getEntries() as $entry) {
 *           echo $entry->event->summary . ' on ' . $entry->date->format('Y-m-d');
 *       }
 *   }
 */
final class AgendaView
{
    /** @var list<ICalEvent> */
    private array $events;

    private DateTimeImmutable $from;
    private DateTimeImmutable $to;
    private AgendaGrouping $grouping = AgendaGrouping::Day;

    /** @param list<ICalEvent> $events */
    private function __construct(array $events)
    {
        $this->events = $events;
        $this->from   = new DateTimeImmutable('today');
        $this->to     = new DateTimeImmutable('+30 days');
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

    public function groupBy(AgendaGrouping $grouping): self
    {
        $clone          = clone $this;
        $clone->grouping = $grouping;
        return $clone;
    }

    /**
     * Build and return the grouped list of entries.
     *
     * @return list<AgendaGroup>
     */
    public function getGroups(): array
    {
        $entries = $this->collectEntries();

        $buckets = [];
        foreach ($entries as $entry) {
            $key = $this->bucketKey($entry->date);
            $buckets[$key][] = $entry;
        }

        ksort($buckets);

        $groups = [];
        foreach ($buckets as $key => $bucketEntries) {
            $anchor   = $bucketEntries[0]->date;
            $groups[] = new AgendaGroup(
                label:   $this->formatLabel($anchor),
                date:    $this->bucketAnchor($anchor),
                entries: $bucketEntries,
            );
        }

        return $groups;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return list<AgendaEntry> sorted by date asc */
    private function collectEntries(): array
    {
        $entries = [];
        foreach ($this->events as $event) {
            foreach ($event->occurrences($this->from, $this->to) as $occurrence) {
                // restore original time component from dtStart
                if ($event->dtStart->format('H:i:s') !== '00:00:00') {
                    $occurrence = $occurrence->setTime(
                        (int) $event->dtStart->format('H'),
                        (int) $event->dtStart->format('i'),
                        (int) $event->dtStart->format('s'),
                    );
                }
                $entries[] = new AgendaEntry($event, $occurrence);
            }
        }

        usort($entries, fn (AgendaEntry $a, AgendaEntry $b) => $a->date <=> $b->date);

        return $entries;
    }

    private function bucketKey(DateTimeImmutable $date): string
    {
        return match ($this->grouping) {
            AgendaGrouping::Day   => $date->format('Y-m-d'),
            AgendaGrouping::Week  => $date->format('o-W'),   // ISO year-week
            AgendaGrouping::Month => $date->format('Y-m'),
        };
    }

    private function bucketAnchor(DateTimeImmutable $date): DateTimeImmutable
    {
        return match ($this->grouping) {
            AgendaGrouping::Day   => $date->setTime(0, 0, 0),
            AgendaGrouping::Week  => $date->modify('monday this week')->setTime(0, 0, 0),
            AgendaGrouping::Month => $date->modify('first day of this month')->setTime(0, 0, 0),
        };
    }

    private function formatLabel(DateTimeImmutable $date): string
    {
        return match ($this->grouping) {
            AgendaGrouping::Day   => $date->format('l, j F Y'),
            AgendaGrouping::Week  => sprintf(
                '%s – %s',
                $date->modify('monday this week')->format('j M Y'),
                $date->modify('sunday this week')->format('j M Y'),
            ),
            AgendaGrouping::Month => $date->format('F Y'),
        };
    }
}
