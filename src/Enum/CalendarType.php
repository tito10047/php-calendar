<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Enum;

use Tito10047\Calendar\Interface\DaysGeneratorInterface;

enum CalendarType implements DaysGeneratorInterface
{
    case Monthly;
    case Weekly;
    case WorkWeek;

    public function hasGhostDays(): bool
    {
        return $this === self::Monthly;
    }

    public function getNavigationStep(): \DateInterval
    {
        return match ($this) {
            self::Monthly   => new \DateInterval('P1M'),
            self::Weekly,
            self::WorkWeek  => new \DateInterval('P7D'),
        };
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function getDays(\DateTimeImmutable $day, DayName $firstDay): array
    {
        $days = [];
        $currentDay = $this->getStartDate($day, $firstDay);
        $lastDayDate = $this->getEndDate($day, $firstDay);
        while ($currentDay <= $lastDayDate) {
            $days[] = $currentDay;
            $currentDay = $currentDay->modify('+1 day');
        }
        return $days;
    }
    public function getStartDate(\DateTimeImmutable $day, DayName $firstDay): \DateTimeImmutable
    {

        $firstDayDate = $day->modify(match ($this) {
            self::Monthly => 'first day of this month',
            self::Weekly => 'monday this week',
            self::WorkWeek => 'monday this week',
        });
        $firstDayDate = $firstDayDate->modify("monday this week");
        if ($this !== CalendarType::Monthly) {
            if ($firstDay == DayName::Sunday) {
                $firstDayDate = $firstDayDate->modify("-7 day");
            }
            $dayNumber = $firstDay->getDayNumber() - 1;
            $firstDayDate = $firstDayDate->modify("+{$dayNumber} days");
        }
        return $firstDayDate;
    }
    public function getEndDate(\DateTimeImmutable $day, DayName $firstDay): \DateTimeImmutable
    {

        $lastDayDate = $day->modify(match ($this) {
            self::Monthly => 'last day of this month',
            self::Weekly => 'sunday this week',
            self::WorkWeek => 'friday this week',
        });
        $lastDayDate = $lastDayDate->modify("sunday this week");
        if ($this !== CalendarType::Monthly) {
            if ($firstDay == DayName::Sunday) {
                $lastDayDate = $lastDayDate->modify("-7 day");
            }
            $dayNumber = $firstDay->getDayNumber() - 1;
            $lastDayDate = $lastDayDate->modify("+{$dayNumber} days");
        }
        return $lastDayDate;
    }
}
