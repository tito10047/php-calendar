<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DOMDocument;
use DOMXPath;
use Tito10047\Calendar\Server\Dav;

/**
 * Reading a multistatus response the way a client does — by namespace and
 * element name, never by string matching.
 */
trait DavXmlHelpers
{
    private function xpath(string $xml): DOMXPath
    {
        $doc = new DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'Response is not well-formed XML');

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('D', Dav::NS_DAV);
        $xpath->registerNamespace('C', Dav::NS_CALDAV);
        $xpath->registerNamespace('CS', Dav::NS_CALENDARSERVER);

        return $xpath;
    }

    private function nodeCount(DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes, 'Invalid XPath: ' . $query);

        return $nodes->length;
    }

    private function nodeText(DOMXPath $xpath, string $query): string
    {
        return (string) $xpath->evaluate('string(' . $query . ')');
    }

    /**
     * @return list<string>
     */
    private function nodeTexts(DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes, 'Invalid XPath: ' . $query);

        $texts = [];
        foreach ($nodes as $node) {
            $texts[] = trim($node->textContent ?? '');
        }

        return $texts;
    }
}
