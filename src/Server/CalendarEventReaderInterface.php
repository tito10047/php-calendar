<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use DateTimeImmutable;
use Tito10047\Calendar\ICal\ICalEvent;

/**
 * Provides read access to stored events for CalDAV server responses (GET, REPORT).
 *
 * Implement this interface so that CalDavServer can serve event data back to
 * CalDAV clients during synchronisation.
 */
interface CalendarEventReaderInterface
{
    /**
     * Return a single event by UID, or null if it does not exist.
     * Used by HTTP GET /caldav/{uid}.ics.
     */
    public function getEvent(string $uid): ?ICalEvent;

    /**
     * Return all events that have at least one occurrence in [$from, $to].
     * A null boundary means "no limit on that side" — PROPFIND with Depth: 1
     * asks for the whole collection, because that is what a client syncing for
     * the first time needs to see.
     *
     * For recurring events return the master ICalEvent (with RRULE intact),
     * not individual expanded occurrences — CalDAV clients handle their own expansion.
     * Used by HTTP REPORT (calendar-query) and PROPFIND.
     *
     * @return list<ICalEvent>
     */
    public function listEvents(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array;
}
