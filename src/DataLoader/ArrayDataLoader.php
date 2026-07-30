<?php

declare(strict_types=1);

namespace Tito10047\Calendar\DataLoader;

use DateTimeImmutable;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;

/**
 * In-memory DayDataLoaderInterface backed by a plain array.
 *
 * Intended as the bridge between a cache layer and Calendar::fromConfig():
 *   $data = $cache->get($config->cacheKey(), fn() => $loader->computeData($from, $to));
 *   $calendar = Calendar::fromConfig($config, $data);
 */
final class ArrayDataLoader implements DayDataLoaderInterface
{
    /**
     * @param array<string, array<mixed>> $data Keys must be 'Y-m-d' strings.
     */
    public function __construct(private readonly array $data)
    {
    }

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): static
    {
        return $this; // data is provided at construction time, nothing to expand
    }

    /**
     * @return array<mixed>
     */
    public function getData(DateTimeImmutable $date): array
    {
        return $this->data[$date->format('Y-m-d')] ?? [];
    }
}
