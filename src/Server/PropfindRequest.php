<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use DOMElement;
use DOMXPath;
use Tito10047\Calendar\Xml\SafeXml;

/**
 * The parsed body of a PROPFIND request (RFC 4918 §9.1).
 *
 * The body is read with DOM, so a property in a namespace we have never seen
 * still comes back addressed correctly in the 404 propstat.
 */
final class PropfindRequest
{
    public const MODE_PROP = 'prop';
    public const MODE_ALLPROP = 'allprop';
    public const MODE_PROPNAME = 'propname';

    /**
     * @param list<array{namespace: string, name: string}> $properties
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $properties = [],
    ) {
    }

    public static function fromXml(string $body): self
    {
        // An empty body means allprop (RFC 4918 §9.1) — and so does a body we
        // cannot parse, because answering something is more useful to a client
        // than a blank stare.
        $doc = SafeXml::parse($body);
        if ($doc === null) {
            return new self(self::MODE_ALLPROP);
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('D', Dav::NS_DAV);

        $propName = $xpath->query('//D:propname');
        if ($propName !== false && $propName->length > 0) {
            return new self(self::MODE_PROPNAME);
        }

        $propNodes = $xpath->query('//D:prop/*');
        if ($propNodes === false || $propNodes->length === 0) {
            return new self(self::MODE_ALLPROP);
        }

        $properties = [];
        foreach ($propNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $properties[] = [
                'namespace' => $node->namespaceURI ?? '',
                'name' => $node->localName ?? $node->nodeName,
            ];
        }

        return new self(self::MODE_PROP, $properties);
    }
}
