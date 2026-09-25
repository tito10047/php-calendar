<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

/**
 * The store refusing one write — not because the method is unknown, but
 * because this particular resource may not be written.
 *
 * A collection can be writable in general and still have resources a client
 * must not touch (a read-only entry it did not create, a date outside a window
 * the application enforces). Throwing this from putEvent() or deleteEvent()
 * turns into a 403 with the WebDAV precondition the client can act on, instead
 * of a 500 that reads like the server is broken.
 */
final class ForbiddenException extends \RuntimeException
{
    /**
     * @param string $precondition the DAV: or CalDAV precondition element name
     */
    public function __construct(
        string $message = 'Forbidden',
        public readonly string $precondition = 'need-privileges',
        public readonly string $preconditionNamespace = Dav::NS_DAV,
    ) {
        parent::__construct($message);
    }
}
