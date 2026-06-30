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
            self::Monthly              => new \DateInterval('P1M'),
            self::Weekly, self::WorkWeek => new \DateInterval('P7D'),
        };
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function getDays(\DateTimeImmutable $day, WeekStart $weekStart): array
    {
        $days       = [];
        $currentDay = $this->getStartDate($day, $weekStart);
        $lastDay    = $this->getEndDate($day, $weekStart);
        while ($currentDay <= $lastDay) {
            $days[]     = $currentDay;
            $currentDay = $currentDay->modify('+1 day');
        }
        return $days;
    }

    public function getStartDate(\DateTimeImmutable $day, WeekStart $weekStart): \DateTimeImmutable
    {
        $anchor = match ($this) {
            self::Monthly              => $day->modify('first day of this month'),
            self::Weekly, self::WorkWeek => $day,
        };
        $anchor    = $anchor->setTime(0, 0, 0);
        $dayOfWeek = (int) $anchor->format('N');
        $daysBack  = ($dayOfWeek - $weekStart->value + 7) % 7;
        return $anchor->modify("-{$daysBack} days");
    }

    public function getEndDate(\DateTimeImmutable $day, WeekStart $weekStart): \DateTimeImmutable
    {
        return match ($this) {
            self::Monthly              => $this->monthlyEndDate($day, $weekStart),
            self::Weekly, self::WorkWeek => $this->getStartDate($day, $weekStart)->modify('+6 days'),
        };
    }

    private function monthlyEndDate(\DateTimeImmutable $day, WeekStart $weekStart): \DateTimeImmutable
    {
        $lastOfMonth   = $day->modify('last day of this month')->setTime(0, 0, 0);
        $dayOfWeek     = (int) $lastOfMonth->format('N');
        $lastDayOfWeek = $weekStart->lastDayIsoNumber();
        $daysForward   = ($lastDayOfWeek - $dayOfWeek + 7) % 7;
        return $lastOfMonth->modify("+{$daysForward} days");
    }
}
