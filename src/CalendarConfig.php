<?php

declare(strict_types=1);

namespace Tito10047\Calendar;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

/**
 * Pure value object holding all calendar configuration.
 * Fully serialisable — safe to store in Redis, Memcached, or any cache backend.
 *
 * Three disable layers (evaluated in priority order by Calendar):
 *   1. enabledDays  — specific dates that are always on, even if their weekday name is disabled
 *   2. disabledDays — specific dates that are always off (e.g. public holidays)
 *   3. disabledDayNames — weekday patterns that are always off (e.g. weekends)
 *
 * Usage:
 *   $config = new CalendarConfig(
 *       date:             new DateTimeImmutable('2024-11'),
 *       type:             CalendarType::Monthly,
 *       disabledDayNames: [DayName::Saturday, DayName::Sunday],
 *       disabledDays:     [new DateTimeImmutable('2024-11-11')], // public holiday
 *       enabledDays:      [new DateTimeImmutable('2024-11-30')], // exceptional Saturday
 *   );
 *   $calendar = Calendar::fromConfig($config);
 *   $cacheKey = $config->cacheKey();
 */
final class CalendarConfig implements \Stringable
{
    /** @var list<string> Y-m-d keys, sorted */
    private readonly array $disabledDayKeys;

    /** @var list<string> Y-m-d keys, sorted */
    private readonly array $enabledDayKeys;

    /** @var list<string> DayName names, sorted */
    private readonly array $disabledDayNameKeys;

    /**
     * @param DateTimeImmutable[] $disabledDays  Date-specific disabled dates
     * @param DayName[]           $disabledDayNames Structural weekday pattern (e.g. weekends)
     * @param DateTimeImmutable[] $enabledDays   Exceptions that override disabledDayNames
     */
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly CalendarType $type = CalendarType::Monthly,
        public readonly DayName $startDay = DayName::Monday,
        array $disabledDays = [],
        public readonly array $disabledDayNames = [],
        array $enabledDays = [],
    ) {
        $dKeys = [];
        foreach ($disabledDays as $day) {
            $dKeys[] = $day->format('Y-m-d');
        }
        sort($dKeys);
        $this->disabledDayKeys = $dKeys;

        $eKeys = [];
        foreach ($enabledDays as $day) {
            $eKeys[] = $day->format('Y-m-d');
        }
        sort($eKeys);
        $this->enabledDayKeys = $eKeys;

        $nKeys = array_map(static fn (DayName $d) => $d->name, $disabledDayNames);
        sort($nKeys);
        $this->disabledDayNameKeys = $nKeys;
    }

    /**
     * Deterministic, cache-safe key derived from all configuration fields.
     * Order of disabledDays / disabledDayNames / enabledDays does not affect the key.
     */
    public function cacheKey(): string
    {
        return sprintf(
            'calendar:%s:%s:%s:%s:%s:%s',
            $this->date->format('Y-m-d'),
            $this->type->name,
            $this->startDay->name,
            hash('xxh3', implode(',', $this->disabledDayNameKeys)),
            hash('xxh3', implode(',', $this->disabledDayKeys)),
            hash('xxh3', implode(',', $this->enabledDayKeys)),
        );
    }

    /** @return list<string> Y-m-d keys */
    public function getDisabledDayKeys(): array
    {
        return $this->disabledDayKeys;
    }

    /** @return list<string> Y-m-d keys */
    public function getEnabledDayKeys(): array
    {
        return $this->enabledDayKeys;
    }

    public function __toString(): string
    {
        return $this->cacheKey();
    }
}
