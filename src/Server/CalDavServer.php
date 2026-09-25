<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use DOMDocument;
use DOMElement;
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
 * Two rules the implementation is built around:
 *
 *   - **One entity tag per event.** The ETag is always the hash of the event as
 *     it is stored, never of the bytes a client happened to send. PUT, GET,
 *     PROPFIND and REPORT therefore agree, and a client's cache can settle.
 *   - **Preconditions are honoured.** If-Match and If-None-Match decide whether
 *     a write happens, so two devices editing the same day cannot silently
 *     overwrite each other.
 *
 * Usage – Symfony:
 *   $r = (new CalDavServer($store))->handlePut($uid, $request->getContent(), $request->headers->all());
 *   return new Response($r->body, $r->statusCode, ['Content-Type' => $r->contentType] + $r->headers);
 *
 * Usage – plain PHP:
 *   $r = (new CalDavServer($store))->handleRequest($_SERVER['REQUEST_METHOD'], $uid, file_get_contents('php://input'), getallheaders());
 *   http_response_code($r->statusCode);
 *   echo $r->body;
 */
final class CalDavServer
{
    private const CONTENT_TYPE_EVENT = 'text/calendar; charset=utf-8; component=vevent';

    public function __construct(
        private readonly CalendarEventStoreInterface $store,
        private readonly string $calendarName = 'Calendar',
        private readonly string $baseUrl = '/caldav/',
        private readonly ?string $calendarDescription = null,
    ) {
    }

    // -------------------------------------------------------------------------
    // Convenience dispatcher
    // -------------------------------------------------------------------------

    /**
     * Dispatch a raw HTTP request to the appropriate handler.
     *
     * @param array<string, string|list<string>> $headers Request headers, looked up case-insensitively.
     */
    public function handleRequest(
        string $method,
        string $uid,
        string $body,
        array $headers = [],
    ): CalDavResponse {
        $depth = $this->header($headers, 'Depth');

        return match (strtoupper($method)) {
            'OPTIONS' => $this->handleOptions(),
            'PROPFIND' => $this->handlePropfind($body, $depth === 'infinity' ? 1 : (int) $depth),
            'REPORT' => $this->handleReport($body),
            'GET', 'HEAD' => $this->handleGet($uid, $headers),
            'PUT' => $this->handlePut($uid, $body, $headers),
            'DELETE' => $this->handleDelete($uid, $headers),
            default => new CalDavResponse(405, '', 'text/plain', [
                'Allow' => Dav::ALLOWED_METHODS,
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
                'Allow' => Dav::ALLOWED_METHODS,
                'DAV' => Dav::COMPLIANCE,
                'Content-Length' => '0',
            ],
        );
    }

    /**
     * Handle HTTP PROPFIND — answer exactly the properties the client asked for.
     *
     * Depth 0 describes the collection, Depth 1 adds one response per event with
     * its entity tag, which is how a client learns what changed.
     */
    public function handlePropfind(string $body, int $depth): CalDavResponse
    {
        $request = PropfindRequest::fromXml($body);
        $builder = new MultiStatusBuilder();

        $builder->addResponse(
            $this->collectionHref(),
            $this->selectProperties($request, $this->collectionProperties()),
        );

        if ($depth >= 1) {
            foreach ($this->store->listEvents() as $event) {
                $builder->addResponse(
                    $this->eventHref($event->uid),
                    $this->selectProperties(
                        $request,
                        $this->eventProperties($event),
                        [$this->key(Dav::NS_CALDAV, 'calendar-data')],
                    ),
                );
            }
        }

        return new CalDavResponse(
            statusCode: 207,
            body: $builder->toXml(),
            headers: ['DAV' => Dav::COMPLIANCE],
        );
    }

    /**
     * Handle HTTP REPORT — calendar-query and calendar-multiget.
     */
    public function handleReport(string $xmlBody): CalDavResponse
    {
        $request = ReportRequest::fromXml($xmlBody);

        return match ($request->type) {
            ReportRequest::TYPE_CALENDAR_QUERY => $this->reportCalendarQuery($request),
            ReportRequest::TYPE_CALENDAR_MULTIGET => $this->reportMultiget($request),
            default => new CalDavResponse(
                statusCode: 403,
                body: $this->errorXml(Dav::NS_DAV, 'supported-report'),
            ),
        };
    }

    /**
     * Handle HTTP GET — serve a single event as a .ics file.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function handleGet(string $uid, array $headers = []): CalDavResponse
    {
        $event = $this->store->getEvent($uid);
        if ($event === null) {
            return new CalDavResponse(404, 'Not Found', 'text/plain');
        }

        $etag = $this->etagFor($event);

        if ($this->etagMatches($this->header($headers, 'If-None-Match'), $etag)) {
            return new CalDavResponse(304, '', 'text/plain', ['ETag' => $etag]);
        }

        $ics = $this->exportSingleEvent($event);

        return new CalDavResponse(
            statusCode: 200,
            body: $ics,
            contentType: 'text/calendar; charset=utf-8',
            headers: [
                'ETag' => $etag,
                'Content-Length' => (string) strlen($ics),
            ],
        );
    }

    /**
     * Handle HTTP PUT — create or update an event from an iCal body.
     * Returns 201 Created for new events, 204 No Content for updates,
     * 412 Precondition Failed when If-Match / If-None-Match says no.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function handlePut(string $uid, string $icsBody, array $headers = []): CalDavResponse
    {
        $current = $this->store->getEvent($uid);

        $failed = $this->checkPreconditions($headers, $current);
        if ($failed !== null) {
            return $failed;
        }

        $events = (new ICalParser())->parseString($this->wrapInVcalendar($icsBody));
        if ($events === []) {
            return new CalDavResponse(400, 'No VEVENT found in request body', 'text/plain');
        }

        $this->store->putEvent($uid, $events[0]);

        // The ETag describes what is stored, not what arrived: the store may
        // normalise the event, and a later GET has to hash to the same string.
        $stored = $this->store->getEvent($uid) ?? $events[0];

        return new CalDavResponse(
            statusCode: $current === null ? 201 : 204,
            body: '',
            headers: ['ETag' => $this->etagFor($stored)],
        );
    }

    /**
     * Handle HTTP DELETE — remove an event.
     * Returns 204 No Content on success, 404 if the event does not exist,
     * 412 when If-Match does not agree with what is stored.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function handleDelete(string $uid, array $headers = []): CalDavResponse
    {
        $current = $this->store->getEvent($uid);
        if ($current === null) {
            return new CalDavResponse(404, 'Not Found', 'text/plain');
        }

        $failed = $this->checkPreconditions($headers, $current);
        if ($failed !== null) {
            return $failed;
        }

        $this->store->deleteEvent($uid);

        return new CalDavResponse(204, '');
    }

    /**
     * The entity tag of a stored event — the single place it is computed.
     */
    public function etagFor(ICalEvent $event): string
    {
        $ics = $this->exportSingleEvent($event);

        if ($event->dtStamp === null) {
            // The exporter stamps "now" on an event that carries no DTSTAMP;
            // hashing that would change the ETag every second for an event
            // nobody touched.
            $ics = preg_replace('/^DTSTAMP:.*\R?/m', '', $ics) ?? $ics;
        }

        return '"' . md5($ics) . '"';
    }

    // -------------------------------------------------------------------------
    // REPORT implementations
    // -------------------------------------------------------------------------

    private function reportCalendarQuery(ReportRequest $request): CalDavResponse
    {
        $builder = new MultiStatusBuilder();

        // This server stores VEVENTs only; a query for anything else matches nothing.
        if ($request->componentName !== null && $request->componentName !== 'VEVENT') {
            return new CalDavResponse(207, $builder->toXml());
        }

        $events = $this->store->listEvents($request->from, $request->to);

        foreach ($events as $event) {
            $builder->addResponse(
                $this->eventHref($event->uid),
                $this->reportProperties($request, $event),
            );
        }

        return new CalDavResponse(207, $builder->toXml());
    }

    private function reportMultiget(ReportRequest $request): CalDavResponse
    {
        $builder = new MultiStatusBuilder();

        foreach ($request->hrefs as $href) {
            $event = $this->store->getEvent($this->uidFromHref($href));

            if ($event === null) {
                $builder->addStatusResponse($href, 'HTTP/1.1 404 Not Found');
                continue;
            }

            $builder->addResponse($href, $this->reportProperties($request, $event));
        }

        return new CalDavResponse(207, $builder->toXml());
    }

    /**
     * Properties for one event in a REPORT response. A REPORT without an
     * explicit prop list is answered with the two every client wants.
     *
     * @return list<DavProperty>
     */
    private function reportProperties(ReportRequest $request, ICalEvent $event): array
    {
        $available = $this->eventProperties($event);

        if ($request->properties === []) {
            return [
                $available[$this->key(Dav::NS_DAV, 'getetag')],
                $available[$this->key(Dav::NS_CALDAV, 'calendar-data')],
            ];
        }

        $selected = [];
        foreach ($request->properties as $property) {
            $key = $this->key($property['namespace'], $property['name']);
            $selected[] = $available[$key] ?? DavProperty::notFound($property['namespace'], $property['name']);
        }

        return $selected;
    }

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /**
     * @param array<string, DavProperty> $available
     * @param list<string>               $excludedFromAllprop
     *
     * @return list<DavProperty>
     */
    private function selectProperties(PropfindRequest $request, array $available, array $excludedFromAllprop = []): array
    {
        if ($request->mode === PropfindRequest::MODE_ALLPROP || $request->mode === PropfindRequest::MODE_PROPNAME) {
            $properties = [];
            foreach ($available as $key => $property) {
                if (!$property->found || in_array($key, $excludedFromAllprop, true)) {
                    continue;
                }
                $properties[] = $request->mode === PropfindRequest::MODE_PROPNAME
                    ? $property->withoutValue()
                    : $property;
            }

            return $properties;
        }

        $selected = [];
        foreach ($request->properties as $property) {
            $key = $this->key($property['namespace'], $property['name']);
            $selected[] = $available[$key] ?? DavProperty::notFound($property['namespace'], $property['name']);
        }

        return $selected;
    }

    /**
     * @return array<string, DavProperty>
     */
    private function collectionProperties(): array
    {
        $properties = [
            $this->key(Dav::NS_DAV, 'resourcetype') => DavProperty::structured(
                Dav::NS_DAV,
                'resourcetype',
                function (DOMDocument $doc, DOMElement $element): void {
                    $element->appendChild($doc->createElementNS(Dav::NS_DAV, 'D:collection'));
                    $element->appendChild($doc->createElementNS(Dav::NS_CALDAV, 'C:calendar'));
                },
            ),
            $this->key(Dav::NS_DAV, 'displayname') => DavProperty::text(
                Dav::NS_DAV,
                'displayname',
                $this->calendarName,
            ),
            $this->key(Dav::NS_DAV, 'supported-report-set') => DavProperty::structured(
                Dav::NS_DAV,
                'supported-report-set',
                function (DOMDocument $doc, DOMElement $element): void {
                    foreach (['calendar-query', 'calendar-multiget'] as $reportName) {
                        $supported = $doc->createElementNS(Dav::NS_DAV, 'D:supported-report');
                        $report = $doc->createElementNS(Dav::NS_DAV, 'D:report');
                        $report->appendChild($doc->createElementNS(Dav::NS_CALDAV, 'C:' . $reportName));
                        $supported->appendChild($report);
                        $element->appendChild($supported);
                    }
                },
            ),
            $this->key(Dav::NS_CALDAV, 'supported-calendar-component-set') => DavProperty::structured(
                Dav::NS_CALDAV,
                'supported-calendar-component-set',
                function (DOMDocument $doc, DOMElement $element): void {
                    $comp = $doc->createElementNS(Dav::NS_CALDAV, 'C:comp');
                    $comp->setAttribute('name', 'VEVENT');
                    $element->appendChild($comp);
                },
            ),
            // A collection is not an entity, so it has no entity tag — saying so
            // is the point of the 404 propstat.
            $this->key(Dav::NS_DAV, 'getetag') => DavProperty::notFound(Dav::NS_DAV, 'getetag'),
            $this->key(Dav::NS_DAV, 'getcontenttype') => DavProperty::notFound(Dav::NS_DAV, 'getcontenttype'),
        ];

        if ($this->calendarDescription !== null) {
            $properties[$this->key(Dav::NS_CALDAV, 'calendar-description')] = DavProperty::text(
                Dav::NS_CALDAV,
                'calendar-description',
                $this->calendarDescription,
            );
        }

        return $properties;
    }

    /**
     * @return array<string, DavProperty>
     */
    private function eventProperties(ICalEvent $event): array
    {
        $ics = $this->exportSingleEvent($event);
        $lastModified = $event->lastModified ?? $event->dtStamp;

        return [
            $this->key(Dav::NS_DAV, 'getetag') => DavProperty::text(
                Dav::NS_DAV,
                'getetag',
                $this->etagFor($event),
            ),
            $this->key(Dav::NS_DAV, 'getcontenttype') => DavProperty::text(
                Dav::NS_DAV,
                'getcontenttype',
                self::CONTENT_TYPE_EVENT,
            ),
            $this->key(Dav::NS_DAV, 'getcontentlength') => DavProperty::text(
                Dav::NS_DAV,
                'getcontentlength',
                (string) strlen($ics),
            ),
            $this->key(Dav::NS_DAV, 'resourcetype') => DavProperty::emptyValue(Dav::NS_DAV, 'resourcetype'),
            $this->key(Dav::NS_DAV, 'getlastmodified') => $lastModified !== null
                ? DavProperty::text(
                    Dav::NS_DAV,
                    'getlastmodified',
                    $lastModified->setTimezone(new \DateTimeZone('GMT'))->format('D, d M Y H:i:s \G\M\T'),
                )
                : DavProperty::notFound(Dav::NS_DAV, 'getlastmodified'),
            $this->key(Dav::NS_CALDAV, 'calendar-data') => DavProperty::text(
                Dav::NS_CALDAV,
                'calendar-data',
                $ics,
            ),
        ];
    }

    // -------------------------------------------------------------------------
    // Preconditions
    // -------------------------------------------------------------------------

    /**
     * RFC 7232 §3.1–3.2 for the two headers CalDAV clients actually send.
     *
     * @param array<string, string|list<string>> $headers
     */
    private function checkPreconditions(array $headers, ?ICalEvent $current): ?CalDavResponse
    {
        $currentEtag = $current !== null ? $this->etagFor($current) : null;

        $ifNoneMatch = $this->header($headers, 'If-None-Match');
        if ($ifNoneMatch !== '') {
            if (trim($ifNoneMatch) === '*' && $current !== null) {
                return $this->preconditionFailed();
            }
            if ($currentEtag !== null && $this->etagMatches($ifNoneMatch, $currentEtag)) {
                return $this->preconditionFailed();
            }
        }

        $ifMatch = $this->header($headers, 'If-Match');
        if ($ifMatch !== '') {
            if ($current === null) {
                return $this->preconditionFailed();
            }
            if (trim($ifMatch) !== '*' && !$this->etagMatches($ifMatch, (string) $currentEtag)) {
                return $this->preconditionFailed();
            }
        }

        return null;
    }

    private function preconditionFailed(): CalDavResponse
    {
        return new CalDavResponse(412, 'Precondition Failed', 'text/plain');
    }

    /**
     * Does a comma-separated header value contain this entity tag?
     * Weak tags (W/"…") compare by their opaque part.
     */
    private function etagMatches(string $headerValue, string $etag): bool
    {
        if (trim($headerValue) === '') {
            return false;
        }

        $normalise = static fn (string $value): string => trim(str_replace(['W/', '"'], '', trim($value)));
        $wanted = $normalise($etag);

        foreach (explode(',', $headerValue) as $candidate) {
            if ($normalise($candidate) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }

            return is_array($value) ? (string) ($value[0] ?? '') : $value;
        }

        return '';
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

    private function collectionHref(): string
    {
        return rtrim($this->baseUrl, '/') . '/';
    }

    private function eventHref(string $uid): string
    {
        return $this->collectionHref() . rawurlencode($uid) . '.ics';
    }

    private function uidFromHref(string $href): string
    {
        $path = parse_url($href, PHP_URL_PATH);
        $name = basename(is_string($path) ? $path : $href);

        return rawurldecode(preg_replace('/\.ics$/i', '', $name) ?? $name);
    }

    private function key(string $namespace, string $name): string
    {
        return '{' . $namespace . '}' . $name;
    }

    private function errorXml(string $namespace, string $condition): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $error = $doc->createElementNS(Dav::NS_DAV, 'D:error');
        $error->appendChild($doc->createElementNS($namespace, ($namespace === Dav::NS_DAV ? 'D:' : 'C:') . $condition));
        $doc->appendChild($error);

        return (string) $doc->saveXML();
    }

    private function wrapInVcalendar(string $body): string
    {
        if (str_contains($body, 'BEGIN:VCALENDAR')) {
            return $body;
        }

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . $body . "\r\nEND:VCALENDAR";
    }
}
