<?php

declare(strict_types=1);

namespace Tito10047\Calendar;

use DateTimeImmutable;
use Tito10047\Calendar\DataLoader\ArrayDataLoader;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Interface\CalendarInterface;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;
use Tito10047\Calendar\Interface\DaysGeneratorInterface;

final class Calendar implements CalendarInterface
{
    /** @var non-empty-list<DateTimeImmutable> */
    private array $days;

    /**
     * @param array<string, DateTimeImmutable> $disabledDays    Date-specific disabled dates (Y-m-d keys)
     * @param list<DayName>                    $disabledDayNames Structural weekday pattern (e.g. weekends)
     * @param array<string, DateTimeImmutable> $enabledDays     Exception overrides for disabledDayNames (Y-m-d keys)
     */
    public function __construct(
        private readonly DateTimeImmutable $date,
        private readonly DaysGeneratorInterface $daysGenerator = CalendarType::Monthly,
        private readonly DayName $startDay = DayName::Monday,
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

    public static function forMonth(int $year, int $month, DayName $startDay = DayName::Monday): self
    {
        return new self(
            date: new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)),
            daysGenerator: CalendarType::Monthly,
            startDay: $startDay,
        );
    }

    public static function forWeek(DateTimeImmutable $date, DayName $startDay = DayName::Monday): self
    {
        return new self(
            date: $date,
            daysGenerator: CalendarType::Weekly,
            startDay: $startDay,
        );
    }

    public static function forToday(
        DaysGeneratorInterface $type = CalendarType::Monthly,
        DayName $startDay = DayName::Monday,
    ): self {
        return new self(
            date: new DateTimeImmutable('today'),
            daysGenerator: $type,
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
        $disabledDays = [];
        foreach ($config->getDisabledDayKeys() as $key) {
            $disabledDays[$key] = new DateTimeImmutable($key);
        }

        $enabledDays = [];
        foreach ($config->getEnabledDayKeys() as $key) {
            $enabledDays[$key] = new DateTimeImmutable($key);
        }

        $calendar = new self(
            date: $config->date,
            daysGenerator: $config->type,
            startDay: $config->startDay,
            disabledDays: $disabledDays,
            disabledDayNames: $config->disabledDayNames,
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

    public function getStartDay(): DayName
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

    public function isFirstDay(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return $this->date->modify('first day of this month')->format('Y-m-d') === $day->format('Y-m-d');
    }

    public function isLastDay(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return $this->date->modify('last day of this month')->format('Y-m-d') === $day->format('Y-m-d');
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
        $date = $this->date->add($this->daysGenerator->getNavigationStep());
        if ($this->daysGenerator->hasGhostDays()) {
            $date = $date->modify('first day of this month');
        }
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
        $date = $this->date->sub($this->daysGenerator->getNavigationStep());
        if ($this->daysGenerator->hasGhostDays()) {
            $date = $date->modify('first day of this month');
        }
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

    public function disableWeek(int $weekNum): self
    {
        $disabled = array_filter(
            $this->days,
            static fn (DateTimeImmutable $d) => (int) $d->format('W') === $weekNum,
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
     * @return Day[][]
     */
    public function getDaysTable(): array
    {
        $thisMonthNum  = $this->date->format('m');
        $ghostsEnabled = $this->daysGenerator->hasGhostDays();
        $days          = $this->days;
        $today         = date('Y-m-d');
        $rows          = [];

        $this->dataLoader?->load($days[0], $days[array_key_last($days)]);

        while ($days !== []) {
            $firstDay = $days[0];
            $weekNum  = (int) $firstDay->format('W');
            $row      = [];

            for ($i = (int) $firstDay->format('N'); $i <= 7 && $days !== []; $i++) {
                $day    = array_shift($days);
                $row[$i] = new Day(
                    date: $day,
                    ghost: $ghostsEnabled && $day->format('m') !== $thisMonthNum,
                    today: $day->format('Y-m-d') === $today,
                    enabled: $this->resolveEnabled($day),
                    data: $this->dataLoader?->getData($day),
                );
            }

            $rows[$weekNum] = $row;
        }

        return $rows;
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
