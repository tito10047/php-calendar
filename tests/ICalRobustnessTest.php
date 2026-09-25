<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\ICal\Attendee;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\ICal\ICalExporter;
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\ICal\VAlarm;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Parser / exporter correctness and injection safety (RFC 5545 §3.1, §3.2, §3.3.11).
 */
final class ICalRobustnessTest extends TestCase
{
    /** @param list<string> $eventLines */
    private function ics(array $eventLines): string
    {
        return implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:VEVENT', ...$eventLines, 'END:VEVENT', 'END:VCALENDAR']) . "\r\n";
    }

    private function parseOne(string $ics): ICalEvent
    {
        $events = (new ICalParser(defaultTimezone: new DateTimeZone('UTC')))->parseString($ics);
        $this->assertCount(1, $events);
        return $events[0];
    }

    // -------------------------------------------------------------------------
    // Parser
    // -------------------------------------------------------------------------

    public function testTextValuesAreUnescaped(): void
    {
        $event = $this->parseOne($this->ics([
            'UID:t1', 'DTSTART:20240101T100000Z',
            'SUMMARY:a\, b\; c\nd\\\\e',
            'CATEGORIES:x\,y,z',
        ]));

        $this->assertSame("a, b; c\nd\\e", $event->summary);
        $this->assertSame(['x,y', 'z'], $event->categories);
    }

    public function testQuotedParameterValues(): void
    {
        $event = $this->parseOne($this->ics([
            'UID:t2',
            'DTSTART;TZID="America/New_York":20240101T090000',
            'ORGANIZER;CN="Doe: John":mailto:j@x.com',
            'ATTENDEE;CN="Smith; Ann";ROLE=CHAIR;PARTSTAT=ACCEPTED:mailto:ann@x.com',
            'ATTENDEE;CN=Caret ^\'Quote^\' ^^:mailto:c@x.com',
        ]));

        $this->assertSame('America/New_York', $event->dtStart->getTimezone()->getName());
        $this->assertSame('09:00', $event->dtStart->format('H:i'));
        $this->assertSame('Doe: John', $event->organizerName);
        $this->assertSame('j@x.com', $event->organizer);
        $this->assertSame('Smith; Ann', $event->attendees[0]->name);
        $this->assertSame('CHAIR', $event->attendees[0]->role);
        $this->assertSame('ann@x.com', $event->attendees[0]->email);
        $this->assertSame('Caret "Quote" ^', $event->attendees[1]->name);
    }

    public function testCommaSeparatedExdates(): void
    {
        $event = $this->parseOne($this->ics([
            'UID:t3', 'DTSTART:20240101T090000Z', 'RRULE:FREQ=DAILY;COUNT=5',
            'EXDATE:20240102T090000Z,20240103T090000Z',
        ]));

        $this->assertCount(2, $event->exDates);
        $this->assertSame(
            ['2024-01-01', '2024-01-04', '2024-01-05'],
            array_map(static fn ($d) => $d->format('Y-m-d'), $event->occurrences(new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-31'))),
        );
    }

    public function testDateValuesAreMidnightAndAllDay(): void
    {
        $event = $this->parseOne($this->ics(['UID:t4', 'DTSTART;VALUE=DATE:20240101', 'DTEND;VALUE=DATE:20240104']));

        $this->assertTrue($event->allDay);
        $this->assertSame('2024-01-01 00:00:00', $event->dtStart->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-04 00:00:00', $event->dtEnd?->format('Y-m-d H:i:s'));

        $ics = (new ICalExporter())->addICalEvent($event)->export();
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20240101', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20240104', $ics);
    }

    public function testFloatingTimeUsesDefaultTimezone(): void
    {
        $events = (new ICalParser(defaultTimezone: new DateTimeZone('Europe/Bratislava')))
            ->parseString($this->ics(['UID:t5', 'DTSTART:20240101T090000']));

        $this->assertSame('Europe/Bratislava', $events[0]->dtStart->getTimezone()->getName());
    }

    public function testUnsupportedOrBrokenRruleDoesNotDropTheFeed(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT', 'UID:a', 'DTSTART:20240101T090000Z', 'RRULE:FREQ=SOMETIMES', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:b', 'DTSTART:20240101T090000Z', 'RRULE:FREQ=HOURLY;COUNT=2', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:c', 'DTSTART:20240101T090000Z', 'RRULE:FREQ=DAILY;INTERVAL=0', 'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);

        $this->assertCount(3, $events);
        $this->assertNull($events[0]->rrule);
        $this->assertNotNull($events[1]->rrule);
        $this->assertNull($events[2]->rrule);
    }

    public function testComponentNamesAreCaseInsensitiveAndBomIsIgnored(): void
    {
        $ics = "\xEF\xBB\xBFbegin:vcalendar\nbegin:vevent\nuid:lc\ndtstart:20240101T090000Z\nsummary:Lower\nend:vevent\nend:vcalendar\n";

        $events = (new ICalParser())->parseString($ics);

        $this->assertCount(1, $events);
        $this->assertSame('Lower', $events[0]->summary);
    }

    public function testOrphanOverrideIsReturnedAsEvent(): void
    {
        $event = $this->parseOne($this->ics([
            'UID:invite', 'RECURRENCE-ID:20240110T090000Z', 'DTSTART:20240110T100000Z', 'SUMMARY:Single instance',
        ]));

        $this->assertSame('Single instance', $event->summary);
        $this->assertCount(1, $event->occurrences(new DateTimeImmutable('2024-01-10'), new DateTimeImmutable('2024-01-10')));
    }

    public function testRecurrenceIdRangeThisAndFutureIsParsed(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT', 'UID:r', 'DTSTART:20240101T090000Z', 'DTEND:20240101T100000Z', 'RRULE:FREQ=WEEKLY;COUNT=4', 'SUMMARY:Old', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:r', 'RECURRENCE-ID;RANGE=THISANDFUTURE:20240115T090000Z', 'DTSTART:20240115T130000Z', 'DTEND:20240115T140000Z', 'SUMMARY:New', 'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $master    = (new ICalParser())->parseString($ics)[0];
        $instances = $master->expandOccurrences(new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-31'));

        $this->assertSame(['Old', 'Old', 'New', 'New'], array_map(static fn (ICalEvent $e) => $e->summary, $instances));
        $this->assertSame('13:00', $instances[3]->dtStart->format('H:i'));
    }

    public function testRdateWithoutRrule(): void
    {
        $event = $this->parseOne($this->ics([
            'UID:rd', 'DTSTART:20240101T090000Z', 'RDATE:20240105T090000Z,20240110T090000Z',
        ]));

        $this->assertSame(
            ['2024-01-01', '2024-01-05', '2024-01-10'],
            array_map(static fn ($d) => $d->format('Y-m-d'), $event->occurrences(new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-31'))),
        );
    }

    public function testNegativeDuration(): void
    {
        $event = $this->parseOne($this->ics(['UID:dur', 'DTSTART:20240101T090000Z', 'DURATION:PT1H30M']));
        $this->assertSame('10:30', $event->dtEnd?->format('H:i'));
    }

    public function testParseUrlRejectsNonHttpSchemes(): void
    {
        foreach (['php://filter/resource=/etc/passwd', 'file:///etc/passwd', '/etc/passwd', 'ftp://example.com/a.ics'] as $url) {
            try {
                (new ICalParser())->parseUrl($url);
                $this->fail("{$url} must be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testParseFileEnforcesSizeLimit(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ics');
        $this->assertNotFalse($file);
        file_put_contents($file, str_repeat('X', 200));

        try {
            $this->expectException(\RuntimeException::class);
            (new ICalParser(maxBytes: 100))->parseFile($file);
        } finally {
            unlink($file);
        }
    }

    // -------------------------------------------------------------------------
    // Exporter
    // -------------------------------------------------------------------------

    public function testPropertyInjectionIsNeutralised(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title: "Title\r\nX-EVIL:1",
                from: new DateTimeImmutable('2024-01-01T10:00:00Z'),
                uid: "uid\r\nEND:VEVENT",
                url: "http://a\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:evil",
                color: "red\r\nX-EVIL:2",
            )
            ->export();

        $this->assertSame(1, substr_count($ics, "\r\nBEGIN:VEVENT\r\n"));
        $this->assertStringNotContainsString("\r\nX-EVIL", $ics);
        $this->assertStringNotContainsString("\r\nUID:evil", $ics);

        $events = (new ICalParser())->parseString($ics);
        $this->assertCount(1, $events);
        $this->assertSame("Title\nX-EVIL:1", $events[0]->summary);
    }

    public function testParameterInjectionIsNeutralised(): void
    {
        $event = (new ICalEvent(
            uid: 'p',
            dtStart: new DateTimeImmutable('2024-01-01T10:00:00Z'),
            dtEnd: null,
            summary: 'S',
            description: null,
            location: null,
            rrule: null,
        ))
            ->withOrganizer("boss@x.com\r\nX-EVIL:1", 'Doe, John: CEO')
            ->withAttendee(new Attendee("a@x\r\nX-EVIL:1", 'Bob;ROLE=CHAIR', 'REQ-PARTICIPANT;X=1'))
            ->withAlarm(new VAlarm("DISPLAY\r\nX-EVIL:1", "-PT15M\r\nX-EVIL:2", "Remind\r\nme"));

        $ics = (new ICalExporter())->addICalEvent($event)->export();

        $this->assertStringNotContainsString("\r\nX-EVIL", $ics);
        $this->assertStringContainsString('ORGANIZER;CN="Doe, John: CEO":mailto:boss@x.comX-EVIL:1', $ics);

        $parsed = (new ICalParser())->parseString($ics)[0];
        $this->assertSame('Doe, John: CEO', $parsed->organizerName);
        $this->assertSame('Bob;ROLE=CHAIR', $parsed->attendees[0]->name);
        $this->assertSame('REQ-PARTICIPANTX1', $parsed->attendees[0]->role);
        $this->assertSame("Remind\nme", $parsed->alarms[0]->description);
    }

    public function testTextRoundTripDoesNotDoubleEscape(): void
    {
        $original = "a, b; c\nd\\e";
        $ics      = (new ICalExporter())->addEvent(title: $original, from: new DateTimeImmutable('2024-01-01T10:00:00Z'))->export();
        $parsed   = (new ICalParser())->parseString($ics)[0];
        $again    = (new ICalExporter())->addICalEvent($parsed)->export();

        $this->assertSame($original, $parsed->summary);
        $this->assertSame((new ICalParser())->parseString($again)[0]->summary, $original);
    }

    public function testCarriageReturnIsNotWrittenRaw(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(title: 'x', from: new DateTimeImmutable('2024-01-01T10:00:00Z'), description: "line1\r\nline2")
            ->export();

        $this->assertStringContainsString('DESCRIPTION:line1\nline2', $ics);
        $this->assertSame(substr_count($ics, "\r"), substr_count($ics, "\r\n"));
    }

    public function testFoldingKeepsUtf8CharactersIntact(): void
    {
        $ics = (new ICalExporter())->addEvent(title: str_repeat('é', 60), from: new DateTimeImmutable('2024-01-01T10:00:00Z'))->export();

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "Folded line is not valid UTF-8: {$line}");
        }
        $this->assertSame(str_repeat('é', 60), (new ICalParser())->parseString($ics)[0]->summary);
    }

    public function testMetadataTimestampsAreConvertedToUtc(): void
    {
        $ny    = new DateTimeZone('America/New_York');
        $event = new ICalEvent(
            uid: 'm',
            dtStart: new DateTimeImmutable('2024-01-01T10:00:00Z'),
            dtEnd: null,
            summary: 'S',
            description: null,
            location: null,
            rrule: null,
            dtStamp: new DateTimeImmutable('2024-01-01 09:00', $ny),
            created: new DateTimeImmutable('2024-01-01 09:00', $ny),
            lastModified: new DateTimeImmutable('2024-01-01 09:00', $ny),
        );

        $ics = (new ICalExporter())->addICalEvent($event)->export();

        $this->assertStringContainsString('DTSTAMP:20240101T140000Z', $ics);
        $this->assertStringContainsString('CREATED:20240101T140000Z', $ics);
        $this->assertStringContainsString('LAST-MODIFIED:20240101T140000Z', $ics);
    }

    public function testRecurrenceDataSurvivesRoundTrip(): void
    {
        $source = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT', 'UID:rt', 'DTSTART;TZID=Europe/Bratislava:20240101T090000', 'DTEND;TZID=Europe/Bratislava:20240101T100000',
            'RRULE:FREQ=WEEKLY;COUNT=6', 'EXDATE;TZID=Europe/Bratislava:20240108T090000', 'RDATE;TZID=Europe/Bratislava:20240103T090000',
            'SUMMARY:Series', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:rt', 'RECURRENCE-ID;TZID=Europe/Bratislava:20240115T090000',
            'DTSTART;TZID=Europe/Bratislava:20240115T150000', 'DTEND;TZID=Europe/Bratislava:20240115T160000', 'SUMMARY:Moved', 'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $parser = new ICalParser();
        $ics    = (new ICalExporter())->addICalEvent($parser->parseString($source)[0])->export();

        $this->assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        $this->assertStringContainsString('TZID:Europe/Bratislava', $ics);
        $this->assertStringContainsString('EXDATE;TZID=Europe/Bratislava:20240108T090000', $ics);
        $this->assertStringContainsString('RDATE;TZID=Europe/Bratislava:20240103T090000', $ics);
        $this->assertStringContainsString('RECURRENCE-ID;TZID=Europe/Bratislava:20240115T090000', $ics);

        $from = new DateTimeImmutable('2024-01-01', new DateTimeZone('Europe/Bratislava'));
        $to   = new DateTimeImmutable('2024-02-29', new DateTimeZone('Europe/Bratislava'));
        $this->assertEquals(
            array_map(static fn (ICalEvent $e) => [$e->summary, $e->dtStart->format('c')], $parser->parseString($source)[0]->expandOccurrences($from, $to)),
            array_map(static fn (ICalEvent $e) => [$e->summary, $e->dtStart->format('c')], $parser->parseString($ics)[0]->expandOccurrences($from, $to)),
        );
    }

    public function testAllDaySeriesUntilIsWrittenAsDate(): void
    {
        $ics = (new ICalExporter())
            ->addRecurringEvent(
                title: 'Holiday',
                rule: RecurrenceRule::yearly()->until(new DateTimeImmutable('2030-12-31')),
                start: new DateTimeImmutable('2024-12-25'),
                end: new DateTimeImmutable('2024-12-26'),
                allDay: true,
            )
            ->export();

        $this->assertStringContainsString('RRULE:FREQ=YEARLY;UNTIL=20301231', $ics);
        $this->assertStringNotContainsString('UNTIL=20301231T', $ics);
    }
}
