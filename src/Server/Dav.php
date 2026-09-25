<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

/**
 * The XML namespaces a CalDAV conversation is conducted in.
 */
final class Dav
{
    public const NS_DAV = 'DAV:';
    public const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';
    public const NS_CALENDARSERVER = 'http://calendarserver.org/ns/';

    /** Compliance classes advertised in the DAV response header. */
    public const COMPLIANCE = '1, 2, 3, calendar-access';

    public const ALLOWED_METHODS = 'OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, REPORT';

    private function __construct()
    {
    }
}
