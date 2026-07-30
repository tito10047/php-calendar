<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\ICal\CalDAVClient;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\ICal\ICalExporter;

final class P3FeaturesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // P3: Streaming iCal export
    // -------------------------------------------------------------------------

    public function testExportToStreamMatchesExportString(): void
    {
        $exporter = (new ICalExporter())
            ->calendarName('Stream Test')
            ->addEvent(
                title:       'Event A',
                from:        new DateTimeImmutable('2025-06-01T10:00:00Z'),
                to:          new DateTimeImmutable('2025-06-01T11:00:00Z'),
                description: 'Desc A',
            )
            ->addEvent(
                title: 'Event B',
                from:  new DateTimeImmutable('2025-07-15T09:00:00Z'),
            );

        $expected = $exporter->export();

        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        $exporter->exportToStream($stream);
        rewind($stream);
        $actual = stream_get_contents($stream);
        fclose($stream);

        $this->assertIsString($actual);

        // Strip the dynamic DTSTAMP lines before comparing (they may differ by a second)
        $normalise = static fn (string $ics): string => preg_replace('/^DTSTAMP:.*$/m', 'DTSTAMP:REDACTED', $ics) ?? $ics;

        $this->assertSame($normalise($expected), $normalise($actual));
    }

    public function testExportToStreamContainsAllEvents(): void
    {
        $from1 = new DateTimeImmutable('2025-06-01T10:00:00Z');
        $from2 = new DateTimeImmutable('2025-07-15T09:00:00Z');

        $exporter = (new ICalExporter())
            ->addEvent(title: 'Alpha', from: $from1)
            ->addEvent(title: 'Beta', from: $from2);

        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        $exporter->exportToStream($stream);
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('SUMMARY:Alpha', $output);
        $this->assertStringContainsString('SUMMARY:Beta', $output);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $output);
        $this->assertStringContainsString('END:VCALENDAR', $output);
    }

    public function testExportToStreamRespectsFolding(): void
    {
        $longTitle = str_repeat('A', 100);
        $exporter  = (new ICalExporter())->addEvent(title: $longTitle, from: new DateTimeImmutable('2025-06-01T10:00:00Z'));

        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        $exporter->exportToStream($stream);
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        // Each physical line must be ≤ 75 chars (ignoring CRLF)
        foreach (explode("\r\n", rtrim($output)) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Line too long: {$line}");
        }
    }

    public function testExportToStreamPreservesRecurringRule(): void
    {
        $rule    = \Tito10047\Calendar\Recurrence\RecurrenceRule::weekly();
        $exporter = (new ICalExporter())
            ->addRecurringEvent(title: 'Weekly', rule: $rule, start: new DateTimeImmutable('2025-06-02T08:00:00Z'));

        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        $exporter->exportToStream($stream);
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('RRULE:FREQ=WEEKLY', $output);
    }

    // -------------------------------------------------------------------------
    // P3: CalDAVClient
    // -------------------------------------------------------------------------

    public function testCalDAVClientInstantiates(): void
    {
        $client = new CalDAVClient('https://example.com/dav/');
        $this->assertInstanceOf(CalDAVClient::class, $client);
    }

    public function testCalDAVClientFluentAuthentication(): void
    {
        $client  = new CalDAVClient('https://example.com/dav/');
        $client2 = $client->authenticate('user', 'pass');

        // Fluent — returns a new instance
        $this->assertNotSame($client, $client2);
    }

    public function testCalDAVClientWithTimeout(): void
    {
        $client  = new CalDAVClient('https://example.com/dav/');
        $client2 = $client->withTimeout(60);

        $this->assertNotSame($client, $client2);
        $this->assertInstanceOf(CalDAVClient::class, $client2);
    }

    public function testCalDAVClientParsesMultistatusXml(): void
    {
        // Simulate a CalDAV server response containing one VEVENT via a
        // reflection call to the private parseMultiResponse method.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n" .
               "UID:caldav-unit@test\r\nDTSTART:20250601T100000Z\r\n" .
               "SUMMARY:CalDAV event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:response>'
            . '<d:href>/dav/user/calendar/event1.ics</d:href>'
            . '<d:propstat>'
            . '<d:prop><c:calendar-data>' . htmlspecialchars($ics) . '</c:calendar-data></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status>'
            . '</d:propstat>'
            . '</d:response>'
            . '</d:multistatus>';

        $client = new CalDAVClient('https://example.com/dav/');
        $ref    = new \ReflectionMethod($client, 'parseMultiResponse');
        /** @var list<ICalEvent> $events */
        $events = $ref->invoke($client, $xml);

        $this->assertCount(1, $events);
        $this->assertSame('caldav-unit@test', $events[0]->uid);
        $this->assertSame('CalDAV event', $events[0]->summary);
    }

    public function testCalDAVClientHandlesEmptyMultistatus(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '</d:multistatus>';

        $client = new CalDAVClient('https://example.com/dav/');
        $ref    = new \ReflectionMethod($client, 'parseMultiResponse');
        /** @var list<ICalEvent> $events */
        $events = $ref->invoke($client, $xml);

        $this->assertSame([], $events);
    }

    public function testCalDAVClientExtractsHrefs(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:">'
            . '<d:response><d:href>/dav/user/personal/</d:href></d:response>'
            . '<d:response><d:href>/dav/user/work/</d:href></d:response>'
            . '</d:multistatus>';

        $client = new CalDAVClient('https://example.com/dav/');
        $ref    = new \ReflectionMethod($client, 'extractHrefs');
        /** @var list<string> $hrefs */
        $hrefs = $ref->invoke($client, $xml);

        $this->assertCount(2, $hrefs);
        $this->assertContains('/dav/user/personal/', $hrefs);
        $this->assertContains('/dav/user/work/', $hrefs);
    }

    public function testCalDAVClientBuildReportBody(): void
    {
        $client = new CalDAVClient('https://example.com/dav/');
        $ref    = new \ReflectionMethod($client, 'buildReportBody');
        $from   = new DateTimeImmutable('2025-01-01T00:00:00Z');
        $to     = new DateTimeImmutable('2025-12-31T23:59:59Z');
        $body   = $ref->invoke($client, $from, $to);

        $this->assertStringContainsString('calendar-query', $body);
        $this->assertStringContainsString('VEVENT', $body);
        $this->assertStringContainsString('20250101T000000Z', $body);
        $this->assertStringContainsString('20251231T235959Z', $body);
    }
}
