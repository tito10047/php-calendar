<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use Tito10047\Calendar\ICal\ICalEvent;

/**
 * Receives write operations from a CalDAV client (PUT, DELETE).
 *
 * Implement this interface to persist incoming events in your storage of choice
 * (database, file, external CalDAV server, …).
 */
interface CalendarEventWriterInterface
{
    /**
     * Persist a new or updated event.
     * Called when a CalDAV client sends HTTP PUT /caldav/{uid}.ics.
     *
     * The $uid comes from the URL; it always matches $event->uid when the client
     * follows RFC 4791, but you should treat the URL $uid as authoritative.
     */
    public function putEvent(string $uid, ICalEvent $event): void;

    /**
     * Delete an event by UID.
     * Called when a CalDAV client sends HTTP DELETE /caldav/{uid}.ics.
     * Silently ignoring an unknown UID is acceptable.
     */
    public function deleteEvent(string $uid): void;
}
