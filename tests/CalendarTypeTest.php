<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

class CalendarTypeTest extends TestCase
{
    public function testMonthlyGetDaysReturnsFullGridForNovember2024(): void
    {
        // November 2024: first day is Friday, last is Saturday.
        // With Monday start: grid Mon 28 Oct – Sun 01 Dec = 35 days (5 weeks).
        $days = CalendarType::Monthly->getDays(new DateTimeImmutable('2024-11-01'), DayName::Monday);
        $this->assertCount(35, $days);
        $this->assertSame('2024-10-28', $days[0]->format('Y-m-d'));
        $this->assertSame('2024-12-01', $days[34]->format('Y-m-d'));
    }

    public function testMonthlyGetDaysReturnsFullGridForFebruary2021(): void
    {
        // February 2021: first day is Monday, last is Sunday — perfect 4 weeks, 28 days.
        $days = CalendarType::Monthly->getDays(new DateTimeImmutable('2021-02-01'), DayName::Monday);
        $this->assertCount(28, $days);
        $this->assertSame('2021-02-01', $days[0]->format('Y-m-d'));
        $this->assertSame('2021-02-28', $days[27]->format('Y-m-d'));
    }

    public function testWeeklyGetDaysReturnsSevenDays(): void
    {
        $days = CalendarType::Weekly->getDays(new DateTimeImmutable('2024-11-05'), DayName::Monday);
        $this->assertCount(7, $days);
        $this->assertSame('2024-11-04', $days[0]->format('Y-m-d'));
        $this->assertSame('2024-11-10', $days[6]->format('Y-m-d'));
    }

    public function testWorkWeekGetDays(): void
    {
        // WorkWeek generates Mon–Sun like Weekly; the caller disables Sat/Sun via disableDaysByName.
        $days = CalendarType::WorkWeek->getDays(new DateTimeImmutable('2024-11-05'), DayName::Monday);
        $this->assertCount(7, $days);
        $this->assertSame('2024-11-04', $days[0]->format('Y-m-d'));
        $this->assertSame('2024-11-10', $days[6]->format('Y-m-d'));
    }

    public function testMonthlyStartDateIsFirstDayOfGridNotMonth(): void
    {
        // November 2024 starts on Friday. Grid starts on Monday 28 Oct.
        $start = CalendarType::Monthly->getStartDate(new DateTimeImmutable('2024-11-01'), DayName::Monday);
        $this->assertSame('2024-10-28', $start->format('Y-m-d'));
    }

    public function testMonthlyEndDateIsLastDayOfGrid(): void
    {
        // November 2024 ends on Saturday. Grid ends on Sunday 01 Dec.
        $end = CalendarType::Monthly->getEndDate(new DateTimeImmutable('2024-11-01'), DayName::Monday);
        $this->assertSame('2024-12-01', $end->format('Y-m-d'));
    }

    public function testWeeklyStartDateIsCurrentWeekMonday(): void
    {
        $start = CalendarType::Weekly->getStartDate(new DateTimeImmutable('2024-11-07'), DayName::Monday);
        $this->assertSame('2024-11-04', $start->format('Y-m-d'));
    }

    public function testWorkWeekEndDateIsSundayOfWeek(): void
    {
        // WorkWeek end date aligns to Sunday (same as Weekly); Sat/Sun are disabled by the caller.
        $end = CalendarType::WorkWeek->getEndDate(new DateTimeImmutable('2024-11-07'), DayName::Monday);
        $this->assertSame('2024-11-10', $end->format('Y-m-d'));
    }

    public function testAllDaysAreConsecutive(): void
    {
        foreach ([CalendarType::Monthly, CalendarType::Weekly, CalendarType::WorkWeek] as $type) {
            $days = $type->getDays(new DateTimeImmutable('2024-11-05'), DayName::Monday);
            for ($i = 1; $i < count($days); $i++) {
                $diff = (int) $days[$i - 1]->diff($days[$i])->days;
                $this->assertSame(1, $diff, "{$type->name}: days[$i] should follow days[" . ($i - 1) . "] by exactly 1 day");
            }
        }
    }
}
