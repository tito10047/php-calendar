<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Xml;

use DOMDocument;

/**
 * The one way this library is allowed to turn a request body into a DOM tree.
 *
 * A CalDAV server parses XML that arrives from the network, and the default
 * shape of `DOMDocument::loadXML()` is not safe for that:
 *
 *  - `LIBXML_NOENT` *enables* entity substitution rather than disabling it —
 *    the name reads the other way round and has cost more than one project a
 *    file disclosure. With it on, a body carrying
 *    `<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>` gets the file
 *    read and its contents pasted into the tree, which the server then echoes
 *    back inside its 207. Nothing here needs entity substitution: the parsers
 *    read element names and the text of `href`, and both survive an entity
 *    reference being left alone.
 *  - A document type declaration has no legitimate place in a DAV request at
 *    all. Refusing one outright closes the billion-laughs family of attacks
 *    too, which limits alone do not.
 *
 * `LIBXML_NONET` stays for defence in depth even though `DOCTYPE` is already
 * refused, and libxml errors are captured so a malformed body is a `null`
 * rather than a warning in somebody's log.
 */
final class SafeXml
{
    /**
     * The parsed document, or null when the body is empty, malformed, or
     * carries a `DOCTYPE`.
     */
    public static function parse(string $body): ?DOMDocument
    {
        if (trim($body) === '') {
            return null;
        }

        $doc = new DOMDocument();

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $doc->loadXML($body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            return null;
        }

        // Belt and braces: even without LIBXML_NOENT an external DTD subset is
        // something a request body has no reason to carry, and a parser that
        // was configured differently one version from now would start
        // substituting again.
        if ($doc->doctype !== null) {
            return null;
        }

        return $doc;
    }
}
