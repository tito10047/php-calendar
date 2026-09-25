<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

/**
 * One calendar as a CalDAV client sees it: a name, a colour, a change tag and
 * the events behind it.
 *
 * A client shows this as one entry in its sidebar, so everything on it is
 * something the reader will actually see — and `getCtag()` is what saves them
 * a full download on every sync.
 */
interface CalendarCollectionInterface
{
    /**
     * The path segment this collection lives under. Anything URL-safe; it is
     * what a client stores for years, so it must not change.
     */
    public function getId(): string;

    public function getDisplayName(): string;

    public function getDescription(): ?string;

    /**
     * `#rrggbb` (optionally with alpha) for the dot a client draws next to the
     * calendar, or null to let the client pick.
     */
    public function getColor(): ?string;

    /**
     * An opaque token that changes whenever **anything** in the collection
     * changes. A client that sees the same ctag twice skips the sync entirely,
     * which is the difference between a phone that syncs a calendar in a few
     * bytes and one that downloads a year of days every ten minutes.
     */
    public function getCtag(): string;

    /**
     * Whether a client may create, change or delete events here. False makes
     * the collection read-only and every write answers 403.
     */
    public function isWritable(): bool;

    public function getStore(): CalendarEventStoreInterface;
}
