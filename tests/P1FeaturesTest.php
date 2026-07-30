<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\DataLoader\DateRangeGenerator;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\ICal\Attendee;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\ICal\ICalExporter;
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\ICal\ICalTodo;
use Tito10047\Calendar\ICal\VAlarm;
use Tito10047\Calendar\Recurrence\RecurrenceRule;
use Tito10047\Calendar\View\DayView;

class P1FeaturesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // RDATE
    // -------------------------------------------------------------------------

    public function testRDateAddsExtraDates(): void
    {
        $rule = RecurrenceRule::weekly()
            ->onDays(DayName::Monday)
            ->withExtraDates(new DateTimeImmutable('2025-06-24')); // Tuesday — not normally included

        $dates = array_map(
            fn ($d) => $d->format('Y-m-d'),
            $rule->expand(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30')),
        );

        $this->assertContains('2025-06-02', $dates); // normal Monday
        $this->assertContains('2025-06-24', $dates); // extra RDATE
    }

    public function testRDatePreservedThroughWithers(): void
    {
        $rule = RecurrenceRule::monthly()
            ->withExtraDates(new DateTimeImmutable('2025-06-24'))
            ->limitTo(2);

        $this->assertSame(['2025-06-24'], $rule->getRDates());
        $this->assertSame(2, $rule->getCount());
    }

    public function testRDateParsedFromIcs(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:rdate-test@test',
            'DTSTART:20250602T090000Z',
            'RRULE:FREQ=WEEKLY;BYDAY=MO',
            'RDATE:20250624T090000Z',
            'SUMMARY:Monday + extra',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);
        $dates  = array_map(
            fn ($d) => $d->format('Y-m-d'),
            $events[0]->occurrences(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30')),
        );

        $this->assertContains('2025-06-02', $dates);
        $this->assertContains('2025-06-24', $dates); // Tuesday from RDATE
    }

    // -------------------------------------------------------------------------
    // VALARM
    // -------------------------------------------------------------------------

    public function testVAlarmRoundTrip(): void
    {
        $from = new DateTimeImmutable('2025-06-01T10:00:00Z');
        $ics  = (new ICalExporter())
            ->addEvent(title: 'Meeting', from: $from, uid: 'alarm-test@test')
            ->addICalEvent(
                (new ICalEvent(
                    uid:         'alarm-ev@test',
                    dtStart:     $from,
                    dtEnd:       null,
                    summary:     'With alarm',
                    description: null,
                    location:    null,
                    rrule:       null,
                ))->withAlarm(VAlarm::display('-PT15M', 'Reminder: With alarm'))
                  ->withAlarm(VAlarm::email('-P1D', 'Tomorrow: With alarm')),
            )
            ->export();

        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('ACTION:DISPLAY', $ics);
        $this->assertStringContainsString('TRIGGER:-PT15M', $ics);
        $this->assertStringContainsString('ACTION:EMAIL', $ics);
        $this->assertStringContainsString('END:VALARM', $ics);
    }

    public function testVAlarmParsedFromIcs(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:alarm-parse@test',
            'DTSTART:20250615T100000Z',
            'SUMMARY:Meeting with alarm',
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'TRIGGER:-PT15M',
            'DESCRIPTION:Reminder',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);
        $this->assertCount(1, $events[0]->alarms);
        $this->assertSame('DISPLAY', $events[0]->alarms[0]->action);
        $this->assertSame('-PT15M', $events[0]->alarms[0]->trigger);
        $this->assertSame('Reminder', $events[0]->alarms[0]->description);
    }

    // -------------------------------------------------------------------------
    // ORGANIZER + ATTENDEE
    // -------------------------------------------------------------------------

    public function testOrganizerAndAttendeesRoundTrip(): void
    {
        $from  = new DateTimeImmutable('2025-06-01T10:00:00Z');
        $event = (new ICalEvent(
            uid:         'meeting@test',
            dtStart:     $from,
            dtEnd:       null,
            summary:     'Team meeting',
            description: null,
            location:    null,
            rrule:       null,
        ))
            ->withOrganizer('jan@firma.sk', 'Ján Novák')
            ->withAttendee(Attendee::required('maria@firma.sk', 'Mária Horáková'))
            ->withAttendee(Attendee::optional('peter@firma.sk', 'Peter Kováč'));

        $ics = (new ICalExporter())->addICalEvent($event)->export();

        $this->assertStringContainsString('ORGANIZER', $ics);
        $this->assertStringContainsString('jan@firma.sk', $ics);
        $this->assertStringContainsString('ATTENDEE', $ics);
        $this->assertStringContainsString('maria@firma.sk', $ics);
        $this->assertStringContainsString('OPT-PARTICIPANT', $ics);
    }

    public function testOrganizerParsedFromIcs(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:organizer-test@test',
            'DTSTART:20250615T100000Z',
            'SUMMARY:Meeting',
            'ORGANIZER;CN=Jan Novak:mailto:jan@firma.sk',
            'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;CN=Maria:mailto:maria@firma.sk',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);
        $this->assertSame('jan@firma.sk', $events[0]->organizer);
        $this->assertSame('Jan Novak', $events[0]->organizerName);
        $this->assertCount(1, $events[0]->attendees);
        $this->assertSame('maria@firma.sk', $events[0]->attendees[0]->email);
        $this->assertSame('Maria', $events[0]->attendees[0]->name);
    }

    // -------------------------------------------------------------------------
    // VTODO
    // -------------------------------------------------------------------------

    public function testVTodoParsed(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VTODO',
            'UID:todo-1@test',
            'SUMMARY:Buy groceries',
            'DUE:20250620T120000Z',
            'STATUS:NEEDS-ACTION',
            'PRIORITY:1',
            'PERCENT-COMPLETE:0',
            'END:VTODO',
            'BEGIN:VTODO',
            'UID:todo-2@test',
            'SUMMARY:Send report',
            'STATUS:COMPLETED',
            'PERCENT-COMPLETE:100',
            'END:VTODO',
            'END:VCALENDAR',
        ]);

        $todos = (new ICalParser())->parseTodos($ics);
        $this->assertCount(2, $todos);
        $this->assertSame('todo-1@test', $todos[0]->uid);
        $this->assertSame('Buy groceries', $todos[0]->summary);
        $this->assertSame('2025-06-20', $todos[0]->due?->format('Y-m-d'));
        $this->assertSame(1, $todos[0]->priority);
        $this->assertFalse($todos[0]->isCompleted());
        $this->assertTrue($todos[1]->isCompleted());
        $this->assertSame(100, $todos[1]->percentComplete);
    }

    public function testVTodoToArray(): void
    {
        $todo = new ICalTodo(
            uid:             'todo-arr@test',
            summary:         'Test task',
            description:     'Details',
            due:             new DateTimeImmutable('2025-07-01T12:00:00Z'),
            dtStart:         null,
            status:          'NEEDS-ACTION',
            priority:        2,
            percentComplete: 50,
        );

        $arr = $todo->toArray();
        $this->assertSame('todo-arr@test', $arr['uid']);
        $this->assertSame(50, $arr['percentComplete']);
        $this->assertSame(2, $arr['priority']);
    }

    // -------------------------------------------------------------------------
    // fromDateRange() factory
    // -------------------------------------------------------------------------

    public function testFromDateRangeCreatesCalendar(): void
    {
        $from     = new DateTimeImmutable('2025-06-10');
        $to       = new DateTimeImmutable('2025-06-20');
        $calendar = Calendar::fromDateRange($from, $to);

        $range = $calendar->getDateRange();
        $this->assertSame('2025-06-10', $range['from']->format('Y-m-d'));
        $this->assertSame('2025-06-20', $range['to']->format('Y-m-d'));
    }

    public function testFromDateRangeHasExactDays(): void
    {
        $from     = new DateTimeImmutable('2025-06-10');
        $to       = new DateTimeImmutable('2025-06-15');
        $calendar = Calendar::fromDateRange($from, $to);

        $allDays = [];
        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $day) {
                $allDays[] = $day->date->format('Y-m-d');
            }
        }

        $this->assertSame(['2025-06-10', '2025-06-11', '2025-06-12', '2025-06-13', '2025-06-14', '2025-06-15'], $allDays);
    }

    public function testDateRangeGeneratorNavStep(): void
    {
        $from = new DateTimeImmutable('2025-06-01');
        $to   = new DateTimeImmutable('2025-06-30');
        $gen  = new DateRangeGenerator($from, $to);

        $step = $gen->getNavigationStep();
        $this->assertSame(30, (int) $step->d); // $step->days is false for new DateInterval()
    }

    // -------------------------------------------------------------------------
    // DayView
    // -------------------------------------------------------------------------

    public function testDayViewSlotCount(): void
    {
        $view  = DayView::forDate(new DateTimeImmutable('2025-06-15'))
            ->withSlotDuration(60)
            ->withRange(8, 18);
        $slots = $view->getSlots();

        $this->assertCount(10, $slots); // 8:00–18:00 = 10 hours = 10 slots
        $this->assertSame('08:00', $slots[0]->startTime->format('H:i'));
        $this->assertSame('17:00', $slots[9]->startTime->format('H:i'));
        $this->assertSame('18:00', $slots[9]->endTime->format('H:i'));
    }

    public function testDayViewSlotDuration30Min(): void
    {
        $view  = DayView::forDate(new DateTimeImmutable('2025-06-15'))
            ->withSlotDuration(30)
            ->withRange(9, 11);
        $slots = $view->getSlots();

        $this->assertCount(4, $slots); // 9:00–11:00 = 2 hours = 4 × 30min
    }

    public function testDayViewEventAppearsInSlot(): void
    {
        $event = new ICalEvent(
            uid:         'morning-meeting',
            dtStart:     new DateTimeImmutable('2025-06-15T09:30:00Z'),
            dtEnd:       new DateTimeImmutable('2025-06-15T10:30:00Z'),
            summary:     'Morning meeting',
            description: null,
            location:    null,
            rrule:       null,
        );

        $view  = DayView::forDate(new DateTimeImmutable('2025-06-15'))
            ->withSlotDuration(60)
            ->withRange(8, 12)
            ->setEvents([$event]);
        $slots = $view->getSlots();

        // 09:00–10:00 slot: event starts at 9:30, overlaps
        $this->assertNotEmpty($slots[1]->events); // slot index 1 = 09:00–10:00
        // 10:00–11:00 slot: event ends at 10:30, overlaps
        $this->assertNotEmpty($slots[2]->events); // slot index 2 = 10:00–11:00
        // 08:00–09:00 slot: no overlap
        $this->assertEmpty($slots[0]->events);
    }

    public function testDayViewInvalidSlotDurationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DayView::forDate(new DateTimeImmutable('2025-06-15'))->withSlotDuration(7);
    }
}
