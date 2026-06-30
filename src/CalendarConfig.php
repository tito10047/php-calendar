<?php

declare(strict_types=1);

namespace Tito10047\Calendar;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

/**
 * Pure value object holding calendar configuration without any runtime dependencies.
 * Fully serialisable — safe to store in Redis, Memcached, or any cache backend.
 *
 * Usage:
 *   $config = new CalendarConfig(date: new DateTimeImmutable('2024-11'), type: CalendarType::Monthly);
 *   $cacheKey = $config->cacheKey();
 *   $calendar = Calendar::fromConfig($config);
 */
final class CalendarConfig implements \Stringable
{
    /** @var list<string> Y-m-d keys of disabled days */
    private readonly array $disabledDayKeys;

    /**
     * @param DateTimeImmutable[] $disabledDays
     */
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly CalendarType $type = CalendarType::Monthly,
        public readonly DayName $startDay = DayName::Monday,
        array $disabledDays = [],
    ) {
        $keys = [];
        foreach ($disabledDays as $day) {
            $keys[] = $day->format('Y-m-d');
        }
        sort($keys);
        $this->disabledDayKeys = $keys;
    }

    /**
     * Deterministic, cache-safe key derived from all config fields.
     * Changing any field produces a different key.
     */
    public function cacheKey(): string
    {
        return sprintf(
            'calendar:%s:%s:%s:%s',
            $this->date->format('Y-m-d'),
            $this->type->name,
            $this->startDay->name,
            hash('xxh3', implode(',', $this->disabledDayKeys)),
        );
    }

    /** @return list<string> */
    public function getDisabledDayKeys(): array
    {
        return $this->disabledDayKeys;
    }

    public function __toString(): string
    {
        return $this->cacheKey();
    }
}
