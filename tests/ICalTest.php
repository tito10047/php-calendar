<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\ICal\ICalDataLoader;
use Tito10047\Calendar\ICal\ICalExporter;
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

class ICalTest extends TestCase
{
    private function sampleIcs(): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:event-001@test',
            'DTSTART:20241105T090000Z',
            'DTEND:20241105T100000Z',
            'SUMMARY:Team meeting',
            'DESCRIPTION:Weekly sync',
            'LOCATION:Room A',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-002@test',
            'DTSTART:20241101T000000Z',
            'RRULE:FREQ=WEEKLY;BYDAY=MO',
            'SUMMARY:Standup',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    // -------------------------------------------------------------------------
    // ICalParser
    // -------------------------------------------------------------------------

    public function testParseStringSingleEvent(): void
    {
        $parser = new ICalParser();
        $events = $parser->parseString($this->sampleIcs());

        $this->assertCount(2, $events);
    }

    public function testParsedEventHasCorrectFields(): void
    {
        $parser = new ICalParser();
        $events = $parser->parseString($this->sampleIcs());
        $event  = $events[0];

        $this->assertSame('event-001@test', $event->uid);
        $this->assertSame('Team meeting', $event->summary);
        $this->assertSame('Weekly sync', $event->description);
        $this->assertSame('Room A', $event->location);
        $this->assertSame('2024-11-05', $event->dtStart->format('Y-m-d'));
        $this->assertFalse($event->isRecurring());
    }

    public function testParsedRecurringEventHasRrule(): void
    {
        $parser = new ICalParser();
        $events = $parser->parseString($this->sampleIcs());
        $event  = $events[1];

        $this->assertTrue($event->isRecurring());
        $this->assertSame('Standup', $event->summary);
    }

    public function testParseFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ical_') . '.ics';
        file_put_contents($tmpFile, $this->sampleIcs());

        $parser = new ICalParser();
        $events = $parser->parseFile($tmpFile);
        unlink($tmpFile);

        $this->assertCount(2, $events);
    }

    public function testParseExDate(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:ex-001@test',
            'DTSTART:20241104T000000Z',
            'RRULE:FREQ=WEEKLY;BYDAY=MO',
            'EXDATE:20241111T000000Z',
            'SUMMARY:Weekly Mon',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $parser = new ICalParser();
        $events = $parser->parseString($ics);
        $event  = $events[0];

        $from        = new DateTimeImmutable('2024-11-01');
        $to          = new DateTimeImmutable('2024-11-30');
        $occurrences = array_map(fn ($d) => $d->format('Y-m-d'), $event->occurrences($from, $to));

        $this->assertNotContains('2024-11-11', $occurrences, 'EXDATE should exclude 2024-11-11');
        $this->assertContains('2024-11-04', $occurrences);
        $this->assertContains('2024-11-18', $occurrences);
    }

    public function testLineFolding(): void
    {
        // Lines folded with CRLF+SPACE must be unfolded transparently
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:fold@test\r\nDTSTART:20241105T090000Z\r\nSUMMARY:A very long title that would normally be folded across\r\n  multiple lines in a real iCal feed\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $parser = new ICalParser();
        $events = $parser->parseString($ics);

        $this->assertCount(1, $events);
        $this->assertStringContainsString('A very long title', $events[0]->summary ?? '');
    }

    // -------------------------------------------------------------------------
    // ICalDataLoader + Calendar integration
    // -------------------------------------------------------------------------

    public function testICalDataLoaderPopulatesCalendar(): void
    {
        $parser = new ICalParser();
        $events = $parser->parseString($this->sampleIcs());
        $loader = ICalDataLoader::fromEvents($events);

        $calendar = (new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly))
            ->setDataLoader($loader);

        $hasMeeting = false;
        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $day) {
                if ($day->date->format('Y-m-d') === '2024-11-05') {
                    $this->assertIsArray($day->data);
                    $this->assertNotEmpty($day->data);
                    $this->assertSame('Team meeting', $day->data[0]['summary']);
                    $hasMeeting = true;
                }
            }
        }

        $this->assertTrue($hasMeeting, 'Expected Team meeting on 2024-11-05');
    }

    // -------------------------------------------------------------------------
    // ICalExporter
    // -------------------------------------------------------------------------

    public function testExportProducesValidIcalHeader(): void
    {
        $exporter = new ICalExporter();
        $ics      = $exporter->export();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function testExportSingleEvent(): void
    {
        $from = new DateTimeImmutable('2024-11-05T09:00:00Z');
        $to   = new DateTimeImmutable('2024-11-05T10:00:00Z');

        $ics = (new ICalExporter())
            ->addEvent(title: 'Team meeting', from: $from, to: $to, uid: 'test-uid@test')
            ->export();

        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Team meeting', $ics);
        $this->assertStringContainsString('UID:test-uid@test', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
    }

    public function testExportRecurringEvent(): void
    {
        $rule  = RecurrenceRule::weekly()->onDays(DayName::Monday);
        $start = new DateTimeImmutable('2024-11-04T09:00:00Z');

        $ics = (new ICalExporter())
            ->addRecurringEvent(title: 'Standup', rule: $rule, start: $start)
            ->export();

        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=MO', $ics);
    }

    public function testExportIsImmutable(): void
    {
        $base  = new ICalExporter();
        $from  = new DateTimeImmutable('2024-11-05T09:00:00Z');
        $with  = $base->addEvent(title: 'Meeting', from: $from);

        $this->assertStringNotContainsString('VEVENT', $base->export());
        $this->assertStringContainsString('VEVENT', $with->export());
    }

    public function testExportRoundTrip(): void
    {
        $from   = new DateTimeImmutable('2024-11-05T09:00:00Z');
        $to     = new DateTimeImmutable('2024-11-05T10:00:00Z');

        $ics = (new ICalExporter())
            ->calendarName('My Calendar')
            ->addEvent(title: 'Round-trip event', from: $from, to: $to, description: 'Test desc')
            ->export();

        $parser = new ICalParser();
        $events = $parser->parseString($ics);

        $this->assertCount(1, $events);
        $this->assertSame('Round-trip event', $events[0]->summary);
    }

    public function testExportEscapesSpecialChars(): void
    {
        $from = new DateTimeImmutable('2024-11-05T09:00:00Z');

        $ics = (new ICalExporter())
            ->addEvent(title: 'Meeting; with, special chars', from: $from)
            ->export();

        $this->assertStringContainsString('SUMMARY:Meeting\; with\, special chars', $ics);
    }
}
