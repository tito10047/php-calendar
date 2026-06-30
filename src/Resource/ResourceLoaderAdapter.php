<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Resource;

use DateTimeImmutable;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;

/**
 * Adapts a ResourceDataLoaderInterface for a single resource into a
 * DayDataLoaderInterface that Calendar can consume directly.
 *
 * Used internally by ResourceCalendar — not part of the public API.
 */
final class ResourceLoaderAdapter implements DayDataLoaderInterface
{
    public function __construct(
        private readonly ResourceDataLoaderInterface $loader,
        private readonly ResourceInterface $resource,
    ) {
    }

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): static
    {
        $this->loader->load($this->resource, $from, $to);
        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getData(DateTimeImmutable $date): array
    {
        return $this->loader->getData($this->resource, $date);
    }
}
