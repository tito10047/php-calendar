<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use DOMDocument;
use DOMElement;
use Tito10047\Calendar\Xml\SafeXml;

/**
 * The whole CalDAV tree of one account — discovery included.
 *
 * A client is given one address and finds everything else itself:
 *
 *   /caldav/                                    who am I        (current-user-principal)
 *   /caldav/principals/{principal}/             where are my calendars (calendar-home-set)
 *   /caldav/calendars/{principal}/              the list of them
 *   /caldav/calendars/{principal}/{calendar}/   one calendar     (PROPFIND, REPORT)
 *   /caldav/calendars/{principal}/{calendar}/{uid}.ics           (GET, PUT, DELETE)
 *
 * Apple Calendar and DAVx5 will not finish setting up an account without those
 * first two steps, and without them one account cannot offer more than one
 * calendar at all.
 *
 * Everything below a calendar is handed to CalDavServer, which is where the
 * protocol for a single collection already lives.
 */
final class CalDavRouter
{
    private readonly string $basePath;

    public function __construct(
        private readonly CalendarHomeInterface $home,
        string $basePath = '/caldav/',
    ) {
        $this->basePath = '/' . trim($basePath, '/') . '/';
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function handle(string $method, string $path, string $body = '', array $headers = []): CalDavResponse
    {
        $segments = $this->segmentsOf($path);
        $method = strtoupper($method);

        if ($segments === null) {
            return $this->notFound();
        }

        $depth = $this->depthOf($headers);

        // Apple Calendar tries to write the colour and the display name back
        // as part of setting an account up. The answer is "no", but it has to
        // be a WebDAV answer: a 405 aborts the whole setup, a 207 with a 403
        // for each property is read as "this calendar is not mine to rename".
        if ($method === 'PROPPATCH') {
            return $this->handleProppatch($body, $path);
        }

        return match (true) {
            $segments === [] => $this->handleRoot($method, $body),
            $segments[0] === 'principals' => $this->handlePrincipal($method, $segments, $body),
            $segments[0] === 'calendars' => $this->handleCalendars($method, $segments, $body, $headers, $depth),
            default => $this->notFound(),
        };
    }

    // -------------------------------------------------------------------------
    // Hrefs
    // -------------------------------------------------------------------------

    public function principalHref(): string
    {
        return $this->basePath . 'principals/' . rawurlencode($this->home->getPrincipalId()) . '/';
    }

    public function homeHref(): string
    {
        return $this->basePath . 'calendars/' . rawurlencode($this->home->getPrincipalId()) . '/';
    }

    public function collectionHref(CalendarCollectionInterface $collection): string
    {
        return $this->homeHref() . rawurlencode($collection->getId()) . '/';
    }

    // -------------------------------------------------------------------------
    // Resources
    // -------------------------------------------------------------------------

    private function handleRoot(string $method, string $body): CalDavResponse
    {
        if ($method === 'OPTIONS') {
            return $this->options();
        }

        if ($method !== 'PROPFIND') {
            return $this->methodNotAllowed();
        }

        $builder = new MultiStatusBuilder();
        $builder->addResponse(
            $this->basePath,
            $this->select(PropfindRequest::fromXml($body), [
                $this->key(Dav::NS_DAV, 'resourcetype') => $this->plainCollection(),
                $this->key(Dav::NS_DAV, 'current-user-principal') => $this->hrefProperty('current-user-principal', $this->principalHref()),
                $this->key(Dav::NS_DAV, 'principal-URL') => $this->hrefProperty('principal-URL', $this->principalHref()),
                $this->key(Dav::NS_CALDAV, 'calendar-home-set') => $this->hrefProperty('calendar-home-set', $this->homeHref(), Dav::NS_CALDAV),
                $this->key(Dav::NS_DAV, 'principal-collection-set') => $this->hrefProperty('principal-collection-set', $this->basePath . 'principals/'),
            ]),
        );

        return $this->multistatus($builder);
    }

    /**
     * @param list<string> $segments
     */
    private function handlePrincipal(string $method, array $segments, string $body): CalDavResponse
    {
        if (count($segments) !== 2 || $segments[1] !== $this->home->getPrincipalId()) {
            return $this->notFound();
        }

        if ($method === 'OPTIONS') {
            return $this->options();
        }

        if ($method !== 'PROPFIND') {
            return $this->methodNotAllowed();
        }

        $builder = new MultiStatusBuilder();
        $builder->addResponse(
            $this->principalHref(),
            $this->select(PropfindRequest::fromXml($body), [
                $this->key(Dav::NS_DAV, 'resourcetype') => DavProperty::structured(
                    Dav::NS_DAV,
                    'resourcetype',
                    static function (DOMDocument $doc, DOMElement $element): void {
                        $element->appendChild($doc->createElementNS(Dav::NS_DAV, 'D:collection'));
                        $element->appendChild($doc->createElementNS(Dav::NS_DAV, 'D:principal'));
                    },
                ),
                $this->key(Dav::NS_DAV, 'displayname') => DavProperty::text(Dav::NS_DAV, 'displayname', $this->home->getDisplayName()),
                $this->key(Dav::NS_DAV, 'current-user-principal') => $this->hrefProperty('current-user-principal', $this->principalHref()),
                $this->key(Dav::NS_DAV, 'principal-URL') => $this->hrefProperty('principal-URL', $this->principalHref()),
                $this->key(Dav::NS_CALDAV, 'calendar-home-set') => $this->hrefProperty('calendar-home-set', $this->homeHref(), Dav::NS_CALDAV),
                $this->key(Dav::NS_CALDAV, 'calendar-user-address-set') => $this->hrefProperty('calendar-user-address-set', $this->principalHref(), Dav::NS_CALDAV),
                $this->key(Dav::NS_CALDAV, 'supported-calendar-component-set') => DavProperty::notFound(Dav::NS_CALDAV, 'supported-calendar-component-set'),
                $this->key(Dav::NS_DAV, 'principal-collection-set') => $this->hrefProperty('principal-collection-set', $this->basePath . 'principals/'),
                $this->key(Dav::NS_CALDAV, 'calendar-user-type') => DavProperty::text(Dav::NS_CALDAV, 'calendar-user-type', 'INDIVIDUAL'),
            ]),
        );

        return $this->multistatus($builder);
    }

    /**
     * @param list<string>                       $segments
     * @param array<string, string|list<string>> $headers
     */
    private function handleCalendars(string $method, array $segments, string $body, array $headers, int $depth): CalDavResponse
    {
        if (count($segments) < 2 || $segments[1] !== $this->home->getPrincipalId()) {
            return $this->notFound();
        }

        // /caldav/calendars/{principal}/ — the list itself.
        if (count($segments) === 2) {
            return $this->handleHome($method, $body, $depth);
        }

        $collection = $this->home->findCollection($segments[2]);

        if ($collection === null) {
            return $this->notFound();
        }

        $server = CalDavServer::forCollection(
            $collection,
            $this->collectionHref($collection),
            $this->principalHref(),
        );

        // /caldav/calendars/{principal}/{calendar}/
        if (count($segments) === 3) {
            return $server->handleRequest($method, '', $body, $headers);
        }

        // /caldav/calendars/{principal}/{calendar}/{uid}.ics
        if (count($segments) === 4) {
            return $server->handleRequest($method, $this->uidOf($segments[3]), $body, $headers);
        }

        return $this->notFound();
    }

    private function handleHome(string $method, string $body, int $depth): CalDavResponse
    {
        if ($method === 'OPTIONS') {
            return $this->options();
        }

        if ($method !== 'PROPFIND') {
            return $this->methodNotAllowed();
        }

        $request = PropfindRequest::fromXml($body);
        $builder = new MultiStatusBuilder();

        $builder->addResponse(
            $this->homeHref(),
            $this->select($request, [
                $this->key(Dav::NS_DAV, 'resourcetype') => $this->plainCollection(),
                $this->key(Dav::NS_DAV, 'displayname') => DavProperty::text(Dav::NS_DAV, 'displayname', $this->home->getDisplayName()),
                $this->key(Dav::NS_DAV, 'current-user-principal') => $this->hrefProperty('current-user-principal', $this->principalHref()),
                $this->key(Dav::NS_DAV, 'owner') => $this->hrefProperty('owner', $this->principalHref()),
                $this->key(Dav::NS_CALDAV, 'calendar-home-set') => $this->hrefProperty('calendar-home-set', $this->homeHref(), Dav::NS_CALDAV),
            ]),
        );

        // Depth: 1 is how a client learns which calendars exist — one response
        // per collection, described by the collection itself.
        if ($depth >= 1) {
            foreach ($this->home->listCollections() as $collection) {
                $server = CalDavServer::forCollection(
                    $collection,
                    $this->collectionHref($collection),
                    $this->principalHref(),
                );

                $builder->addResponse(
                    $this->collectionHref($collection),
                    $server->describeCollection($request),
                );
            }
        }

        return $this->multistatus($builder);
    }

    /**
     * Nothing here is writable by a client, and saying so per property is what
     * keeps a client from deciding the server is broken.
     */
    private function handleProppatch(string $body, string $path): CalDavResponse
    {
        $builder = new MultiStatusBuilder();
        $properties = [];

        foreach ($this->proppatchProperties($body) as $property) {
            $properties[] = DavProperty::notFound($property['namespace'], $property['name']);
        }

        $builder->addResponse($path, $properties);

        return $this->multistatus($builder);
    }

    /**
     * @return list<array{namespace: string, name: string}>
     */
    private function proppatchProperties(string $body): array
    {
        $doc = SafeXml::parse($body);

        if ($doc === null) {
            return [];
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('D', Dav::NS_DAV);
        $nodes = $xpath->query('//D:set/D:prop/* | //D:remove/D:prop/*');

        if ($nodes === false) {
            return [];
        }

        $properties = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $properties[] = [
                'namespace' => $node->namespaceURI ?? '',
                'name' => $node->localName ?? $node->nodeName,
            ];
        }

        return $properties;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The path below the base, split — or null when the path is not ours.
     *
     * @return list<string>|null
     */
    private function segmentsOf(string $path): ?array
    {
        $path = parse_url($path, PHP_URL_PATH);

        if (!is_string($path)) {
            return null;
        }

        $path = rawurldecode($path);
        $base = rtrim($this->basePath, '/');

        if ($path !== $base && !str_starts_with($path, $this->basePath)) {
            return null;
        }

        $relative = trim(substr($path, strlen($base)), '/');

        return $relative === '' ? [] : explode('/', $relative);
    }

    private function uidOf(string $resource): string
    {
        return preg_replace('/\.ics$/i', '', $resource) ?? $resource;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function depthOf(array $headers): int
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Depth') !== 0) {
                continue;
            }
            $depth = is_array($value) ? (string) ($value[0] ?? '0') : $value;

            return $depth === 'infinity' ? 1 : (int) $depth;
        }

        return 0;
    }

    /**
     * @param array<string, DavProperty> $available
     *
     * @return list<DavProperty>
     */
    private function select(PropfindRequest $request, array $available): array
    {
        if ($request->mode === PropfindRequest::MODE_ALLPROP || $request->mode === PropfindRequest::MODE_PROPNAME) {
            $properties = [];
            foreach ($available as $property) {
                if (!$property->found) {
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

    private function plainCollection(): DavProperty
    {
        return DavProperty::structured(
            Dav::NS_DAV,
            'resourcetype',
            static function (DOMDocument $doc, DOMElement $element): void {
                $element->appendChild($doc->createElementNS(Dav::NS_DAV, 'D:collection'));
            },
        );
    }

    private function hrefProperty(string $name, string $href, string $namespace = Dav::NS_DAV): DavProperty
    {
        return DavProperty::structured(
            $namespace,
            $name,
            static function (DOMDocument $doc, DOMElement $element) use ($href): void {
                $node = $doc->createElementNS(Dav::NS_DAV, 'D:href');
                $node->appendChild($doc->createTextNode($href));
                $element->appendChild($node);
            },
        );
    }

    private function multistatus(MultiStatusBuilder $builder): CalDavResponse
    {
        return new CalDavResponse(207, $builder->toXml(), headers: ['DAV' => Dav::COMPLIANCE]);
    }

    private function options(): CalDavResponse
    {
        return new CalDavResponse(200, '', 'text/plain', [
            'Allow' => Dav::ALLOWED_METHODS,
            'DAV' => Dav::COMPLIANCE,
            'Content-Length' => '0',
        ]);
    }

    private function methodNotAllowed(): CalDavResponse
    {
        return new CalDavResponse(405, '', 'text/plain', ['Allow' => 'OPTIONS, PROPFIND, PROPPATCH']);
    }

    private function notFound(): CalDavResponse
    {
        return new CalDavResponse(404, 'Not Found', 'text/plain');
    }

    private function key(string $namespace, string $name): string
    {
        return '{' . $namespace . '}' . $name;
    }
}
