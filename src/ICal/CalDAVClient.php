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
 * Security:
 *   - Only http:// and https:// base URLs are accepted; credentials are never sent over plain
 *     http:// unless allowInsecureHttp() is called explicitly.
 *   - Redirects are followed only within the same origin (scheme, host and port), so the
 *     Authorization header can never be forwarded to a different server.
 *   - Non-2xx responses (e.g. 401 for wrong credentials) throw instead of looking like "no events".
 *   - Responses are size-limited and XML is parsed without network access or DTDs.
 *
 * Usage:
 *   $client = new CalDAVClient('https://cloud.example.com/remote.php/dav/calendars/user/personal/');
 *   $events = $client->authenticate('user', 'password')
 *                    ->fetchEvents(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));
 *
 * A custom HTTP transport (e.g. a PSR-18 client adapter, or a stub in tests) can be supplied via
 * withTransport(): fn(string $method, string $url, string $body, list<string> $headers): array{status: int, body: string, location?: string|null}
 */
final class CalDAVClient
{
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_REDIRECTS    = 5;

    private string $baseUrl;
    private ?string $username     = null;
    private ?string $password     = null;
    private int     $timeout      = 30;
    /** @var positive-int */
    private int     $maxBytes     = self::DEFAULT_MAX_BYTES;
    private bool    $allowInsecure = false;

    /** @var (\Closure(string, string, string, list<string>): array{status: int, body: string, location?: string|null})|null */
    private ?\Closure $transport = null;

    public function __construct(string $baseUrl)
    {
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string) parse_url($baseUrl, PHP_URL_HOST) === '') {
            throw new \InvalidArgumentException('CalDAV base URL must be an absolute http:// or https:// URL');
        }
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
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Timeout must be at least 1 second');
        }
        $clone          = clone $this;
        $clone->timeout = $seconds;
        return $clone;
    }

    /** Maximum accepted response size in bytes (default 20 MiB). */
    public function withMaxResponseSize(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Maximum response size must be positive');
        }
        $clone           = clone $this;
        $clone->maxBytes = $bytes;
        return $clone;
    }

    /** Permit sending credentials over plain http:// (e.g. a local development server). */
    public function allowInsecureHttp(bool $allow = true): self
    {
        $clone                = clone $this;
        $clone->allowInsecure = $allow;
        return $clone;
    }

    /**
     * Replace the built-in stream-based HTTP transport.
     *
     * @param callable(string, string, string, list<string>): array{status: int, body: string, location?: string|null} $transport
     */
    public function withTransport(callable $transport): self
    {
        $clone            = clone $this;
        $clone->transport = \Closure::fromCallable($transport);
        return $clone;
    }

    /**
     * Fetch all events in the given date range via a CalDAV calendar-query REPORT.
     *
     * @return list<ICalEvent>
     * @throws \RuntimeException on transport errors, non-2xx responses or malformed XML
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
     * @throws \RuntimeException on transport errors, non-2xx responses or malformed XML
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
     * Execute an HTTP request with the configured credentials, following same-origin redirects.
     *
     * @param  list<string> $extraHeaders
     */
    private function request(string $method, string $url, string $body, array $extraHeaders = []): string
    {
        $headers = ['User-Agent: php-calendar CalDAVClient', ...$extraHeaders];

        if ($this->username !== null && $this->password !== null) {
            if (!$this->allowInsecure && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                throw new \RuntimeException('Refusing to send CalDAV credentials over plain http:// — use https:// or call allowInsecureHttp()');
            }
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $origin = self::origin($url);
        for ($redirects = 0; ; $redirects++) {
            $response = $this->transport !== null
                ? ($this->transport)($method, $url, $body, $headers)
                : $this->streamTransport($method, $url, $body, $headers);

            $status = $response['status'];
            if ($status >= 300 && $status < 400 && ($response['location'] ?? null) !== null) {
                $target = self::resolveUrl($url, (string) $response['location']);
                if (self::origin($target) !== $origin) {
                    throw new \RuntimeException("CalDAV server redirected to a different origin ({$status}); refusing to forward credentials. Configure the final URL instead.");
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Too many CalDAV redirects');
                }
                $url = $target;
                continue;
            }

            if ($status === 401 || $status === 403) {
                throw new \RuntimeException("CalDAV {$method} was rejected with HTTP {$status} — check the credentials and permissions");
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException("CalDAV {$method} failed with HTTP {$status}");
            }
            if (strlen($response['body']) > $this->maxBytes) {
                throw new \RuntimeException("CalDAV response exceeds the {$this->maxBytes} byte limit");
            }

            return $response['body'];
        }
    }

    /**
     * Built-in transport using PHP streams. Never follows redirects on its own.
     *
     * @param  list<string> $headers
     * @return array{status: int, body: string, location: string|null}
     */
    private function streamTransport(string $method, string $url, string $body, array $headers): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => $method,
                'content'         => $body,
                'header'          => implode("\r\n", $headers),
                'timeout'         => $this->timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $ctx, 0, $this->maxBytes + 1);
        if ($response === false) {
            throw new \RuntimeException('CalDAV request failed for URL: ' . self::redactUrl($url));
        }

        // $http_response_header is deprecated as of PHP 8.5; http_get_last_response_headers() exists since 8.4
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
        $status          = 0;
        $location        = null;
        foreach ($responseHeaders ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status   = (int) $m[1];
                $location = null;
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return ['status' => $status, 'body' => $response, 'location' => $location];
    }

    /**
     * Extract iCal data from a multi-status XML response and parse events.
     *
     * @return list<ICalEvent>
     */
    private function parseMultiResponse(string $xmlBody): array
    {
        $dom = $this->loadXml($xmlBody);
        if ($dom === null) {
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
                // Skip malformed iCal blocks — one broken resource must not hide the others
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
        $dom = $this->loadXml($xmlBody);
        if ($dom === null) {
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

    /** Parse a multistatus body; null for an empty body, exception for malformed XML or a DTD. */
    private function loadXml(string $xmlBody): ?\DOMDocument
    {
        if (trim($xmlBody) === '') {
            return null;
        }

        $prev   = libxml_use_internal_errors(true);
        $dom    = new \DOMDocument();
        $loaded = $dom->loadXML($xmlBody, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            throw new \RuntimeException('CalDAV server returned malformed XML');
        }
        if ($dom->doctype !== null) {
            throw new \RuntimeException('CalDAV response must not contain a DTD');
        }

        return $dom;
    }

    private static function origin(string $url): string
    {
        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host'] ?? '') . ':' . $port;
    }

    private static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $parts  = parse_url($base);
        $prefix = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $prefix . $location;
        }
        $path = $parts['path'] ?? '/';
        return $prefix . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    private static function redactUrl(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1***@', $url);
    }
}
