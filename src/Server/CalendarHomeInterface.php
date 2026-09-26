<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

/**
 * Everything one signed-in person's client may see: who they are, and which
 * calendars are theirs.
 *
 * This is what makes discovery possible. A client asks the server "who am I"
 * (`current-user-principal`), then "where are my calendars"
 * (`calendar-home-set`), then lists them — Apple Calendar and DAVx5 will not
 * finish setting up an account any other way.
 */
interface CalendarHomeInterface
{
    /**
     * The path segment that stands for this person. Stable and URL-safe; a
     * client keeps the principal URL for as long as the account exists.
     */
    public function getPrincipalId(): string;

    /**
     * What a client shows for the account itself, next to the list of
     * calendars.
     */
    public function getDisplayName(): string;

    /**
     * @return list<CalendarCollectionInterface>
     */
    public function listCollections(): array;

    public function findCollection(string $id): ?CalendarCollectionInterface;
}
