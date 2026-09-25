<?php

declare(strict_types=1);

namespace Tito10047\Calendar;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Enum\WeekStart;
use Tito10047\Calendar\Interface\DaysGeneratorInterface;

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
 *
 * $type accepts any DaysGeneratorInterface (e.g. new DateRangeGenerator($from, $to)); custom
 * generators must be serialisable for cacheKey() to be stable.
 */
final class CalendarConfig implements \Stringable
{
    /** @var list<string> Y-m-d keys, sorted */
    private readonly array $disabledDayKeys;

    /** @var list<string> Y-m-d keys, sorted */
    private readonly array $enabledDayKeys;

    /** @var list<string> DayName names, sorted */
    private readonly array $disabledDayNameKeys;

    /** @var list<DayName> */
    public readonly array $disabledDayNames;

    /**
     * @param array<DateTimeImmutable> $disabledDays     Date-specific disabled dates
     * @param array<DayName>           $disabledDayNames Structural weekday pattern (e.g. weekends)
     * @param array<DateTimeImmutable> $enabledDays      Exceptions that override disabledDayNames
     */
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly DaysGeneratorInterface $type = CalendarType::Monthly,
        public readonly WeekStart $startDay = WeekStart::Monday,
        array $disabledDays = [],
        array $disabledDayNames = [],
        array $enabledDays = [],
    ) {
        $this->disabledDayKeys = self::dateKeys($disabledDays, 'disabledDays');
        $this->enabledDayKeys  = self::dateKeys($enabledDays, 'enabledDays');

        $names = [];
        foreach ($disabledDayNames as $name) {
            if (!$name instanceof DayName) {
                throw new \InvalidArgumentException('disabledDayNames must contain only ' . DayName::class . ' values, got ' . get_debug_type($name));
            }
            $names[$name->name] = $name;
        }
        $this->disabledDayNames = array_values($names);

        $nKeys = array_keys($names);
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
            'calendar:%s:%s:%s:%s:%s:%s:%s',
            $this->date->format('Y-m-d'),
            str_replace(':', '_', $this->date->getTimezone()->getName()),
            $this->type instanceof \UnitEnum
                ? $this->type->name
                : str_replace('\\', '.', $this->type::class) . '#' . hash('xxh3', serialize($this->type)),
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

    /**
     * @param  array<mixed> $dates
     * @return list<string>
     */
    private static function dateKeys(array $dates, string $field): array
    {
        $keys = [];
        foreach ($dates as $date) {
            if (!$date instanceof \DateTimeInterface) {
                throw new \InvalidArgumentException("{$field} must contain only DateTimeInterface values, got " . get_debug_type($date));
            }
            $keys[$date->format('Y-m-d')] = true;
        }
        $keys = array_keys($keys);
        sort($keys);
        return $keys;
    }

    public function __toString(): string
    {
        return $this->cacheKey();
    }
}
