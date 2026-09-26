<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\Server\CalDavServer;
use Tito10047\Calendar\Server\CalendarEventStoreInterface;

class CalDavServerTest extends TestCase
{
    private InMemoryEventStore $store;
    private CalDavServer $server;

    protected function setUp(): void
    {
        $this->store  = new InMemoryEventStore();
        $this->server = new CalDavServer($this->store, 'Test Calendar', '/caldav/');
    }

    // -------------------------------------------------------------------------
    // OPTIONS
    // -------------------------------------------------------------------------

    public function testHandleOptionsReturns200WithDavHeader(): void
    {
        $r = $this->server->handleOptions();

        self::assertSame(200, $r->statusCode);
        self::assertSame('1, 2, 3, calendar-access', $r->headers['DAV']);
        self::assertStringContainsString('PROPFIND', $r->headers['Allow']);
    }

    // -------------------------------------------------------------------------
    // PROPFIND
    // -------------------------------------------------------------------------

    public function testHandlePropfindReturns207WithCalendarName(): void
    {
        $r = $this->server->handlePropfind('', 0);

        self::assertSame(207, $r->statusCode);
        self::assertStringContainsString('Test Calendar', $r->body);
        self::assertStringContainsString('<C:calendar/>', $r->body);
        self::assertStringContainsString('/caldav/', $r->body);
    }

    public function testPropfindXmlIsWellFormed(): void
    {
        $r   = $this->server->handlePropfind('', 0);
        $xml = @simplexml_load_string($r->body);

        self::assertNotFalse($xml, 'PROPFIND response is not valid XML');
    }

    // -------------------------------------------------------------------------
    // PUT
    // -------------------------------------------------------------------------

    public function testHandlePutCreatesNewEventReturns201(): void
    {
        $ics = $this->makeIcs('test-uid-001', '20250715T090000Z', '20250715T100000Z', 'New Event');

        $r = $this->server->handlePut('test-uid-001', $ics);

        self::assertSame(201, $r->statusCode);
        $event = $this->store->getEvent('test-uid-001');
        self::assertNotNull($event);
        self::assertSame('New Event', $event->summary);
    }

    public function testHandlePutUpdatesExistingEventReturns204(): void
    {
        $ics = $this->makeIcs('test-uid-002', '20250715T090000Z', '20250715T100000Z', 'Original');
        $this->server->handlePut('test-uid-002', $ics);

        $icsUpdated = $this->makeIcs('test-uid-002', '20250715T090000Z', '20250715T100000Z', 'Updated');
        $r          = $this->server->handlePut('test-uid-002', $icsUpdated);

        self::assertSame(204, $r->statusCode);
        self::assertSame('Updated', $this->store->getEvent('test-uid-002')?->summary);
    }

    public function testHandlePutReturns400ForEmptyBody(): void
    {
        $r = $this->server->handlePut('no-event-uid', '');

        self::assertSame(400, $r->statusCode);
        self::assertNull($this->store->getEvent('no-event-uid'));
    }

    public function testHandlePutSetsEtagHeader(): void
    {
        $ics = $this->makeIcs('etag-uid', '20250715T090000Z', '20250715T100000Z', 'Event');
        $r   = $this->server->handlePut('etag-uid', $ics);

        self::assertArrayHasKey('ETag', $r->headers);
        self::assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $r->headers['ETag']);
    }

    // -------------------------------------------------------------------------
    // GET
    // -------------------------------------------------------------------------

    public function testHandleGetReturnsIcsForExistingEvent(): void
    {
        $ics = $this->makeIcs('get-uid', '20250720T080000Z', '20250720T090000Z', 'Get Test');
        $this->server->handlePut('get-uid', $ics);

        $r = $this->server->handleGet('get-uid');

        self::assertSame(200, $r->statusCode);
        self::assertStringContainsString('text/calendar', $r->contentType);
        self::assertStringContainsString('Get Test', $r->body);
        self::assertStringContainsString('BEGIN:VCALENDAR', $r->body);
    }

    public function testHandleGetReturns404ForMissingEvent(): void
    {
        $r = $this->server->handleGet('does-not-exist');

        self::assertSame(404, $r->statusCode);
    }

    public function testHandleGetSetsEtagHeader(): void
    {
        $ics = $this->makeIcs('etag-get-uid', '20250720T080000Z', '20250720T090000Z', 'Event');
        $this->server->handlePut('etag-get-uid', $ics);

        $r = $this->server->handleGet('etag-get-uid');

        self::assertArrayHasKey('ETag', $r->headers);
    }

    // -------------------------------------------------------------------------
    // DELETE
    // -------------------------------------------------------------------------

    public function testHandleDeleteRemovesEventReturns204(): void
    {
        $ics = $this->makeIcs('del-uid', '20250715T090000Z', '20250715T100000Z', 'To Delete');
        $this->server->handlePut('del-uid', $ics);

        $r = $this->server->handleDelete('del-uid');

        self::assertSame(204, $r->statusCode);
        self::assertNull($this->store->getEvent('del-uid'));
    }

    public function testHandleDeleteReturns404ForMissingEvent(): void
    {
        $r = $this->server->handleDelete('missing-uid');

        self::assertSame(404, $r->statusCode);
    }

    // -------------------------------------------------------------------------
    // REPORT
    // -------------------------------------------------------------------------

    public function testHandleReportReturns207WithEventsInRange(): void
    {
        $ics = $this->makeIcs('report-uid', '20250715T090000Z', '20250715T100000Z', 'In Range');
        $this->server->handlePut('report-uid', $ics);

        $xml = $this->makeCalendarQueryXml('20250701T000000Z', '20250801T000000Z');
        $r   = $this->server->handleReport($xml);

        self::assertSame(207, $r->statusCode);
        self::assertStringContainsString('In Range', $r->body);
        self::assertStringContainsString('report-uid', $r->body);
    }

    public function testHandleReportExcludesEventsOutsideRange(): void
    {
        $ics = $this->makeIcs('out-of-range-uid', '20250301T090000Z', '20250301T100000Z', 'Out of Range');
        $this->server->handlePut('out-of-range-uid', $ics);

        $xml = $this->makeCalendarQueryXml('20250701T000000Z', '20250801T000000Z');
        $r   = $this->server->handleReport($xml);

        self::assertStringNotContainsString('out-of-range-uid', $r->body);
    }

    public function testHandleReportXmlIsWellFormed(): void
    {
        $ics = $this->makeIcs('xml-uid', '20250715T090000Z', '20250715T100000Z', 'XML Test');
        $this->server->handlePut('xml-uid', $ics);

        $xml = $this->makeCalendarQueryXml('20250701T000000Z', '20250801T000000Z');
        $r   = $this->server->handleReport($xml);

        $parsed = @simplexml_load_string($r->body);
        self::assertNotFalse($parsed, 'REPORT response is not valid XML');
    }

    public function testHandleReportWithNoTimeRangeUsesDefaultRange(): void
    {
        $r = $this->server->handleReport('<calendar-query/>');

        self::assertSame(207, $r->statusCode);
    }

    // -------------------------------------------------------------------------
    // Dispatcher
    // -------------------------------------------------------------------------

    public function testHandleRequestDispatchesPut(): void
    {
        $ics = $this->makeIcs('dispatch-uid', '20250715T090000Z', '20250715T100000Z', 'Dispatch');
        $r   = $this->server->handleRequest('PUT', 'dispatch-uid', $ics);

        self::assertSame(201, $r->statusCode);
    }

    public function testHandleRequestDispatchesDelete(): void
    {
        $ics = $this->makeIcs('dispatch-del', '20250715T090000Z', '20250715T100000Z', 'Del');
        $this->server->handleRequest('PUT', 'dispatch-del', $ics);
        $r = $this->server->handleRequest('DELETE', 'dispatch-del', '');

        self::assertSame(204, $r->statusCode);
    }

    public function testHandleRequestReturns405ForUnknownMethod(): void
    {
        $r = $this->server->handleRequest('PATCH', 'uid', '');

        self::assertSame(405, $r->statusCode);
    }

    public function testHandleRequestPassesDepthHeaderToPropfind(): void
    {
        $r = $this->server->handleRequest('PROPFIND', '', '', ['Depth' => '1']);

        self::assertSame(207, $r->statusCode);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeIcs(string $uid, string $start, string $end, string $summary): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTART:' . $start,
            'DTEND:' . $end,
            'SUMMARY:' . $summary,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function makeCalendarQueryXml(string $start, string $end): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<calendar-query xmlns="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/><calendar-data/></D:prop>',
            '  <filter>',
            '    <comp-filter name="VCALENDAR">',
            '      <comp-filter name="VEVENT">',
            '        <time-range start="' . $start . '" end="' . $end . '"/>',
            '      </comp-filter>',
            '    </comp-filter>',
            '  </filter>',
            '</calendar-query>',
        ]);
    }
}

/**
 * In-memory CalendarEventStoreInterface for tests only.
 */
final class InMemoryEventStore implements CalendarEventStoreInterface
{
    /** @var array<string, ICalEvent> */
    private array $events = [];

    public function putEvent(string $uid, ICalEvent $event): void
    {
        $this->events[$uid] = $event;
    }

    public function deleteEvent(string $uid): void
    {
        unset($this->events[$uid]);
    }

    public function getEvent(string $uid): ?ICalEvent
    {
        return $this->events[$uid] ?? null;
    }

    /** @return list<ICalEvent> */
    public function listEvents(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        return array_values(array_filter(
            $this->events,
            fn (ICalEvent $e) => ($to === null || $e->dtStart <= $to)
                && ($from === null || $e->dtEnd === null || $e->dtEnd >= $from),
        ));
    }
}
