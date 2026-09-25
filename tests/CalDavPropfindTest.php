<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Server\CalDavServer;

/**
 * K2 — PROPFIND answers the properties the client asked for, respects Depth,
 * and says 404 about the ones it does not know instead of staying silent.
 */
final class CalDavPropfindTest extends TestCase
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
    // Requested properties
    // -------------------------------------------------------------------------

    public function testOnlyRequestedPropertiesAreReturned(): void
    {
        $r = $this->server->handlePropfind($this->propfindBody(['D:displayname']), 0);

        $xpath = $this->xpath($r->body);
        self::assertSame(1, $this->nodeCount($xpath, '//D:propstat[D:status[contains(., "200")]]/D:prop/D:displayname'));
        self::assertSame('Test Calendar', $xpath->evaluate('string(//D:displayname)'));
        self::assertSame(0, $this->nodeCount($xpath, '//D:resourcetype'));
    }

    public function testUnknownPropertyIsReportedAsNotFound(): void
    {
        $body = $this->propfindBody(['D:displayname', 'D:quota-used-bytes']);

        $xpath = $this->xpath($this->server->handlePropfind($body, 0)->body);

        self::assertSame(
            1,
            $this->nodeCount($xpath, '//D:propstat[D:status[contains(., "404")]]/D:prop/D:quota-used-bytes'),
            'Unsupported property must come back in a 404 propstat',
        );
        self::assertSame(
            1,
            $this->nodeCount($xpath, '//D:propstat[D:status[contains(., "200")]]/D:prop/D:displayname'),
        );
    }

    public function testResourcetypeIsACalendarCollection(): void
    {
        $xpath = $this->xpath($this->server->handlePropfind($this->propfindBody(['D:resourcetype']), 0)->body);

        self::assertSame(1, $this->nodeCount($xpath, '//D:resourcetype/D:collection'));
        self::assertSame(1, $this->nodeCount($xpath, '//D:resourcetype/C:calendar'));
    }

    public function testSupportedComponentSetContainsVevent(): void
    {
        $body  = $this->propfindBody(['C:supported-calendar-component-set']);
        $xpath = $this->xpath($this->server->handlePropfind($body, 0)->body);

        self::assertSame('VEVENT', $xpath->evaluate('string(//C:supported-calendar-component-set/C:comp/@name)'));
    }

    public function testSupportedReportSetAdvertisesCalendarQueryAndMultiget(): void
    {
        $body  = $this->propfindBody(['D:supported-report-set']);
        $xpath = $this->xpath($this->server->handlePropfind($body, 0)->body);

        self::assertSame(1, $this->nodeCount($xpath, '//D:supported-report-set/D:supported-report/D:report/C:calendar-query'));
        self::assertSame(1, $this->nodeCount($xpath, '//D:supported-report-set/D:supported-report/D:report/C:calendar-multiget'));
    }

    public function testEmptyBodyIsTreatedAsAllprop(): void
    {
        $xpath = $this->xpath($this->server->handlePropfind('', 0)->body);

        self::assertSame(1, $this->nodeCount($xpath, '//D:displayname'));
        self::assertSame(1, $this->nodeCount($xpath, '//D:resourcetype'));
    }

    public function testPropnameReturnsNamesWithoutValues(): void
    {
        $body = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<D:propfind xmlns:D="DAV:"><D:propname/></D:propfind>',
        ]);

        $xpath = $this->xpath($this->server->handlePropfind($body, 0)->body);

        self::assertSame(1, $this->nodeCount($xpath, '//D:displayname'));
        self::assertSame('', $xpath->evaluate('string(//D:displayname)'));
    }

    // -------------------------------------------------------------------------
    // Depth
    // -------------------------------------------------------------------------

    public function testDepthZeroReturnsOnlyTheCollection(): void
    {
        $this->server->handlePut('one', $this->ics('one'));

        $xpath = $this->xpath($this->server->handlePropfind($this->propfindBody(['D:getetag']), 0)->body);

        self::assertSame(1, $this->nodeCount($xpath, '//D:response'));
        self::assertSame('/caldav/', $xpath->evaluate('string(//D:response/D:href)'));
    }

    public function testDepthOneReturnsOneResponsePerEvent(): void
    {
        $this->server->handlePut('one', $this->ics('one'));
        $this->server->handlePut('two', $this->ics('two'));

        $body  = $this->propfindBody(['D:getetag', 'D:getcontenttype']);
        $xpath = $this->xpath($this->server->handlePropfind($body, 1)->body);

        self::assertSame(3, $this->nodeCount($xpath, '//D:response'), 'collection + one response per event');

        $hrefs = $this->nodeTexts($xpath, '//D:response/D:href');
        self::assertContains('/caldav/one.ics', $hrefs);
        self::assertContains('/caldav/two.ics', $hrefs);

        self::assertSame(
            2,
            $this->nodeCount($xpath, '//D:response[D:href[contains(., ".ics")]]/D:propstat/D:prop/D:getetag'),
        );
        self::assertStringContainsString(
            'text/calendar',
            $xpath->evaluate('string(//D:response[D:href[contains(., "one.ics")]]//D:getcontenttype)'),
        );
    }

    public function testCollectionItselfHasNoGetetag(): void
    {
        $this->server->handlePut('one', $this->ics('one'));

        $xpath = $this->xpath($this->server->handlePropfind($this->propfindBody(['D:getetag']), 1)->body);

        self::assertSame(
            1,
            $this->nodeCount($xpath, '//D:response[D:href="/caldav/"]/D:propstat[D:status[contains(., "404")]]/D:prop/D:getetag'),
            'A collection has no entity tag of its own',
        );
    }

    public function testEventEtagInPropfindMatchesTheEtagFromGet(): void
    {
        $this->server->handlePut('one', $this->ics('one'));

        $xpath = $this->xpath($this->server->handlePropfind($this->propfindBody(['D:getetag']), 1)->body);
        $fromPropfind = $xpath->evaluate('string(//D:response[D:href[contains(., "one.ics")]]//D:getetag)');

        self::assertSame($this->server->handleGet('one')->headers['ETag'], $fromPropfind);
    }

    public function testResponseIsAMultistatus(): void
    {
        $r = $this->server->handlePropfind('', 0);

        self::assertSame(207, $r->statusCode);
        self::assertStringContainsString('xml', $r->contentType);
        self::assertSame('1, 2, 3, calendar-access', $r->headers['DAV']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $props
     */
    private function propfindBody(array $props): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">',
            '  <D:prop>',
        ];
        foreach ($props as $prop) {
            $lines[] = '    <' . $prop . '/>';
        }
        $lines[] = '  </D:prop>';
        $lines[] = '</D:propfind>';

        return implode("\n", $lines);
    }

    private function ics(string $uid): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTART;VALUE=DATE:20260925',
            'DTEND;VALUE=DATE:20260926',
            'SUMMARY:Event ' . $uid,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }
}
