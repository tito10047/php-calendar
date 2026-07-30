<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;

/**
 * Minimal CalDAV client for fetching events from a CalDAV server.
 *
 * Supports Basic authentication and REPORT-based date-range queries
 * (RFC 4791 §7.8). Compatible with Nextcloud, Apple iCloud, Fastmail
 * and other CalDAV-compliant servers. Google Calendar requires an
 * app-specific password or OAuth token supplied as the password.
 *
 * Usage:
 *   $client = new CalDAVClient('https://cloud.example.com/remote.php/dav/calendars/user/personal/');
 *   $events = $client->authenticate('user', 'password')
 *                    ->fetchEvents(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));
 */
final class CalDAVClient
{
    private string $baseUrl;
    private ?string $username   = null;
    private ?string $password   = null;
    private int     $timeout    = 30;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
    }

    public function authenticate(string $username, string $password): self
    {
        $clone           = clone $this;
        $clone->username = $username;
        $clone->password = $password;
        return $clone;
    }

    public function withTimeout(int $seconds): self
    {
        $clone          = clone $this;
        $clone->timeout = $seconds;
        return $clone;
    }

    /**
     * Fetch all events in the given date range via a CalDAV calendar-query REPORT.
     *
     * @return list<ICalEvent>
     */
    public function fetchEvents(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $body = $this->buildReportBody($from, $to);
        $raw  = $this->request('REPORT', $this->baseUrl, $body, [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
        ]);

        return $this->parseMultiResponse($raw);
    }

    /**
     * List all calendar URLs under baseUrl via a PROPFIND request.
     *
     * @return list<string>
     */
    public function listCalendars(): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:resourcetype/><d:displayname/></d:prop>'
            . '</d:propfind>';

        $raw  = $this->request('PROPFIND', $this->baseUrl, $body, [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
        ]);

        return $this->extractHrefs($raw);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildReportBody(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $start = $from->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $end   = $to->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
            . '<c:filter>'
            . '<c:comp-filter name="VCALENDAR">'
            . '<c:comp-filter name="VEVENT">'
            . '<c:time-range start="' . $start . '" end="' . $end . '"/>'
            . '</c:comp-filter>'
            . '</c:comp-filter>'
            . '</c:filter>'
            . '</c:calendar-query>';
    }

    /**
     * Execute an HTTP request with the configured credentials.
     *
     * @param  list<string> $extraHeaders
     */
    private function request(string $method, string $url, string $body, array $extraHeaders = []): string
    {
        $headers = array_merge([
            'User-Agent: php-calendar/2.0 CalDAVClient',
        ], $extraHeaders);

        $opts = [
            'http' => [
                'method'  => $method,
                'content' => $body,
                'header'  => implode("\r\n", $headers),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true],
        ];

        if ($this->username !== null && $this->password !== null) {
            $opts['http']['header'] .= "\r\nAuthorization: Basic " . base64_encode($this->username . ':' . $this->password);
        }

        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            throw new \RuntimeException("CalDAV request failed for URL: {$url}");
        }

        return $response;
    }

    /**
     * Extract iCal data from a multi-status XML response and parse events.
     *
     * @return list<ICalEvent>
     */
    private function parseMultiResponse(string $xmlBody): array
    {
        if (trim($xmlBody) === '') {
            return [];
        }

        $prev   = libxml_use_internal_errors(true);
        $dom    = new \DOMDocument();
        $loaded = $dom->loadXML($xmlBody);
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return [];
        }

        $parser = new ICalParser();
        $events = [];

        /** @var \DOMNodeList<\DOMElement> $nodes */
        $nodes = $dom->getElementsByTagNameNS('urn:ietf:params:xml:ns:caldav', 'calendar-data');
        foreach ($nodes as $node) {
            $ics = $node->textContent;
            if (trim($ics) === '') {
                continue;
            }
            try {
                foreach ($parser->parseString($ics) as $event) {
                    $events[] = $event;
                }
            } catch (\Exception) {
                // Skip malformed iCal blocks
            }
        }

        return $events;
    }

    /**
     * Extract <d:href> values from a PROPFIND response.
     *
     * @return list<string>
     */
    private function extractHrefs(string $xmlBody): array
    {
        if (trim($xmlBody) === '') {
            return [];
        }

        $prev   = libxml_use_internal_errors(true);
        $dom    = new \DOMDocument();
        $loaded = $dom->loadXML($xmlBody);
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return [];
        }

        $hrefs = [];

        /** @var \DOMNodeList<\DOMElement> $nodes */
        $nodes = $dom->getElementsByTagNameNS('DAV:', 'href');
        foreach ($nodes as $node) {
            $href = trim($node->textContent);
            if ($href !== '') {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }
}
