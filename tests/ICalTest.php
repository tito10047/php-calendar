<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Enum\EventStatus;
use Tito10047\Calendar\ICal\ICalDataLoader;
use Tito10047\Calendar\ICal\ICalEvent;
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
                    $this->assertSame('Team meeting', $day->data[0]->summary);
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

    public function testExportEventWithUrl(): void
    {
        $from = new DateTimeImmutable('2024-11-05T09:00:00Z');

        $ics = (new ICalExporter())
            ->addEvent(title: 'Meeting', from: $from, url: 'https://example.com/event/1')
            ->export();

        $this->assertStringContainsString('URL:https://example.com/event/1', $ics);
    }

    public function testExportRecurringEventWithUrl(): void
    {
        $rule  = RecurrenceRule::weekly()->onDays(DayName::Monday);
        $start = new DateTimeImmutable('2024-11-04T09:00:00Z');

        $ics = (new ICalExporter())
            ->addRecurringEvent(title: 'Standup', rule: $rule, start: $start, url: 'https://example.com/standup')
            ->export();

        $this->assertStringContainsString('URL:https://example.com/standup', $ics);
    }

    public function testExportUrlIsNotTextEscaped(): void
    {
        // RFC 5545 §3.8.4.6: URL value type is URI, not TEXT — no backslash escaping
        $from = new DateTimeImmutable('2024-11-05T09:00:00Z');

        $ics = (new ICalExporter())
            ->addEvent(title: 'Meeting', from: $from, url: 'https://example.com/path?a=1&b=2,3')
            ->export();

        $this->assertStringContainsString('URL:https://example.com/path?a=1&b=2,3', $ics);
        $this->assertStringNotContainsString('URL:https://example.com/path?a=1&b=2\,3', $ics);
    }

    public function testExportWithoutUrlOmitsUrlLine(): void
    {
        $from = new DateTimeImmutable('2024-11-05T09:00:00Z');

        $ics = (new ICalExporter())
            ->addEvent(title: 'Meeting', from: $from)
            ->export();

        $this->assertStringNotContainsString('URL:', $ics);
    }

    public function testParserParsesUrl(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:url-001@test',
            'DTSTART:20241105T090000Z',
            'SUMMARY:Event with URL',
            'URL:https://example.com/event/1',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $events = (new ICalParser())->parseString($ics);

        $this->assertCount(1, $events);
        $this->assertSame('https://example.com/event/1', $events[0]->url);
    }

    public function testParserEventWithoutUrlHasNullUrl(): void
    {
        $parser = new ICalParser();
        $events = $parser->parseString($this->sampleIcs());

        $this->assertNull($events[0]->url);
    }

    public function testUrlRoundTrip(): void
    {
        $from = new DateTimeImmutable('2024-11-05T09:00:00Z');
        $url  = 'https://example.com/event/42';

        $ics = (new ICalExporter())
            ->addEvent(title: 'Round-trip URL', from: $from, uid: 'rt-url@test', url: $url)
            ->export();

        $events = (new ICalParser())->parseString($ics);

        $this->assertCount(1, $events);
        $this->assertSame($url, $events[0]->url);
    }

    public function testICalEventToArrayIncludesUrl(): void
    {
        $from  = new DateTimeImmutable('2024-11-05T09:00:00Z');
        $url   = 'https://example.com/event/1';

        $ics    = (new ICalExporter())
            ->addEvent(title: 'Meeting', from: $from, uid: 'arr-url@test', url: $url)
            ->export();
        $events = (new ICalParser())->parseString($ics);

        $arr = $events[0]->toArray();
        $this->assertArrayHasKey('url', $arr);
        $this->assertSame($url, $arr['url']);
    }

    // -------------------------------------------------------------------------
    // COLOR, CATEGORIES, STATUS
    // -------------------------------------------------------------------------

    public function testColorRoundTrip(): void
    {
        $from = new DateTimeImmutable('2025-06-01T10:00:00Z');
        $ics  = (new ICalExporter())
            ->addEvent(title: 'Dovolenka', from: $from, uid: 'color-1@test', color: '#e74c3c')
            ->export();

        $events = (new ICalParser())->parseString($ics);
        $this->assertSame('#e74c3c', $events[0]->color);
    }

    public function testCategoriesRoundTrip(): void
    {
        $from = new DateTimeImmutable('2025-06-01T10:00:00Z');
        $ics  = (new ICalExporter())
            ->addEvent(title: 'Meeting', from: $from, uid: 'cat-1@test', categories: ['Osobné', 'Voľno'])
            ->export();

        $events = (new ICalParser())->parseString($ics);
        $this->assertSame(['Osobné', 'Voľno'], $events[0]->categories);
    }

    public function testStatusRoundTrip(): void
    {
        $from = new DateTimeImmutable('2025-06-01T10:00:00Z');
        $ics  = (new ICalExporter())
            ->addEvent(title: 'Tentative', from: $from, uid: 'status-1@test', status: EventStatus::Tentative)
            ->export();

        $events = (new ICalParser())->parseString($ics);
        $this->assertSame(EventStatus::Tentative, $events[0]->status);
    }

    public function testAllThreeFieldsParsedFromIcs(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:rich-event@test',
            'DTSTART:20250601T100000Z',
            'DTEND:20250601T110000Z',
            'SUMMARY:Rich event',
            'COLOR:#3498db',
            'CATEGORIES:Work,Important',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);
        $this->assertCount(1, $events);
        $this->assertSame('#3498db', $events[0]->color);
        $this->assertSame(['Work', 'Important'], $events[0]->categories);
        $this->assertSame(EventStatus::Confirmed, $events[0]->status);
    }

    public function testToArrayIncludesColorCategoriesStatus(): void
    {
        $event = new ICalEvent(
            uid:        'arr-test@test',
            dtStart:    new DateTimeImmutable('2025-06-01T10:00:00Z'),
            dtEnd:      null,
            summary:    'Test',
            description: null,
            location:   null,
            rrule:      null,
            color:      '#ff0000',
            categories: ['A', 'B'],
            status:     EventStatus::Cancelled,
        );

        $arr = $event->toArray();
        $this->assertSame('#ff0000', $arr['color']);
        $this->assertSame(['A', 'B'], $arr['categories']);
        $this->assertSame('CANCELLED', $arr['status']);
    }

    public function testUnknownStatusIgnored(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:unknown-status@test',
            'DTSTART:20250601T100000Z',
            'SUMMARY:Test',
            'STATUS:UNKNOWN_VALUE',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);
        $this->assertNull($events[0]->status);
    }

    public function testExporterWithNullColorCategoriesStatus(): void
    {
        $from = new DateTimeImmutable('2025-06-01T10:00:00Z');
        $ics  = (new ICalExporter())
            ->addEvent(title: 'Plain', from: $from, uid: 'plain@test')
            ->export();

        $this->assertStringNotContainsString('COLOR:', $ics);
        $this->assertStringNotContainsString('CATEGORIES:', $ics);
        $this->assertStringNotContainsString('STATUS:', $ics);
    }

    public function testEscapedTextComesBackUnescaped(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:       'Beh, 10 km; ráno',
                from:        new DateTimeImmutable('2026-09-25T06:00:00Z'),
                description: "10/5\nPrvý raz celá desiatka.",
                uid:         'walk@budem.sk',
            )
            ->export();

        $event = (new ICalParser())->parseString($ics)[0];

        self::assertSame('Beh, 10 km; ráno', $event->summary);
        self::assertSame("10/5\nPrvý raz celá desiatka.", $event->description);
    }

    /**
     * A carriage return used to walk straight through escapeText(), and a
     * lenient parser reads one as a line break — which is a property boundary.
     * Text that carries one must not be able to start an ATTACH, an ORGANIZER,
     * or an early END:VEVENT.
     */
    public function testACarriageReturnCannotInjectAProperty(): void
    {
        $ics = (new ICalExporter())
            ->addEvent(
                title:       "Beh\rATTACH:https://evil.example/x",
                from:        new DateTimeImmutable('2026-09-25T06:00:00Z'),
                description: "Prvý riadok\r\nDruhý\rEND:VEVENT",
                uid:         'walk@budem.sk',
            )
            ->export();

        // No bare carriage return survives, so nothing in the text can be the
        // start of a line — which is the only place a property may begin.
        self::assertStringNotContainsString("\r", str_replace("\r\n", '', $ics));

        $events = (new ICalParser())->parseString($ics);

        self::assertCount(1, $events);
        self::assertSame("Beh\nATTACH:https://evil.example/x", $events[0]->summary);
        self::assertSame("Prvý riadok\nDruhý\nEND:VEVENT", $events[0]->description);
    }
}
