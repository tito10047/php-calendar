<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Server\CalDavServer;

/**
 * K3 — one source of truth for the entity tag, and preconditions that stop two
 * devices from silently overwriting each other.
 */
final class CalDavEtagTest extends TestCase
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
    // One event, one ETag
    // -------------------------------------------------------------------------

    public function testPutAndGetAgreeOnTheEtag(): void
    {
        $put = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'));
        $get = $this->server->handleGet('uid-1');

        self::assertSame($put->headers['ETag'], $get->headers['ETag']);
    }

    public function testEtagIsStableAcrossRepeatedReads(): void
    {
        $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'));

        $first  = $this->server->handleGet('uid-1')->headers['ETag'];
        $second = $this->server->handleGet('uid-1')->headers['ETag'];

        self::assertSame($first, $second, 'The same stored event must always hash the same');
    }

    public function testReportAndGetAgreeOnTheEtag(): void
    {
        $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'));

        $report = $this->server->handleReport($this->calendarQuery('20260901T000000Z', '20261001T000000Z'));
        $xpath  = $this->xpath($report->body);

        self::assertSame(
            $this->server->handleGet('uid-1')->headers['ETag'],
            $xpath->evaluate('string(//D:getetag)'),
        );
    }

    public function testEtagChangesWhenTheEventChanges(): void
    {
        $before = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'))->headers['ETag'];
        $after  = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Run'))->headers['ETag'];

        self::assertNotSame($before, $after);
    }

    // -------------------------------------------------------------------------
    // If-Match / If-None-Match
    // -------------------------------------------------------------------------

    public function testStaleIfMatchOnPutIsRejectedAndChangesNothing(): void
    {
        $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'));

        $r = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Run'), ['If-Match' => '"stale"']);

        self::assertSame(412, $r->statusCode);
        self::assertSame('Walk', $this->store->getEvent('uid-1')?->summary);
    }

    public function testMatchingIfMatchOnPutIsAccepted(): void
    {
        $etag = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'))->headers['ETag'];

        $r = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Run'), ['If-Match' => $etag]);

        self::assertSame(204, $r->statusCode);
        self::assertSame('Run', $this->store->getEvent('uid-1')?->summary);
    }

    public function testIfMatchOnAMissingEventIsRejected(): void
    {
        $r = $this->server->handlePut('ghost', $this->ics('ghost', 'Gone'), ['If-Match' => '"anything"']);

        self::assertSame(412, $r->statusCode);
        self::assertNull($this->store->getEvent('ghost'));
    }

    public function testIfMatchStarRequiresAnExistingEvent(): void
    {
        $r = $this->server->handlePut('ghost', $this->ics('ghost', 'Gone'), ['If-Match' => '*']);

        self::assertSame(412, $r->statusCode);
    }

    public function testIfNoneMatchStarRefusesToOverwrite(): void
    {
        $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'));

        $r = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Run'), ['If-None-Match' => '*']);

        self::assertSame(412, $r->statusCode);
        self::assertSame('Walk', $this->store->getEvent('uid-1')?->summary);
    }

    public function testIfNoneMatchStarAllowsACreate(): void
    {
        $r = $this->server->handlePut('fresh', $this->ics('fresh', 'New'), ['If-None-Match' => '*']);

        self::assertSame(201, $r->statusCode);
    }

    public function testStaleIfMatchOnDeleteKeepsTheEvent(): void
    {
        $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'));

        $r = $this->server->handleDelete('uid-1', ['If-Match' => '"stale"']);

        self::assertSame(412, $r->statusCode);
        self::assertNotNull($this->store->getEvent('uid-1'));
    }

    public function testMatchingIfMatchOnDeleteRemovesTheEvent(): void
    {
        $etag = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'))->headers['ETag'];

        $r = $this->server->handleDelete('uid-1', ['If-Match' => $etag]);

        self::assertSame(204, $r->statusCode);
        self::assertNull($this->store->getEvent('uid-1'));
    }

    public function testGetWithCurrentEtagIsNotModified(): void
    {
        $etag = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'))->headers['ETag'];

        $r = $this->server->handleGet('uid-1', ['If-None-Match' => $etag]);

        self::assertSame(304, $r->statusCode);
        self::assertSame('', $r->body);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $this->server->handlePut('uid-1', $this->ics('uid-1', 'Walk'));

        $r = $this->server->handlePut('uid-1', $this->ics('uid-1', 'Run'), ['if-match' => '"stale"']);

        self::assertSame(412, $r->statusCode);
    }

    public function testPreconditionsGoThroughTheDispatcherToo(): void
    {
        $this->server->handleRequest('PUT', 'uid-1', $this->ics('uid-1', 'Walk'));

        $r = $this->server->handleRequest('PUT', 'uid-1', $this->ics('uid-1', 'Run'), ['If-Match' => '"stale"']);

        self::assertSame(412, $r->statusCode);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ics(string $uid, string $summary): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTART;VALUE=DATE:20260925',
            'DTEND;VALUE=DATE:20260926',
            'SUMMARY:' . $summary,
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
