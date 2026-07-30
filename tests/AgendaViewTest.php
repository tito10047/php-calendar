<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\Recurrence\RecurrenceRule;
use Tito10047\Calendar\View\AgendaGrouping;
use Tito10047\Calendar\View\AgendaView;

class AgendaViewTest extends TestCase
{
    private function makeEvent(string $uid, string $start, ?string $end = null, ?string $summary = null): ICalEvent
    {
        return new ICalEvent(
            uid:         $uid,
            dtStart:     new DateTimeImmutable($start),
            dtEnd:       $end !== null ? new DateTimeImmutable($end) : null,
            summary:     $summary ?? $uid,
            description: null,
            location:    null,
            rrule:       null,
        );
    }

    public function testGroupByDayBasic(): void
    {
        $events = [
            $this->makeEvent('ev-a', '2025-06-10T10:00:00Z', null, 'Morning'),
            $this->makeEvent('ev-b', '2025-06-10T14:00:00Z', null, 'Afternoon'),
            $this->makeEvent('ev-c', '2025-06-12T09:00:00Z', null, 'Thursday'),
        ];

        $groups = AgendaView::fromEvents($events)
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->groupBy(AgendaGrouping::Day)
            ->getGroups();

        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups[0]->getEntries());
        $this->assertCount(1, $groups[1]->getEntries());
        $this->assertSame('2025-06-10', $groups[0]->date->format('Y-m-d'));
        $this->assertSame('2025-06-12', $groups[1]->date->format('Y-m-d'));
    }

    public function testGroupByWeek(): void
    {
        $events = [
            $this->makeEvent('ev-mon', '2025-06-02T09:00:00Z'),
            $this->makeEvent('ev-fri', '2025-06-06T09:00:00Z'),
            $this->makeEvent('ev-next', '2025-06-09T09:00:00Z'),
        ];

        $groups = AgendaView::fromEvents($events)
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->groupBy(AgendaGrouping::Week)
            ->getGroups();

        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups[0]->getEntries());
        $this->assertCount(1, $groups[1]->getEntries());
    }

    public function testGroupByMonth(): void
    {
        $events = [
            $this->makeEvent('june-1', '2025-06-15T09:00:00Z'),
            $this->makeEvent('july-1', '2025-07-03T09:00:00Z'),
        ];

        $groups = AgendaView::fromEvents($events)
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-07-31'))
            ->groupBy(AgendaGrouping::Month)
            ->getGroups();

        $this->assertCount(2, $groups);
        $this->assertSame('June 2025', $groups[0]->label);
        $this->assertSame('July 2025', $groups[1]->label);
    }

    public function testGroupsAreSortedChronologically(): void
    {
        $events = [
            $this->makeEvent('ev-c', '2025-06-20T09:00:00Z'),
            $this->makeEvent('ev-a', '2025-06-05T09:00:00Z'),
            $this->makeEvent('ev-b', '2025-06-10T09:00:00Z'),
        ];

        $groups = AgendaView::fromEvents($events)
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->getGroups();

        $dates = array_map(fn ($g) => $g->date->format('Y-m-d'), $groups);
        $this->assertSame(['2025-06-05', '2025-06-10', '2025-06-20'], $dates);
    }

    public function testRecurringEventExpandedIntoGroups(): void
    {
        $rule  = RecurrenceRule::weekly()->onDays(DayName::Monday)->limitTo(4);
        $event = new ICalEvent(
            uid:         'weekly-mon',
            dtStart:     new DateTimeImmutable('2025-06-02T09:00:00Z'),
            dtEnd:       null,
            summary:     'Weekly Monday',
            description: null,
            location:    null,
            rrule:       $rule,
        );

        $groups = AgendaView::fromEvents([$event])
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->groupBy(AgendaGrouping::Day)
            ->getGroups();

        // Mondays in June 2025: 2, 9, 16, 23, 30 — but COUNT=4 so 2, 9, 16, 23
        $this->assertCount(4, $groups);
        $this->assertSame('2025-06-02', $groups[0]->date->format('Y-m-d'));
        $this->assertSame('2025-06-23', $groups[3]->date->format('Y-m-d'));
    }

    public function testEventsOutsideRangeExcluded(): void
    {
        $events = [
            $this->makeEvent('before', '2025-05-31T09:00:00Z'),
            $this->makeEvent('in', '2025-06-15T09:00:00Z'),
            $this->makeEvent('after', '2025-07-01T09:00:00Z'),
        ];

        $groups = AgendaView::fromEvents($events)
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->getGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('in', $groups[0]->getEntries()[0]->event->uid);
    }

    public function testEntryPreservesOriginalTime(): void
    {
        $event  = $this->makeEvent('timed', '2025-06-10T14:30:00Z', null, 'Timed');
        $groups = AgendaView::fromEvents([$event])
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->getGroups();

        $this->assertSame('14:30:00', $groups[0]->getEntries()[0]->date->format('H:i:s'));
    }

    public function testDefaultGroupingIsDay(): void
    {
        $event  = $this->makeEvent('ev', '2025-06-10T09:00:00Z');
        $groups = AgendaView::fromEvents([$event])
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->getGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('2025-06-10', $groups[0]->date->format('Y-m-d'));
    }
}
