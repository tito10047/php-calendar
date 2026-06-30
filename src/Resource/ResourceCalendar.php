<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Resource;

use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Day;

/**
 * Resource calendar — a second axis over a base Calendar.
 *
 * Rows are resources (rooms, people, vehicles …).
 * Columns are the days produced by the underlying Calendar.
 *
 * Template iteration pattern:
 *   foreach ($resourceCalendar->getResources() as $resource) {
 *       $table = $resourceCalendar->getDaysTableForResource($resource);
 *       // $table is Day[][] keyed by [weekNum][isoDay] — same shape as Calendar::getDaysTable()
 *   }
 */
final class ResourceCalendar
{
    /** @var array<string, Day[][]>  Keyed by resourceId, lazy-loaded on first access */
    private array $cache = [];

    /**
     * @param ResourceInterface[] $resources
     */
    public function __construct(
        private readonly Calendar $calendar,
        private readonly array $resources,
        private readonly ResourceDataLoaderInterface $loader,
    ) {}

    /** @return ResourceInterface[] */
    public function getResources(): array
    {
        return $this->resources;
    }

    public function getCalendar(): Calendar
    {
        return $this->calendar;
    }

    /**
     * @return Day[][] keyed as [weekNum][isoDay]
     */
    public function getDaysTableForResource(ResourceInterface $resource): array
    {
        $id = $resource->getResourceId();

        if (!isset($this->cache[$id])) {
            $this->cache[$id] = $this->calendar
                ->setDataLoader(new ResourceLoaderAdapter($this->loader, $resource))
                ->getDaysTable();
        }

        return $this->cache[$id];
    }

    /**
     * Return the full resource table in one call.
     *
     * @return array<string, Day[][]> keyed by resourceId
     */
    public function getResourceTable(): array
    {
        $table = [];
        foreach ($this->resources as $resource) {
            $table[$resource->getResourceId()] = $this->getDaysTableForResource($resource);
        }
        return $table;
    }
}
