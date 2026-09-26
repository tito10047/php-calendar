<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Server\CalDavRouter;
use Tito10047\Calendar\Server\PropfindRequest;
use Tito10047\Calendar\Server\ReportRequest;
use Tito10047\Calendar\Xml\SafeXml;

/**
 * A DAV request body arrives from the network, and the parser it goes into used
 * to run with LIBXML_NOENT — the flag whose name reads as "no entities" and
 * whose effect is the opposite. A REPORT carrying
 * `<!ENTITY e SYSTEM "file:///etc/passwd">` came back with the file's contents
 * inside the 207.
 *
 * These are the regression tests for that. Every one of them states the same
 * rule from a different door: a body with a DOCTYPE is not parsed at all, and
 * no file ever reaches the tree.
 */
final class SafeXmlTest extends TestCase
{
    private const PAYLOAD = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE D:calendar-multiget [<!ENTITY leak SYSTEM "file:///etc/passwd">]>
        <C:calendar-multiget xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">
          <D:prop><D:getetag/></D:prop>
          <D:href>&leak;</D:href>
        </C:calendar-multiget>
        XML;

    public function testABodyWithADoctypeIsRefused(): void
    {
        self::assertNull(SafeXml::parse(self::PAYLOAD));
    }

    public function testAnOrdinaryBodyStillParses(): void
    {
        $doc = SafeXml::parse('<?xml version="1.0"?><D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>');

        self::assertNotNull($doc);
        self::assertSame('propfind', $doc->documentElement?->localName);
    }

    public function testAnEmptyOrMalformedBodyIsNull(): void
    {
        self::assertNull(SafeXml::parse(''));
        self::assertNull(SafeXml::parse('   '));
        self::assertNull(SafeXml::parse('<D:propfind xmlns:D="DAV:">'));
    }

    public function testAReportWithAnExternalEntityLeaksNothing(): void
    {
        $report = ReportRequest::fromXml(self::PAYLOAD);

        self::assertSame(ReportRequest::TYPE_UNSUPPORTED, $report->type);
        self::assertSame([], $report->hrefs);
    }

    public function testAPropfindWithAnExternalEntityLeaksNothing(): void
    {
        $propfind = PropfindRequest::fromXml(self::PAYLOAD);

        // Unparseable falls back to allprop, which asks for nothing of ours.
        self::assertSame(PropfindRequest::MODE_ALLPROP, $propfind->mode);
        self::assertSame([], $propfind->properties);
    }

    /**
     * End to end, the way the app reaches it: through the router, over a real
     * collection, with the payload as the request body.
     */
    public function testTheRouterNeverEchoesAFileBack(): void
    {
        $router = new CalDavRouter(
            new InMemoryCalendarHome('jana', 'Jana', [
                new InMemoryCollection('walk', 'Ranná prechádzka', 'ctag-1'),
            ]),
            '/caldav/',
        );

        $response = $router->handle(
            method: 'REPORT',
            path: '/caldav/calendars/jana/walk/',
            body: self::PAYLOAD,
            headers: [],
        );

        self::assertStringNotContainsString('root:', $response->body);
        self::assertStringNotContainsString('/bin/', $response->body);
    }
}
