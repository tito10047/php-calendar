<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Day;
use Tito10047\Calendar\Enum\WeekStart;
use Tito10047\Calendar\ICal\ICalDataLoader;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\Recurrence\RecurrenceRule;
use Tito10047\Calendar\Serializer\JsonSerializer;
use Tito10047\Calendar\View\AgendaGrouping;
use Tito10047\Calendar\View\AgendaView;
use Tito10047\Calendar\View\DayView;

final class ViewsTimezoneTest extends TestCase
{
    private function event(
        string $uid,
        DateTimeImmutable $start,
        ?DateTimeImmutable $end = null,
        ?RecurrenceRule $rule = null,
        bool $allDay = false,
        string $summary = 'Event',
    ): ICalEvent {
        return new ICalEvent(
            uid: $uid,
            dtStart: $start,
            dtEnd: $end,
            summary: $summary,
            description: null,
            location: null,
            rrule: $rule,
            allDay: $allDay,
        );
    }

    private function weeklyWithMovedOccurrence(): ICalEvent
    {
        $utc    = new DateTimeZone('UTC');
        $master = $this->event('w', new DateTimeImmutable('2025-01-06 10:00', $utc), new DateTimeImmutable('2025-01-06 11:00', $utc), RecurrenceRule::weekly()->limitTo(3), summary: 'Weekly');
        $moved  = new ICalEvent(
            uid: 'w',
            dtStart: new DateTimeImmutable('2025-01-14 14:00', $utc),
            dtEnd: new DateTimeImmutable('2025-01-14 15:00', $utc),
            summary: 'Moved',
            description: null,
            location: null,
            rrule: null,
            recurrenceId: new DateTimeImmutable('2025-01-13 10:00', $utc),
        );
        return $master->withModifiedOccurrence(new DateTimeImmutable('2025-01-13 10:00', $utc), $moved);
    }

    // -------------------------------------------------------------------------
    // AgendaView
    // -------------------------------------------------------------------------

    public function testAgendaIncludesMovedOccurrence(): void
    {
        $utc    = new DateTimeZone('UTC');
        $groups = AgendaView::fromEvents([$this->weeklyWithMovedOccurrence()])
            ->forRange(new DateTimeImmutable('2025-01-01', $utc), new DateTimeImmutable('2025-01-31', $utc))
            ->getGroups();

        $entries = array_merge(...array_map(static fn ($g) => $g->getEntries(), $groups));
        $this->assertSame(
            ['2025-01-06 10:00 Weekly', '2025-01-14 14:00 Moved', '2025-01-20 10:00 Weekly'],
            array_map(static fn ($e) => $e->date->format('Y-m-d H:i') . ' ' . $e->event->summary, $entries),
        );
        $this->assertSame('2025-01-20', $entries[2]->event->dtStart->format('Y-m-d'), 'entry carries the instance, not the master');
    }

    public function testAgendaBucketsInViewTimezone(): void
    {
        $berlin = new DateTimeZone('Europe/Berlin');
        $event  = $this->event('late', new DateTimeImmutable('2025-06-15 23:30', new DateTimeZone('UTC')));

        $groups = AgendaView::fromEvents([$event])
            ->forRange(new DateTimeImmutable('2025-06-15', $berlin), new DateTimeImmutable('2025-06-16', $berlin))
            ->getGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('Monday, 16 June 2025', $groups[0]->label);
        $this->assertSame('01:30', $groups[0]->getEntries()[0]->event->dtStart->format('H:i'));
    }

    public function testAgendaWeekGroupingRespectsWeekStart(): void
    {
        $events = [
            $this->event('sat', new DateTimeImmutable('2025-06-14 10:00')),
            $this->event('sun', new DateTimeImmutable('2025-06-15 10:00')),
        ];

        $monday = AgendaView::fromEvents($events)->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->groupBy(AgendaGrouping::Week)->getGroups();
        $sunday = AgendaView::fromEvents($events)->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->groupBy(AgendaGrouping::Week)->withWeekStart(WeekStart::Sunday)->getGroups();

        $this->assertCount(1, $monday);
        $this->assertCount(2, $sunday);
        $this->assertSame('15 Jun 2025 – 21 Jun 2025', $sunday[1]->label);
    }

    // -------------------------------------------------------------------------
    // DayView
    // -------------------------------------------------------------------------

    public function testDayViewSeparatesAllDayEvents(): void
    {
        $view = DayView::forDate(new DateTimeImmutable('2025-06-15'))
            ->setEvents([$this->event('ad', new DateTimeImmutable('2025-06-15'), new DateTimeImmutable('2025-06-16'), allDay: true)]);

        $this->assertCount(1, $view->getAllDayEvents());
        foreach ($view->getSlots() as $slot) {
            $this->assertTrue($slot->isEmpty());
        }
    }

    public function testDayViewShowsOvernightEventOnBothDays(): void
    {
        $event = $this->event('night', new DateTimeImmutable('2025-06-15 22:00'), new DateTimeImmutable('2025-06-16 02:00'));

        $first  = DayView::forDate(new DateTimeImmutable('2025-06-15'))->setEvents([$event])->getSlots();
        $second = DayView::forDate(new DateTimeImmutable('2025-06-16'))->setEvents([$event])->getSlots();

        $busy = static fn (array $slots) => array_values(array_map(
            static fn ($s) => $s->startTime->format('H'),
            array_filter($slots, static fn ($s) => !$s->isEmpty()),
        ));
        $this->assertSame(['22', '23'], $busy($first));
        $this->assertSame(['00', '01'], $busy($second));
    }

    public function testDayViewConvertsToViewTimezone(): void
    {
        $prague = new DateTimeZone('Europe/Prague');
        $event  = $this->event('utc', new DateTimeImmutable('2025-06-15 09:00', new DateTimeZone('UTC')), new DateTimeImmutable('2025-06-15 10:00', new DateTimeZone('UTC')));

        $slots = DayView::forDate(new DateTimeImmutable('2025-06-15', $prague))->setEvents([$event])->getSlots();

        $this->assertTrue($slots[9]->isEmpty());
        $this->assertFalse($slots[11]->isEmpty());
        $this->assertSame('11:00', $slots[11]->events[0]->dtStart->format('H:i'));
    }

    public function testDayViewOnDstDaysUsesElapsedTime(): void
    {
        $berlin = new DateTimeZone('Europe/Berlin');

        $this->assertCount(23, DayView::forDate(new DateTimeImmutable('2025-03-30', $berlin))->getSlots());
        $this->assertCount(25, DayView::forDate(new DateTimeImmutable('2025-10-26', $berlin))->getSlots());
        foreach (DayView::forDate(new DateTimeImmutable('2025-10-26', $berlin))->getSlots() as $slot) {
            $this->assertSame(3600, $slot->endTime->getTimestamp() - $slot->startTime->getTimestamp());
        }
    }

    public function testDayViewUsesMovedOccurrence(): void
    {
        $slots = DayView::forDate(new DateTimeImmutable('2025-01-14', new DateTimeZone('UTC')))
            ->setEvents([$this->weeklyWithMovedOccurrence()])
            ->getSlots();

        $this->assertSame('Moved', $slots[14]->events[0]->summary);
    }

    // -------------------------------------------------------------------------
    // JsonSerializer
    // -------------------------------------------------------------------------

    public function testJsonIncludesOffsetAndCanConvert(): void
    {
        $event = $this->event('j', new DateTimeImmutable('2025-06-15 09:00', new DateTimeZone('UTC')));

        $this->assertSame('2025-06-15T09:00:00+00:00', JsonSerializer::fromEvents([$event])->toArray()[0]['start']);
        $this->assertSame(
            '2025-06-15T11:00:00+02:00',
            JsonSerializer::fromEvents([$event])->inTimezone(new DateTimeZone('Europe/Berlin'))->toArray()[0]['start'],
        );
    }

    public function testMidnightTimedEventIsNotAllDay(): void
    {
        $event = $this->event('shift', new DateTimeImmutable('2025-06-15 00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2025-06-15 08:00', new DateTimeZone('UTC')));

        $entry = JsonSerializer::fromEvents([$event])->toArray()[0];
        $this->assertFalse($entry['allDay']);
        $this->assertSame('2025-06-15T00:00:00+00:00', $entry['start']);
    }

    public function testJsonRangeIncludesEventsSpanningIntoIt(): void
    {
        $conference = $this->event('conf', new DateTimeImmutable('2025-06-09'), new DateTimeImmutable('2025-06-14'), allDay: true);

        $result = JsonSerializer::fromEvents([$conference])
            ->forRange(new DateTimeImmutable('2025-06-11'), new DateTimeImmutable('2025-06-15'))
            ->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('2025-06-09', $result[0]['start']);
        $this->assertSame('2025-06-14', $result[0]['end']);
    }

    // -------------------------------------------------------------------------
    // ICalDataLoader
    // -------------------------------------------------------------------------

    public function testDataLoaderUsesInstancesAndSpansDays(): void
    {
        $utc  = new DateTimeZone('UTC');
        $trip = $this->event('trip', new DateTimeImmutable('2025-01-14'), new DateTimeImmutable('2025-01-17'), allDay: true, summary: 'Trip');

        $calendar = Calendar::forMonth(2025, 1, timezone: $utc)
            ->setDataLoader(ICalDataLoader::fromEvents([$this->weeklyWithMovedOccurrence(), $trip]));

        $byDate = [];
        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $day) {
                $byDate[$day->date->format('Y-m-d')] = array_map(static fn (ICalEvent $e) => $e->summary, $day->data ?? []);
            }
        }

        $this->assertSame([], $byDate['2025-01-13']);
        $this->assertSame(['Moved', 'Trip'], $byDate['2025-01-14']);
        $this->assertSame(['Trip'], $byDate['2025-01-16']);
        $this->assertSame([], $byDate['2025-01-17'], 'DTEND of an all-day event is exclusive');
    }

    public function testAllDayEventStaysOnItsDateInAnyTimezone(): void
    {
        $holiday = $this->event('h', new DateTimeImmutable('2025-01-01', new DateTimeZone('UTC')), null, allDay: true);

        foreach (['America/Los_Angeles', 'Asia/Tokyo'] as $tzName) {
            $tz       = new DateTimeZone($tzName);
            $calendar = Calendar::forMonth(2025, 1, timezone: $tz)->setDataLoader(ICalDataLoader::fromEvents([$holiday]));
            $withData = array_values(array_filter(
                array_merge(...array_values($calendar->getDaysTable())),
                static fn (Day $d) => ($d->data ?? []) !== [],
            ));

            $this->assertCount(1, $withData, $tzName);
            $this->assertSame('2025-01-01', $withData[0]->date->format('Y-m-d'), $tzName);
        }
    }
}
