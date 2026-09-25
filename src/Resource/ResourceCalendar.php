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
 *       // $table is Day[][] keyed by [yearWeek][isoDay] — same shape as Calendar::getDaysTable()
 *   }
 *
 * Data loading: with a plain ResourceDataLoaderInterface, load() runs once per resource. Implement
 * BatchResourceDataLoaderInterface to load all resources with a single loadAll() call instead.
 */
final class ResourceCalendar
{
    /** @var array<string, array<int, array<int, Day>>>  Keyed by resourceId, lazy-loaded on first access */
    private array $cache = [];

    private bool $batchLoaded = false;

    /**
     * @param ResourceInterface[] $resources
     */
    public function __construct(
        private readonly Calendar $calendar,
        private readonly array $resources,
        private readonly ResourceDataLoaderInterface $loader,
    ) {
    }

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
     * @return array<int, array<int, Day>> keyed as [yearWeek][isoDay]
     */
    public function getDaysTableForResource(ResourceInterface $resource): array
    {
        $id = $resource->getResourceId();

        if (!isset($this->cache[$id])) {
            $preloaded = false;
            if ($this->loader instanceof BatchResourceDataLoaderInterface) {
                if (!$this->batchLoaded) {
                    $range = $this->calendar->getDateRange();
                    $this->loader->loadAll(array_values($this->resources), $range['from'], $range['to']);
                    $this->batchLoaded = true;
                }
                $preloaded = true;
            }

            $this->cache[$id] = $this->calendar
                ->setDataLoader(new ResourceLoaderAdapter($this->loader, $resource, $preloaded))
                ->getDaysTable();
        }

        return $this->cache[$id];
    }

    /**
     * Return the full resource table in one call.
     *
     * @return array<string, array<int, array<int, Day>>> keyed by resourceId
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
