<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Resource;

use DateTimeImmutable;

/**
 * Optional extension of ResourceDataLoaderInterface that loads every resource in one call
 * (e.g. a single `WHERE resource_id IN (…)` query) instead of one load() per resource.
 *
 * When the loader passed to ResourceCalendar implements this interface, loadAll() is called
 * exactly once per ResourceCalendar and load() is never called.
 */
interface BatchResourceDataLoaderInterface extends ResourceDataLoaderInterface
{
    /**
     * Bulk-load data for all $resources within [$from, $to].
     *
     * @param list<ResourceInterface> $resources
     */
    public function loadAll(array $resources, DateTimeImmutable $from, DateTimeImmutable $to): void;
}
