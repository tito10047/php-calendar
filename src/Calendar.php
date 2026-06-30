<?php

declare(strict_types=1);

namespace Tito10047\Calendar;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Interface\CalendarInterface;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;
use Tito10047\Calendar\Interface\DaysGeneratorInterface;

final class Calendar implements CalendarInterface
{
    /** @var non-empty-list<DateTimeImmutable> */
    private array $days;

    public function __construct(
        private readonly DateTimeImmutable $date,
        private readonly DaysGeneratorInterface $daysGenerator = CalendarType::Monthly,
        private readonly DayName $startDay = DayName::Monday,
        /** @var array<string, DateTimeImmutable> $disabledDays */
        private readonly array $disabledDays = [],
        private ?DayDataLoaderInterface $dataLoader = null
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
     * Pass pre-loaded day data as a flat array keyed by 'Y-m-d'.
     *
     * @param array<string, array<mixed>> $data Per-day data keyed by 'Y-m-d'
     */
    public static function fromConfig(CalendarConfig $config, array $data = []): self
    {
        $disabledDays = [];
        foreach ($config->getDisabledDayKeys() as $key) {
            $disabledDays[$key] = new DateTimeImmutable($key);
        }

        $calendar = new self(
            date: $config->date,
            daysGenerator: $config->type,
            startDay: $config->startDay,
            disabledDays: $disabledDays,
        );

        if ($data !== []) {
            $calendar = $calendar->setDataLoader(new \Tito10047\Calendar\DataLoader\ArrayDataLoader($data));
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

    // -------------------------------------------------------------------------
    // Immutable mutations
    // -------------------------------------------------------------------------

    public function withDate(DateTimeImmutable $date): self
    {
        return new self(
            date: $date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $this->disabledDays,
            dataLoader: $this->dataLoader,
        );
    }

    public function disableDaysRange(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): self
    {
        if (!$from) {
            $from = $this->days[0];
        }
        if (!$to) {
            $to = $this->days[array_key_last($this->days)];
        }
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);
        $current = clone $from;
        $days = [];
        while ($current <= $to) {
            $days[] = $current;
            $current = $current->modify('+1 day');
        }
        return $this->disableDays(...$days);
    }


    public function disableDays(DateTimeImmutable ...$days): self
    {
        $disabledDays = $this->disabledDays;
        foreach ($days as $day) {
            $disabledDays[$day->format('Y-m-d')] = $day;
        }
        return new self(
            date: $this->date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $disabledDays,
            dataLoader: $this->dataLoader,
        );
    }

    public function enableDays(DateTimeImmutable ...$days): self
    {
        $disabledDays = $this->disabledDays;
        foreach ($days as $day) {
            unset($disabledDays[$day->format('Y-m-d')]);
        }
        return new self(
            date: $this->date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $disabledDays,
            dataLoader: $this->dataLoader,
        );
    }

    public function disableDaysByName(DayName ...$daysToDisable): static
    {
        $disabled = [];
        foreach ($this->days as $day) {
            foreach ($daysToDisable as $dayName) {
                if (DayName::fromDate($day) === $dayName) {
                    $disabled[] = $day;
                }
            }
        }
        return $this->disableDays(...$disabled);
    }

    public function disableWeek(int $weekNum): self
    {
        $disabled = [];
        foreach ($this->days as $day) {
            if ((int)$day->format('W') === $weekNum) {
                $disabled[] = $day;
            }
        }
        return $this->disableDays(...$disabled);
    }

    public function setDataLoader(DayDataLoaderInterface $dataLoader): self
    {
        return new self(
            date: $this->date,
            daysGenerator: $this->daysGenerator,
            startDay: $this->startDay,
            disabledDays: $this->disabledDays,
            dataLoader: $dataLoader
        );
    }


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
            dataLoader: $this->dataLoader,
        );
    }

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
            dataLoader: $this->dataLoader,
        );
    }

    /**
     * @return Day[][]
     */
    public function getDaysTable(): array
    {
        $thisMonthNum = $this->date->format('m');
        $ghostsEnabled = $this->daysGenerator->hasGhostDays();
        $days = $this->days;
        $today = date('Y-m-d');
        $rows = [];
        $firstDay = $days[0];
        $lastDay = $days[array_key_last($days)];
        $this->dataLoader?->load($firstDay, $lastDay);
        while (count($days) > 0) {
            $row = [];
            $firstDay = $days[0];
            $weekNum = (int)$firstDay->format('W');
            for ($i = (int)$firstDay->format("N"); $i <= 7 and count($days) > 0; $i++) {
                $day = array_shift($days);
                $dayElm = new Day(
                    date: $day,
                    ghost: $ghostsEnabled && $day->format('m') !== $thisMonthNum,
                    today: $day->format('Y-m-d') === $today,
                    enabled: !array_key_exists($day->format('Y-m-d'), $this->disabledDays),
                );
                if ($this->dataLoader) {
                    $dayElm = $dayElm->withData(
                        data: $this->dataLoader->getData($day)
                    );
                }
                $row[$i] = $dayElm;
            }
            $rows[$weekNum] = $row;
        }
        return $rows;
    }

    public function isDayDisabled(DateTimeImmutable|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return array_key_exists($day->format('Y-m-d'), $this->disabledDays);
    }

    public function getStartDay(): DayName
    {
        return $this->startDay;
    }


    public function getDisabledDays(): array
    {
        return array_values($this->disabledDays);
    }

    public function isFirstDay(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return $this->date->modify("first day of this month")->format('Y-m-d') === $day->format('Y-m-d');
    }

    public function isLastDay(\DateTimeInterface|Day $day): bool
    {
        if ($day instanceof Day) {
            $day = $day->date;
        }
        return $this->date->modify("last day of this month")->format('Y-m-d') === $day->format('Y-m-d');
    }

}
