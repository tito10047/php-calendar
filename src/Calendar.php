<?php

declare(strict_types=1);

namespace Tito10047\Calendar;

use DateTimeImmutable;
use Tito10047\Calendar\DataLoader\ArrayDataLoader;
use Tito10047\Calendar\DataLoader\DateRangeGenerator;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Enum\WeekStart;
use Tito10047\Calendar\Interface\CalendarInterface;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;
use Tito10047\Calendar\Interface\DaysGeneratorInterface;

final class Calendar implements CalendarInterface
{
    /** @var non-empty-list<DateTimeImmutable> */
    private array $days;

    /** @var array<int, array<int, Day>>|null memoised getDaysTable() result */
    private ?array $daysTable = null;

    /**
     * @param array<string, DateTimeImmutable> $disabledDays    Date-specific disabled dates (Y-m-d keys)
     * @param list<DayName>                    $disabledDayNames Structural weekday pattern (e.g. weekends)
     * @param array<string, DateTimeImmutable> $enabledDays     Exception overrides for disabledDayNames (Y-m-d keys)
     */
    public function __construct(
        private readonly DateTimeImmutable $date,
        private readonly DaysGeneratorInterface $daysGenerator = CalendarType::Monthly,
        private readonly WeekStart $startDay = WeekStart::Monday,
        private readonly array $disabledDays = [],
        private readonly array $disabledDayNames = [],
        private readonly array $enabledDays = [],
        private ?DayDataLoaderInterface $dataLoader = null,
    ) {
        $days = $this->daysGenerator->getDays($this->date, $this->startDay);
        if (count($days) === 0) {
            throw new \InvalidArgumentException('Day generator returned no days');
        }
        $this->days = $days;
    }

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    public static function forMonth(
        int $year,
        int $month,
        WeekStart $startDay = WeekStart::Monday,
        ?\DateTimeZone $timezone = null,
    ): self {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Month must be 1–12, got {$month}");
        }
        return new self(
            date: new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone),
            daysGenerator: CalendarType::Monthly,
            startDay: $startDay,
        );
    }

    public static function forWeek(DateTimeImmutable $date, WeekStart $startDay = WeekStart::Monday): self
    {
        return new self(
            date: $date,
            daysGenerator: CalendarType::Weekly,
            startDay: $startDay,
        );
    }

    public static function forToday(
        DaysGeneratorInterface $type = CalendarType::Monthly,
        WeekStart $startDay = WeekStart::Monday,
        ?\DateTimeZone $timezone = null,
    ): self {
        return new self(
            date: new DateTimeImmutable('today', $timezone),
            daysGenerator: $type,
            startDay: $startDay,
        );
    }

    /** Arbitrary date range — not constrained to a month or week boundary. */
    public static function fromDateRange(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        WeekStart $startDay = WeekStart::Monday,
    ): self {
        return new self(
            date: $from,
            daysGenerator: new DateRangeGenerator($from, $to),
            startDay: $startDay,
        );
    }

    /**
     * Reconstruct a Calendar from a serialisable CalendarConfig.
     * Optionally pass pre-loaded day data (e.g. from cache) as a Y-m-d keyed array.
     *
     * @param array<string, array<mixed>> $data Per-day data keyed by 'Y-m-d'
     */
    public static function fromConfig(CalendarConfig $config, array $data = []): self
    {
        $tz = $config->date->getTimezone();

        $disabledDays = [];
        foreach ($config->getDisabledDayKeys() as $key) {
            $disabledDays[$key] = new DateTimeImmutable($key, $tz);
        }

        $enabledDays = [];
        foreach ($config->getEnabledDayKeys() as $key) {
            $enabledDays[$key] = new DateTimeImmutable($key, $tz);
        }

        $calendar = new self(
            date: $config->date,
            daysGenerator: $config->type,
            startDay: $config->startDay,
            disabledDays: $disabledDays,
            disabledDayNames: array_values($config->disabledDayNames),
            enabledDays: $enabledDays,
        );

        if ($data !== []) {
            $calendar = $calendar->setDataLoader(new ArrayDataLoader($data));
        }

        return $calendar;
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public function getDateRange(): array
    {
        return [
            'from' => $this->days[0],
            'to'   => $this->days[array_key_last($this->days)],
        ];
    }

    public function getStartDay(): WeekStart
    {
        return $this->startDay;
    }

    /** @return list<DateTimeImmutable> Date-specific disabled dates only. */
    public function getDisabledDays(): array
    {
        return array_values($this->disabledDays);
    }

    /** @return list<DayName> Structural weekday pattern (e.g. [Saturday, Sunday]). */
    public function getDisabledDayNames(): array
    {
        return $this->disabledDayNames;
    }

    /** @return list<DateTimeImmutable> Dates that override a disabledDayNames rule. */
    public function getEnabledDays(): array
    {
        return array_values($this->enabledDays);
    }

    public function isDayDisabled(\DateTimeImmutable|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return !$this->resolveEnabled($day);
    }

    /** Whether $day is the 1st of the reference date's month (for any calendar type). */
    public function isFirstDay(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return $this->date->modify('first day of this month')->format('Y-m-d') === $day->format('Y-m-d');
    }

    /** Whether $day is the last day of the reference date's month (for any calendar type). */
    public function isLastDay(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return $this->date->modify('last day of this month')->format('Y-m-d') === $day->format('Y-m-d');
    }

    /**
     * Whether $day is the first day of the displayed period: the 1st of the month for month
     * grids (ghost padding excluded), otherwise the first day of the grid (week, work week,
     * fromDateRange()).
     */
    public function isFirstDayOfPeriod(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        if ($this->daysGenerator->hasGhostDays()) {
            return $this->isFirstDay($day);
        }
        return $this->days[0]->format('Y-m-d') === $day->format('Y-m-d');
    }

    /**
     * Whether $day is the last day of the displayed period: the last day of the month for month
     * grids, otherwise the last day of the grid.
     */
    public function isLastDayOfPeriod(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        if ($this->daysGenerator->hasGhostDays()) {
            return $this->isLastDay($day);
        }
        return $this->days[array_key_last($this->days)]->format('Y-m-d') === $day->format('Y-m-d');
    }

    // -------------------------------------------------------------------------
    // Immutable mutations — date navigation
    // -------------------------------------------------------------------------

    /**
     * Return a new instance for a different reference date, preserving all settings
     * (generator, startDay, all disable/enable lists, dataLoader).
     */
    public function withDate(DateTimeImmutable $date): self
    {
        return new self(
            date: $date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $this->disabledDays,
            disabledDayNames: $this->disabledDayNames,
            enabledDays: $this->enabledDays,
            dataLoader: $this->dataLoader,
        );
    }

    /**
     * Advance one period (month for Monthly, week for Weekly/WorkWeek).
     * Structural disabledDayNames are preserved; date-specific disabledDays and
     * enabledDays are reset because they belong to the current period.
     */
    public function nextPeriod(): self
    {
        // Snap month grids to the 1st *before* adding a month, so Jan 31 + 1 month is February
        $date = $this->periodAnchor()->add($this->daysGenerator->getNavigationStep());
        return new self(
            date: $date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: [],
            disabledDayNames: $this->disabledDayNames,
            enabledDays: [],
            dataLoader: $this->dataLoader,
        );
    }

    /**
     * Retreat one period. Same reset semantics as nextPeriod().
     */
    public function prevPeriod(): self
    {
        $date = $this->periodAnchor()->sub($this->daysGenerator->getNavigationStep());
        return new self(
            date: $date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: [],
            disabledDayNames: $this->disabledDayNames,
            enabledDays: [],
            dataLoader: $this->dataLoader,
        );
    }

    // -------------------------------------------------------------------------
    // Immutable mutations — disable / enable
    // -------------------------------------------------------------------------

    public function disableDays(DateTimeImmutable ...$days): self
    {
        $disabledDays = $this->disabledDays;
        $enabledDays  = $this->enabledDays;
        foreach ($days as $day) {
            $key = $day->format('Y-m-d');
            $disabledDays[$key] = $day;
            unset($enabledDays[$key]); // explicit disable wins over exception
        }
        return new self(
            date: $this->date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $disabledDays,
            disabledDayNames: $this->disabledDayNames,
            enabledDays: $enabledDays,
            dataLoader: $this->dataLoader,
        );
    }

    /**
     * Re-enable specific dates.
     * If the date was in the date-specific disabled list it is removed from there.
     * If it would be disabled by a disabledDayNames rule, it is added to enabledDays
     * so it shows as enabled regardless of the weekday pattern.
     */
    public function enableDays(DateTimeImmutable ...$days): self
    {
        $disabledDays = $this->disabledDays;
        $enabledDays  = $this->enabledDays;
        foreach ($days as $day) {
            $key = $day->format('Y-m-d');
            unset($disabledDays[$key]);
            $enabledDays[$key] = $day;
        }
        return new self(
            date: $this->date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $disabledDays,
            disabledDayNames: $this->disabledDayNames,
            enabledDays: $enabledDays,
            dataLoader: $this->dataLoader,
        );
    }

    /**
     * Disable all days matching the given weekday names for every period.
     * Stored as a structural rule (DayName[]), not as concrete dates, so it
     * survives nextPeriod() / prevPeriod() navigation automatically.
     */
    public function disableDaysByName(DayName ...$daysToDisable): self
    {
        $names = $this->disabledDayNames;
        foreach ($daysToDisable as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return new self(
            date: $this->date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $this->disabledDays,
            disabledDayNames: $names,
            enabledDays: $this->enabledDays,
            dataLoader: $this->dataLoader,
        );
    }

    public function disableDaysRange(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): self
    {
        $from    = ($from ?? $this->days[0])->setTime(0, 0, 0);
        $to      = ($to ?? $this->days[array_key_last($this->days)])->setTime(0, 0, 0);
        $current = $from;
        $days    = [];
        while ($current <= $to) {
            $days[]  = $current;
            $current = $current->modify('+1 day');
        }
        return $this->disableDays(...$days);
    }

    /**
     * Disable a week.
     *
     *   disableWeek(45)        — every day in ISO week 45 (of any year present in the grid)
     *   disableWeek(45, 2024)  — ISO week 45 of ISO year 2024 only
     *   disableWeek(202445)    — the grid row with that getDaysTable() key (respects WeekStart)
     */
    public function disableWeek(int $weekNum, ?int $year = null): self
    {
        $disabled = array_filter(
            $this->days,
            fn (DateTimeImmutable $d) => $weekNum > 100
                ? $this->rowKey($d) === $weekNum
                : (int) $d->format('W') === $weekNum && ($year === null || (int) $d->format('o') === $year),
        );
        return $this->disableDays(...$disabled);
    }

    public function setDataLoader(DayDataLoaderInterface $dataLoader): self
    {
        return new self(
            date: $this->date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $this->disabledDays,
            disabledDayNames: $this->disabledDayNames,
            enabledDays: $this->enabledDays,
            dataLoader: $dataLoader,
        );
    }

    // -------------------------------------------------------------------------
    // Calendar grid
    // -------------------------------------------------------------------------

    /**
     * Build the full calendar grid. Triggers DayDataLoaderInterface::load() for the range once;
     * the result is memoised for this (immutable) instance.
     *
     * Outer key = ISO week-year and week number of the row as an int, e.g. 202445 for week 45 of
     *             2024 (use Day::getIsoWeek() or `$key % 100` for the plain week number). Keys are
     *             unique across years and ascend chronologically, so the order survives
     *             json_encode() and ksort(). A row always starts on the configured WeekStart; its
     *             key is the ISO week of the row's Monday.
     * Inner key = ISO weekday number (1 = Monday … 7 = Sunday), in WeekStart order.
     * Ghost days (adjacent-month padding) are present in Monthly grids to fill complete week rows.
     *
     * @return array<int, array<int, Day>>  [yearWeek => [isoDayNum => Day]]
     */
    public function getDaysTable(): array
    {
        if ($this->daysTable !== null) {
            return $this->daysTable;
        }

        $thisMonth     = $this->date->format('Y-m');
        $ghostsEnabled = $this->daysGenerator->hasGhostDays();
        $today         = (new DateTimeImmutable('now', $this->date->getTimezone()))->format('Y-m-d');
        $rows          = [];

        // Use the instance returned by load() — immutable loaders return a new populated object.
        $loader = $this->dataLoader?->load($this->days[0], $this->days[array_key_last($this->days)]);

        foreach ($this->days as $day) {
            $rows[$this->rowKey($day)][(int) $day->format('N')] = new Day(
                date: $day,
                ghost: $ghostsEnabled && $day->format('Y-m') !== $thisMonth,
                today: $day->format('Y-m-d') === $today,
                enabled: $this->resolveEnabled($day),
                data: $loader?->getData($day),
            );
        }

        return $this->daysTable = $rows;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Three-layer enable/disable resolution (highest priority first):
     *   1. enabledDays  — explicit exception, always on
     *   2. disabledDays — explicit date disable, always off
     *   3. disabledDayNames — weekday pattern, off unless overridden by layer 1
     */
    /** getDaysTable() row key of $day: ISO year*100 + ISO week of the Monday in its WeekStart-aligned row. */
    private function rowKey(DateTimeImmutable $day): int
    {
        $weekStart = $this->startDay->value;
        $rowStart  = $day->modify('-' . (((int) $day->format('N') - $weekStart + 7) % 7) . ' days');
        $monday    = $rowStart->modify('+' . ((1 - $weekStart + 7) % 7) . ' days');
        return (int) $monday->format('oW');
    }

    /** Reference date navigation starts from: the 1st of the month for month grids. */
    private function periodAnchor(): DateTimeImmutable
    {
        return $this->daysGenerator->hasGhostDays() ? $this->date->modify('first day of this month') : $this->date;
    }

    private function resolveEnabled(DateTimeImmutable $date): bool
    {
        $key = $date->format('Y-m-d');

        if (isset($this->enabledDays[$key])) {
            return true;
        }

        if (isset($this->disabledDays[$key])) {
            return false;
        }

        if ($this->disabledDayNames !== []) {
            return !in_array(DayName::fromDate($date), $this->disabledDayNames, true);
        }

        return true;
    }
}
