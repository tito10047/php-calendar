<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Resource;

use DateTimeImmutable;

/**
 * Loads day-level data scoped to a specific resource.
 *
 * Implement this to back a ResourceCalendar with real data (database, API, etc.).
 * load() is called once per resource per render; getData() is called per day.
 */
interface ResourceDataLoaderInterface
{
    /**
     * Bulk-load all data for $resource within [$from, $to].
     * Called once before the first getData() call for this resource.
     */
    public function load(ResourceInterface $resource, DateTimeImmutable $from, DateTimeImmutable $to): void;

    /**
     * Return per-day data for $resource on $date.
     * Called after load() for every day in the calendar grid.
     *
     * @return array<mixed>
     */
    public function getData(ResourceInterface $resource, DateTimeImmutable $date): array;
}
