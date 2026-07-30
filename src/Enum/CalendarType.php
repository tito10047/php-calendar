<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Enum;

use Tito10047\Calendar\Interface\DaysGeneratorInterface;

/**
 * Built-in day-range generators.
 *
 * Monthly  — full month padded to complete weeks (ghost days at both ends).
 * Weekly   — 7-day week starting on the configured WeekStart day.
 * WorkWeek — 5-day Mon–Fri week; WeekStart is ignored (always Monday-anchored).
 *            Use this when Saturday and Sunday must never appear in the grid.
 */
enum CalendarType implements DaysGeneratorInterface
{
    /** Full calendar month, padded to complete weeks. Ghost days mark adjacent-month padding. */
    case Monthly;
    /** 7-day week starting on the configured WeekStart day. */
    case Weekly;
    /** 5-day Mon–Fri week. WeekStart is ignored; the grid always runs Monday → Friday. */
    case WorkWeek;

    public function hasGhostDays(): bool
    {
        return $this === self::Monthly;
    }

    public function getNavigationStep(): \DateInterval
    {
        return match ($this) {
            self::Monthly                => new \DateInterval('P1M'),
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
            self::Monthly  => $day->modify('first day of this month'),
            self::Weekly   => $day,
            self::WorkWeek => $day, // always snaps to Monday below
        };
        $anchor    = $anchor->setTime(0, 0, 0);
        $dayOfWeek = (int) $anchor->format('N');
        // WorkWeek always starts on Monday (ISO 1), regardless of $weekStart
        $startValue = $this === self::WorkWeek ? 1 : $weekStart->value;
        $daysBack   = ($dayOfWeek - $startValue + 7) % 7;
        return $anchor->modify("-{$daysBack} days");
    }

    public function getEndDate(\DateTimeImmutable $day, WeekStart $weekStart): \DateTimeImmutable
    {
        return match ($this) {
            self::Monthly  => $this->monthlyEndDate($day, $weekStart),
            self::Weekly   => $this->getStartDate($day, $weekStart)->modify('+6 days'),
            self::WorkWeek => $this->getStartDate($day, $weekStart)->modify('+4 days'), // Monday + 4 = Friday
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
