<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The parsed body of a CalDAV REPORT — calendar-query (RFC 4791 §7.8) or
 * calendar-multiget (§7.9).
 *
 * The filter is read from the XML tree. A regular expression over the raw body
 * cannot tell a time-range attribute from the same text inside a text-match,
 * and picking the wrong one silently returns the wrong month.
 */
final class ReportRequest
{
    public const TYPE_CALENDAR_QUERY = 'calendar-query';
    public const TYPE_CALENDAR_MULTIGET = 'calendar-multiget';
    public const TYPE_UNSUPPORTED = 'unsupported';

    /**
     * @param list<array{namespace: string, name: string}> $properties
     * @param list<string>                                 $hrefs
     */
    private function __construct(
        public readonly string $type,
        public readonly array $properties = [],
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
        public readonly ?string $componentName = null,
        public readonly array $hrefs = [],
    ) {
    }

    public static function fromXml(string $body): self
    {
        $doc = new DOMDocument();
        if (trim($body) === '' || !@$doc->loadXML($body, LIBXML_NONET | LIBXML_NOENT)) {
            return new self(self::TYPE_UNSUPPORTED);
        }

        $root = $doc->documentElement;
        if ($root === null) {
            return new self(self::TYPE_UNSUPPORTED);
        }

        $type = match ($root->localName) {
            'calendar-query' => self::TYPE_CALENDAR_QUERY,
            'calendar-multiget' => self::TYPE_CALENDAR_MULTIGET,
            default => self::TYPE_UNSUPPORTED,
        };

        if ($type === self::TYPE_UNSUPPORTED) {
            return new self(self::TYPE_UNSUPPORTED);
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('D', Dav::NS_DAV);
        $xpath->registerNamespace('C', Dav::NS_CALDAV);

        return new self(
            type: $type,
            properties: self::readProperties($xpath),
            from: self::readRangeBoundary($xpath, 'start'),
            to: self::readRangeBoundary($xpath, 'end'),
            componentName: self::readComponentName($xpath),
            hrefs: self::readHrefs($xpath),
        );
    }

    public function wants(string $namespace, string $name): bool
    {
        foreach ($this->properties as $property) {
            if ($property['namespace'] === $namespace && $property['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return list<array{namespace: string, name: string}>
     */
    private static function readProperties(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//D:prop/*');
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

    private static function readRangeBoundary(DOMXPath $xpath, string $attribute): ?DateTimeImmutable
    {
        $nodes = $xpath->query('//C:filter//C:time-range[@' . $attribute . ']');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if (!$node instanceof DOMElement) {
            return null;
        }

        return self::parseDateTimeValue($node->getAttribute($attribute));
    }

    /**
     * The name of the innermost comp-filter — VEVENT, VTODO, … VCALENDAR is the
     * wrapper every query carries and says nothing about what is wanted.
     */
    private static function readComponentName(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//C:filter//C:comp-filter[@name]');
        if ($nodes === false) {
            return null;
        }

        $name = null;
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $candidate = strtoupper($node->getAttribute('name'));
            if ($candidate !== 'VCALENDAR') {
                $name = $candidate;
            }
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private static function readHrefs(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//D:href');
        if ($nodes === false) {
            return [];
        }

        $hrefs = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->textContent);
            if ($href !== '') {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }

    private static function parseDateTimeValue(string $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $value = trim($value);

        foreach (['Ymd\THis\Z', 'Ymd\THis', '!Ymd'] as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, $utc);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }
}
