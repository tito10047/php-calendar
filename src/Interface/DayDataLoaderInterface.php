<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Interface;

interface DayDataLoaderInterface
{
    /**
     * Bulk-load data for the given date range and return a ready-to-query instance.
     *
     * Callers (Calendar::getDaysTable) MUST use the returned instance for subsequent
     * getData() calls — the original instance is not mutated. This allows implementations
     * to be truly immutable: return a new object with the loaded state rather than
     * modifying $this.
     *
     * Stateless implementations (e.g. ArrayDataLoader) may safely return $this.
     *
     * Guaranteed call order: load() is called exactly once before any getData() call
     * within a single getDaysTable() invocation. $date passed to getData() is always
     * within [$from, $to].
     */
    public function load(\DateTimeImmutable $from, \DateTimeImmutable $to): static;

    /**
     * Return per-day data for $date. Called after load() for every day in the grid.
     *
     * @return array<mixed>
     */
    public function getData(\DateTimeImmutable $date): array;
}
