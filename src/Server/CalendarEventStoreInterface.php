<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

/**
 * Combined read/write contract for CalDavServer.
 *
 * Implement this interface in your application to connect CalDavServer to any
 * storage backend (database, external CalDAV server, in-memory array, …).
 *
 * Minimal implementation requires four methods from the parent interfaces:
 *   - putEvent(string $uid, ICalEvent $event): void
 *   - deleteEvent(string $uid): void
 *   - getEvent(string $uid): ?ICalEvent
 *   - listEvents(DateTimeImmutable $from, DateTimeImmutable $to): array
 *
 * A single class can also implement DayDataLoaderInterface, making the same
 * store usable on both the read side (Calendar::getDaysTable()) and the
 * write side (CalDavServer):
 *
 *   class MyStore implements CalendarEventStoreInterface, DayDataLoaderInterface { … }
 *
 *   $store    = new MyStore($db);
 *   $calendar = Calendar::forMonth(2025, 7)->setDataLoader($store);   // read
 *   $server   = new CalDavServer($store);                              // write
 */
interface CalendarEventStoreInterface extends CalendarEventWriterInterface, CalendarEventReaderInterface
{
}
