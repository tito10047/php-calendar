<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use DateTimeImmutable;
use DateTimeZone;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\ICal\ICalExporter;
use Tito10047\Calendar\ICal\ICalParser;

/**
 * Minimal CalDAV server (RFC 4791) request handler.
 *
 * Understands the CalDAV HTTP methods (OPTIONS, PROPFIND, REPORT, GET, PUT, DELETE)
 * and delegates persistence to a CalendarEventStoreInterface implementation
 * provided by the application.
 *
 * Usage – Symfony:
 *   $r = (new CalDavServer($store))->handlePut($uid, $request->getContent());
 *   return new Response($r->body, $r->statusCode, ['Content-Type' => $r->contentType] + $r->headers);
 *
 * Usage – plain PHP:
 *   $r = (new CalDavServer($store))->handleRequest($_SERVER['REQUEST_METHOD'], $uid, file_get_contents('php://input'), getallheaders());
 *   http_response_code($r->statusCode);
 *   echo $r->body;
 */
final class CalDavServer
{
    public function __construct(
        private readonly CalendarEventStoreInterface $store,
        private readonly string $calendarName = 'Calendar',
        private readonly string $baseUrl = '/caldav/',
    ) {
    }

    // -------------------------------------------------------------------------
    // Convenience dispatcher
    // -------------------------------------------------------------------------

    /**
     * Dispatch a raw HTTP request to the appropriate handler.
     *
     * @param array<string, string> $headers  Request headers (case-insensitive lookup for 'Depth').
     */
    public function handleRequest(
        string $method,
        string $uid,
        string $body,
        array $headers = [],
    ): CalDavResponse {
        $depth = (int) ($headers['Depth'] ?? $headers['depth'] ?? $headers['DEPTH'] ?? 0);

        return match (strtoupper($method)) {
            'OPTIONS'  => $this->handleOptions(),
            'PROPFIND' => $this->handlePropfind($body, $depth),
            'REPORT'   => $this->handleReport($body),
            'GET'      => $this->handleGet($uid),
            'PUT'      => $this->handlePut($uid, $body),
            'DELETE'   => $this->handleDelete($uid),
            default    => new CalDavResponse(405, '', 'text/plain', [
                'Allow' => 'OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT',
            ]),
        };
    }

    // -------------------------------------------------------------------------
    // Per-method handlers
    // -------------------------------------------------------------------------

    /**
     * Handle HTTP OPTIONS — advertise supported methods and CalDAV compliance.
     */
    public function handleOptions(): CalDavResponse
    {
        return new CalDavResponse(
            statusCode: 200,
            body: '',
            contentType: 'text/plain',
            headers: [
                'Allow'          => 'OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT',
                'DAV'            => '1, 2, calendar-access',
                'Content-Length' => '0',
            ],
        );
    }

    /**
     * Handle HTTP PROPFIND — return calendar collection properties.
     */
    public function handlePropfind(string $body, int $depth): CalDavResponse
    {
        return new CalDavResponse(
            statusCode: 207,
            body: $this->buildPropfindXml(),
            headers: ['DAV' => '1, 2, calendar-access'],
        );
    }

    /**
     * Handle HTTP REPORT (calendar-query) — return events in the requested time range.
     */
    public function handleReport(string $xmlBody): CalDavResponse
    {
        [$from, $to] = $this->extractTimeRange($xmlBody);
        $events      = $this->store->listEvents($from, $to);

        return new CalDavResponse(
            statusCode: 207,
            body: $this->buildReportXml($events),
        );
    }

    /**
     * Handle HTTP GET — serve a single event as a .ics file.
     */
    public function handleGet(string $uid): CalDavResponse
    {
        $event = $this->store->getEvent($uid);
        if ($event === null) {
            return new CalDavResponse(404, 'Not Found', 'text/plain');
        }

        $ics = $this->exportSingleEvent($event);

        return new CalDavResponse(
            statusCode: 200,
            body: $ics,
            contentType: 'text/calendar; charset=utf-8',
            headers: [
                'ETag'           => '"' . md5($ics) . '"',
                'Content-Length' => (string) strlen($ics),
            ],
        );
    }

    /**
     * Handle HTTP PUT — create or update an event from an iCal body.
     * Returns 201 Created for new events, 204 No Content for updates.
     */
    public function handlePut(string $uid, string $icsBody): CalDavResponse
    {
        $normalised = $this->wrapInVcalendar($icsBody);
        $events     = (new ICalParser())->parseString($normalised);

        if ($events === []) {
            return new CalDavResponse(400, 'No VEVENT found in request body', 'text/plain');
        }

        $isNew = $this->store->getEvent($uid) === null;
        $this->store->putEvent($uid, $events[0]);

        return new CalDavResponse(
            statusCode: $isNew ? 201 : 204,
            body: '',
            headers: ['ETag' => '"' . md5($icsBody) . '"'],
        );
    }

    /**
     * Handle HTTP DELETE — remove an event.
     * Returns 204 No Content on success, 404 if the event does not exist.
     */
    public function handleDelete(string $uid): CalDavResponse
    {
        if ($this->store->getEvent($uid) === null) {
            return new CalDavResponse(404, 'Not Found', 'text/plain');
        }

        $this->store->deleteEvent($uid);

        return new CalDavResponse(204, '');
    }

    // -------------------------------------------------------------------------
    // XML builders
    // -------------------------------------------------------------------------

    private function buildPropfindXml(): string
    {
        $href = rtrim($this->baseUrl, '/') . '/';
        $name = $this->escapeXml($this->calendarName);

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<multistatus xmlns="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
            '  <response>',
            '    <href>' . $this->escapeXml($href) . '</href>',
            '    <propstat>',
            '      <prop>',
            '        <displayname>' . $name . '</displayname>',
            '        <resourcetype><collection/><cal:calendar/></resourcetype>',
            '        <cal:supported-calendar-component-set>',
            '          <cal:comp name="VEVENT"/>',
            '        </cal:supported-calendar-component-set>',
            '      </prop>',
            '      <status>HTTP/1.1 200 OK</status>',
            '    </propstat>',
            '  </response>',
            '</multistatus>',
        ]);
    }

    /**
     * @param list<ICalEvent> $events
     */
    private function buildReportXml(array $events): string
    {
        $base  = rtrim($this->baseUrl, '/') . '/';
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<multistatus xmlns="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
        ];

        foreach ($events as $event) {
            $ics  = $this->exportSingleEvent($event);
            $href = $base . $event->uid . '.ics';
            $etag = md5($ics);

            $lines[] = '  <response>';
            $lines[] = '    <href>' . $this->escapeXml($href) . '</href>';
            $lines[] = '    <propstat>';
            $lines[] = '      <prop>';
            $lines[] = '        <getetag>"' . $etag . '"</getetag>';
            $lines[] = '        <cal:calendar-data>' . $this->escapeXml($ics) . '</cal:calendar-data>';
            $lines[] = '      </prop>';
            $lines[] = '      <status>HTTP/1.1 200 OK</status>';
            $lines[] = '    </propstat>';
            $lines[] = '  </response>';
        }

        $lines[] = '</multistatus>';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function exportSingleEvent(ICalEvent $event): string
    {
        return (new ICalExporter())
            ->calendarName($this->calendarName)
            ->addICalEvent($event)
            ->export();
    }

    /**
     * Extract time-range boundaries from a REPORT calendar-query XML body.
     * Falls back to −6 months / +12 months when no time-range filter is present.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function extractTimeRange(string $xmlBody): array
    {
        $utc  = new DateTimeZone('UTC');
        $from = new DateTimeImmutable('-6 months', $utc);
        $to   = new DateTimeImmutable('+12 months', $utc);

        if (preg_match('/start="([^"]+)"/', $xmlBody, $m)) {
            $dt = $this->parseDateTimeValue($m[1]);
            if ($dt !== null) {
                $from = $dt;
            }
        }

        if (preg_match('/end="([^"]+)"/', $xmlBody, $m)) {
            $dt = $this->parseDateTimeValue($m[1]);
            if ($dt !== null) {
                $to = $dt;
            }
        }

        return [$from, $to];
    }

    private function parseDateTimeValue(string $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, $utc);
        if ($dt !== false) {
            return $dt;
        }

        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $utc);

        return $dt !== false ? $dt : null;
    }

    private function wrapInVcalendar(string $body): string
    {
        if (str_contains($body, 'BEGIN:VCALENDAR')) {
            return $body;
        }

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . $body . "\r\nEND:VCALENDAR";
    }

    private function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
