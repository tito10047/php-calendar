<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Server\CalDavServer;

/**
 * K6 — calendar-query filters are read from the XML tree, not guessed with a
 * regular expression — plus calendar-multiget, which is how Evolution fetches
 * the events it found changed.
 */
final class CalDavReportTest extends TestCase
{
    use DavXmlHelpers;

    private InMemoryEventStore $store;
    private CalDavServer $server;

    protected function setUp(): void
    {
        $this->store  = new InMemoryEventStore();
        $this->server = new CalDavServer($this->store, 'Test Calendar', '/caldav/');
    }

    // -------------------------------------------------------------------------
    // calendar-query
    // -------------------------------------------------------------------------

    public function testTimeRangeComesFromTheFilterTree(): void
    {
        $this->server->handlePut('september', $this->ics('september', '20260925'));
        $this->server->handlePut('march', $this->ics('march', '20260315'));

        $r = $this->server->handleReport($this->calendarQuery('20260901T000000Z', '20261001T000000Z'));

        self::assertStringContainsString('september.ics', $r->body);
        self::assertStringNotContainsString('march.ics', $r->body);
    }

    public function testAnAttributeOutsideTheFilterIsNotMistakenForATimeRange(): void
    {
        $this->server->handlePut('september', $this->ics('september', '20260925'));

        // A text-match that happens to contain start="…" must not move the window.
        $body = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/></D:prop>',
            '  <C:filter>',
            '    <C:comp-filter name="VCALENDAR">',
            '      <C:comp-filter name="VEVENT">',
            '        <C:time-range start="20260901T000000Z" end="20261001T000000Z"/>',
            '        <C:prop-filter name="SUMMARY">',
            '          <C:text-match>start="19700101T000000Z"</C:text-match>',
            '        </C:prop-filter>',
            '      </C:comp-filter>',
            '    </C:comp-filter>',
            '  </C:filter>',
            '</C:calendar-query>',
        ]);

        $r = $this->server->handleReport($body);

        self::assertStringContainsString('september.ics', $r->body);
    }

    public function testCalendarDataIsReturnedOnlyWhenAskedFor(): void
    {
        $this->server->handlePut('september', $this->ics('september', '20260925'));

        $withData = $this->xpath($this->server->handleReport($this->calendarQuery('20260901T000000Z', '20261001T000000Z'))->body);
        self::assertStringContainsString('BEGIN:VEVENT', $withData->evaluate('string(//C:calendar-data)'));

        $etagOnly = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/></D:prop>',
            '  <C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT"/></C:comp-filter></C:filter>',
            '</C:calendar-query>',
        ]);

        $withoutData = $this->xpath($this->server->handleReport($etagOnly)->body);
        self::assertSame(0, $this->nodeCount($withoutData, '//C:calendar-data'));
        self::assertSame(1, $this->nodeCount($withoutData, '//D:getetag'));
    }

    public function testCompFilterForVtodoMatchesNothing(): void
    {
        $this->server->handlePut('september', $this->ics('september', '20260925'));

        $body = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/></D:prop>',
            '  <C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VTODO"/></C:comp-filter></C:filter>',
            '</C:calendar-query>',
        ]);

        $xpath = $this->xpath($this->server->handleReport($body)->body);

        self::assertSame(0, $this->nodeCount($xpath, '//D:response'), 'This server stores no VTODOs');
    }

    // -------------------------------------------------------------------------
    // calendar-multiget
    // -------------------------------------------------------------------------

    public function testMultigetReturnsExactlyTheRequestedEvents(): void
    {
        $this->server->handlePut('one', $this->ics('one', '20260925'));
        $this->server->handlePut('two', $this->ics('two', '20260926'));
        $this->server->handlePut('three', $this->ics('three', '20260927'));

        $body = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-multiget xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/><C:calendar-data/></D:prop>',
            '  <D:href>/caldav/one.ics</D:href>',
            '  <D:href>/caldav/three.ics</D:href>',
            '</C:calendar-multiget>',
        ]);

        $r     = $this->server->handleReport($body);
        $xpath = $this->xpath($r->body);

        self::assertSame(207, $r->statusCode);
        self::assertSame(2, $this->nodeCount($xpath, '//D:response'));
        self::assertStringContainsString('one.ics', $r->body);
        self::assertStringContainsString('three.ics', $r->body);
        self::assertStringNotContainsString('two.ics', $r->body);
    }

    public function testMultigetReportsAMissingHrefAsNotFound(): void
    {
        $this->server->handlePut('one', $this->ics('one', '20260925'));

        $body = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-multiget xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/></D:prop>',
            '  <D:href>/caldav/one.ics</D:href>',
            '  <D:href>/caldav/ghost.ics</D:href>',
            '</C:calendar-multiget>',
        ]);

        $xpath = $this->xpath($this->server->handleReport($body)->body);

        self::assertSame(
            1,
            $this->nodeCount($xpath, '//D:response[D:href[contains(., "ghost.ics")]][D:status[contains(., "404")]]'),
        );
    }

    public function testMultigetEtagMatchesGet(): void
    {
        $this->server->handlePut('one', $this->ics('one', '20260925'));

        $body = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-multiget xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/></D:prop>',
            '  <D:href>/caldav/one.ics</D:href>',
            '</C:calendar-multiget>',
        ]);

        $xpath = $this->xpath($this->server->handleReport($body)->body);

        self::assertSame($this->server->handleGet('one')->headers['ETag'], $xpath->evaluate('string(//D:getetag)'));
    }

    public function testUnknownReportIsRejected(): void
    {
        $r = $this->server->handleReport('<D:principal-search-property-set xmlns:D="DAV:"/>');

        self::assertSame(403, $r->statusCode);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ics(string $uid, string $date): string
    {
        $next = (new \DateTimeImmutable($date))->modify('+1 day')->format('Ymd');

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTART;VALUE=DATE:' . $date,
            'DTEND;VALUE=DATE:' . $next,
            'SUMMARY:Event ' . $uid,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function calendarQuery(string $start, string $end): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/><C:calendar-data/></D:prop>',
            '  <C:filter>',
            '    <C:comp-filter name="VCALENDAR">',
            '      <C:comp-filter name="VEVENT">',
            '        <C:time-range start="' . $start . '" end="' . $end . '"/>',
            '      </C:comp-filter>',
            '    </C:comp-filter>',
            '  </C:filter>',
            '</C:calendar-query>',
        ]);
    }

}
