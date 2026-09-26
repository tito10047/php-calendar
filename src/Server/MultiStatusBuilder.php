<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use DOMDocument;
use DOMElement;

/**
 * Builds a WebDAV 207 Multi-Status document.
 *
 * The document is assembled with DOM rather than string concatenation: a
 * calendar name with an ampersand in it, or a note a client sent us, must not
 * be able to break the XML a sync depends on.
 */
final class MultiStatusBuilder
{
    private DOMDocument $doc;
    private DOMElement $root;

    /** @var array<string, string> namespace URI → prefix */
    private array $prefixes = [
        Dav::NS_DAV => 'D',
        Dav::NS_CALDAV => 'C',
        Dav::NS_CALENDARSERVER => 'CS',
        Dav::NS_APPLE_ICAL => 'IC',
    ];

    private int $generatedPrefixes = 0;

    public function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        // The root is loaded from source rather than built with createElementNS
        // so that the three namespace declarations are real declarations: every
        // child then reuses them instead of repeating xmlns on every element.
        // The declaration is part of the loaded source on purpose: without it
        // the document has no encoding, and saveXML() escapes every non-ASCII
        // character into a numeric entity — a calendar named "Ranná
        // prechádzka" would come back as "Rann&#xE1;".
        $this->doc->loadXML(sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><D:multistatus xmlns:D="%s" xmlns:C="%s" xmlns:CS="%s" xmlns:IC="%s"/>',
            Dav::NS_DAV,
            Dav::NS_CALDAV,
            Dav::NS_CALENDARSERVER,
            Dav::NS_APPLE_ICAL,
        ));

        $root = $this->doc->documentElement;
        if ($root === null) {
            throw new \LogicException('Multistatus root element could not be created.');
        }

        $this->root = $root;
    }

    /**
     * Add one <response> carrying property results, grouped into a 200 propstat
     * and a 404 propstat.
     *
     * @param list<DavProperty> $properties
     */
    public function addResponse(string $href, array $properties): void
    {
        $response = $this->child($this->root, Dav::NS_DAV, 'response');
        $this->textChild($response, Dav::NS_DAV, 'href', $href);

        $found = array_values(array_filter($properties, static fn (DavProperty $p) => $p->found));
        $missing = array_values(array_filter($properties, static fn (DavProperty $p) => !$p->found));

        if ($found !== []) {
            $this->propstat($response, $found, 'HTTP/1.1 200 OK');
        }
        if ($missing !== []) {
            $this->propstat($response, $missing, 'HTTP/1.1 404 Not Found');
        }
    }

    /**
     * Add one <response> that is just a status — used for an href the client
     * asked about that does not exist.
     */
    public function addStatusResponse(string $href, string $status): void
    {
        $response = $this->child($this->root, Dav::NS_DAV, 'response');
        $this->textChild($response, Dav::NS_DAV, 'href', $href);
        $this->textChild($response, Dav::NS_DAV, 'status', $status);
    }

    public function toXml(): string
    {
        return (string) $this->doc->saveXML();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param list<DavProperty> $properties
     */
    private function propstat(DOMElement $response, array $properties, string $status): void
    {
        $propstat = $this->child($response, Dav::NS_DAV, 'propstat');
        $prop = $this->child($propstat, Dav::NS_DAV, 'prop');

        foreach ($properties as $property) {
            $element = $this->child($prop, $property->namespace, $property->name);

            if ($property->text !== null) {
                $element->appendChild($this->doc->createTextNode($property->text));
            } elseif ($property->builder !== null) {
                ($property->builder)($this->doc, $element);
            }
        }

        $this->textChild($propstat, Dav::NS_DAV, 'status', $status);
    }

    /**
     * Create an element and attach it straight away — libxml only reuses the
     * namespace declarations from the root for nodes whose parent is already in
     * the document, and a detached parent means xmlns repeated on every element.
     */
    private function child(DOMElement $parent, string $namespace, string $name): DOMElement
    {
        $element = $namespace === ''
            ? $this->doc->createElement($name)
            : $this->doc->createElementNS($namespace, $this->prefixFor($namespace) . ':' . $name);

        $parent->appendChild($element);

        return $element;
    }

    private function textChild(DOMElement $parent, string $namespace, string $name, string $text): void
    {
        $element = $this->child($parent, $namespace, $name);
        $element->appendChild($this->doc->createTextNode($text));
    }

    private function prefixFor(string $namespace): string
    {
        return $this->prefixes[$namespace] ??= 'ns' . ++$this->generatedPrefixes;
    }
}
