<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\ICal\ICalExporter;
use Tito10047\Calendar\ICal\ICalParser;

/**
 * K1 — all-day events (RFC 5545 §3.6.1, DATE value type).
 *
 * An all-day event is written as DTSTART;VALUE=DATE with no time and no
 * timezone. DTEND is exclusive: a single day starting on 2026-09-25 ends on
 * 2026-09-26.
 */
final class AllDayEventTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    public function testAllDayEventIsExportedAsDateValue(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:  'Morning walk',
                from:   new DateTimeImmutable('2026-09-25 00:00:00', new DateTimeZone('Europe/Bratislava')),
                uid:    'day-1@budem.sk',
                allDay: true,
            )
            ->export();

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260925\r\n", $ics);
        $this->assertStringNotContainsString('DTSTART;TZID', $ics);
        $this->assertStringNotContainsString('T000000', $ics);
    }

    public function testSingleAllDayEventEndsOnTheNextDay(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:  'Morning walk',
                from:   new DateTimeImmutable('2026-09-25'),
                uid:    'day-1@budem.sk',
                allDay: true,
            )
            ->export();

        $this->assertStringContainsString("DTEND;VALUE=DATE:20260926\r\n", $ics);
    }

    public function testAllDayEventOverMonthBoundaryEndsOnTheFirstOfNextMonth(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:  'Last day of September',
                from:   new DateTimeImmutable('2026-09-30'),
                uid:    'day-2@budem.sk',
                allDay: true,
            )
            ->export();

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260930\r\n", $ics);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20261001\r\n", $ics);
    }

    public function testAllDayEventOnLeapDay(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:  'Leap day',
                from:   new DateTimeImmutable('2028-02-29'),
                uid:    'day-3@budem.sk',
                allDay: true,
            )
            ->export();

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20280229\r\n", $ics);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20280301\r\n", $ics);
    }

    public function testExplicitEndIsKeptAsGivenAndTreatedAsExclusive(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:  'Holiday',
                from:   new DateTimeImmutable('2026-09-25'),
                to:     new DateTimeImmutable('2026-09-28'),
                uid:    'day-4@budem.sk',
                allDay: true,
            )
            ->export();

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260925\r\n", $ics);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20260928\r\n", $ics);
    }

    public function testTimedEventIsStillExportedWithTime(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title: 'Team meeting',
                from:  new DateTimeImmutable('2026-09-25T10:00:00Z'),
                to:    new DateTimeImmutable('2026-09-25T11:00:00Z'),
                uid:   'meeting@example.com',
            )
            ->export();

        $this->assertStringContainsString("DTSTART:20260925T100000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20260925T110000Z\r\n", $ics);
        $this->assertStringNotContainsString('VALUE=DATE', $ics);
    }

    public function testRecurringAllDayEventExportsDateValues(): void
    {
        $event = new ICalEvent(
            uid:         'weekly@budem.sk',
            dtStart:     new DateTimeImmutable('2026-09-25'),
            dtEnd:       null,
            summary:     'Weekly',
            description: null,
            location:    null,
            rrule:       \Tito10047\Calendar\Recurrence\RecurrenceRule::fromRrule('FREQ=WEEKLY;COUNT=3'),
            allDay:      true,
        );

        $ics = (new ICalExporter())->addICalEvent($event)->export();

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260925\r\n", $ics);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;COUNT=3', $ics);
    }

    // -------------------------------------------------------------------------
    // Parse
    // -------------------------------------------------------------------------

    public function testParserMarksDateValueEventsAsAllDay(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:day-1@budem.sk',
            'DTSTART;VALUE=DATE:20260925',
            'DTEND;VALUE=DATE:20260926',
            'SUMMARY:Morning walk',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->allDay);
        $this->assertSame('2026-09-25 00:00:00', $events[0]->dtStart->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-26 00:00:00', $events[0]->dtEnd?->format('Y-m-d H:i:s'));
    }

    public function testParserKeepsMidnightForDateOnlyValues(): void
    {
        // createFromFormat() without a reset fills the time from "now" — a
        // date-only value must always land on midnight, whatever the hour is.
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:x@budem.sk',
            'DTSTART:20260925',
            'SUMMARY:No time here',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);

        $this->assertSame('00:00:00', $events[0]->dtStart->format('H:i:s'));
        $this->assertTrue($events[0]->allDay);
    }

    public function testParserLeavesTimedEventsNotAllDay(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:meeting@example.com',
            'DTSTART:20260925T100000Z',
            'DTEND:20260925T110000Z',
            'SUMMARY:Team meeting',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);

        $this->assertFalse($events[0]->allDay);
    }

    // -------------------------------------------------------------------------
    // Round trip
    // -------------------------------------------------------------------------

    public function testAllDayRoundTripKeepsTheSameDate(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:  'Morning walk',
                from:   new DateTimeImmutable('2026-09-25'),
                uid:    'day-1@budem.sk',
                allDay: true,
            )
            ->export();

        $parsed = (new ICalParser())->parseString($ics);
        $again  = (new ICalExporter())->addICalEvent($parsed[0])->export();

        $this->assertTrue($parsed[0]->allDay);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260925\r\n", $again);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20260926\r\n", $again);
    }

    public function testAllDayEventSurvivesOccurrenceExpansion(): void
    {
        $event = new ICalEvent(
            uid:         'weekly@budem.sk',
            dtStart:     new DateTimeImmutable('2026-09-25'),
            dtEnd:       null,
            summary:     'Weekly',
            description: null,
            location:    null,
            rrule:       \Tito10047\Calendar\Recurrence\RecurrenceRule::fromRrule('FREQ=WEEKLY;COUNT=3'),
            allDay:      true,
        );

        $occurrences = $event->expandOccurrences(
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-10-31'),
        );

        $this->assertCount(3, $occurrences);
        foreach ($occurrences as $occurrence) {
            $this->assertTrue($occurrence->allDay);
        }
    }
}
