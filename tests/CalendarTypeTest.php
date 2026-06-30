<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\WeekStart;

class CalendarTypeTest extends TestCase
{
    public function testMonthlyGetDaysReturnsFullGridForNovember2024(): void
    {
        // November 2024: first day is Friday, last is Saturday.
        // With Monday start: grid Mon 28 Oct – Sun 01 Dec = 35 days (5 weeks).
        $days = CalendarType::Monthly->getDays(new DateTimeImmutable('2024-11-01'), WeekStart::Monday);
        $this->assertCount(35, $days);
        $this->assertSame('2024-10-28', $days[0]->format('Y-m-d'));
        $this->assertSame('2024-12-01', $days[34]->format('Y-m-d'));
    }

    public function testMonthlyGetDaysReturnsFullGridForFebruary2021(): void
    {
        // February 2021: first day is Monday, last is Sunday — perfect 4 weeks, 28 days.
        $days = CalendarType::Monthly->getDays(new DateTimeImmutable('2021-02-01'), WeekStart::Monday);
        $this->assertCount(28, $days);
        $this->assertSame('2021-02-01', $days[0]->format('Y-m-d'));
        $this->assertSame('2021-02-28', $days[27]->format('Y-m-d'));
    }

    public function testWeeklyGetDaysReturnsSevenDays(): void
    {
        $days = CalendarType::Weekly->getDays(new DateTimeImmutable('2024-11-05'), WeekStart::Monday);
        $this->assertCount(7, $days);
        $this->assertSame('2024-11-04', $days[0]->format('Y-m-d'));
        $this->assertSame('2024-11-10', $days[6]->format('Y-m-d'));
    }

    public function testWorkWeekGetDays(): void
    {
        // WorkWeek generates Mon–Sun like Weekly; the caller disables Sat/Sun via disableDaysByName.
        $days = CalendarType::WorkWeek->getDays(new DateTimeImmutable('2024-11-05'), WeekStart::Monday);
        $this->assertCount(7, $days);
        $this->assertSame('2024-11-04', $days[0]->format('Y-m-d'));
        $this->assertSame('2024-11-10', $days[6]->format('Y-m-d'));
    }

    public function testMonthlyStartDateIsFirstDayOfGridNotMonth(): void
    {
        // November 2024 starts on Friday. Grid starts on Monday 28 Oct.
        $start = CalendarType::Monthly->getStartDate(new DateTimeImmutable('2024-11-01'), WeekStart::Monday);
        $this->assertSame('2024-10-28', $start->format('Y-m-d'));
    }

    public function testMonthlyEndDateIsLastDayOfGrid(): void
    {
        // November 2024 ends on Saturday. Grid ends on Sunday 01 Dec.
        $end = CalendarType::Monthly->getEndDate(new DateTimeImmutable('2024-11-01'), WeekStart::Monday);
        $this->assertSame('2024-12-01', $end->format('Y-m-d'));
    }

    public function testWeeklyStartDateIsCurrentWeekMonday(): void
    {
        $start = CalendarType::Weekly->getStartDate(new DateTimeImmutable('2024-11-07'), WeekStart::Monday);
        $this->assertSame('2024-11-04', $start->format('Y-m-d'));
    }

    public function testWorkWeekEndDateIsSundayOfWeek(): void
    {
        // WorkWeek end date aligns to Sunday (same as Weekly); Sat/Sun are disabled by the caller.
        $end = CalendarType::WorkWeek->getEndDate(new DateTimeImmutable('2024-11-07'), WeekStart::Monday);
        $this->assertSame('2024-11-10', $end->format('Y-m-d'));
    }

    public function testAllDaysAreConsecutive(): void
    {
        foreach ([CalendarType::Monthly, CalendarType::Weekly, CalendarType::WorkWeek] as $type) {
            $days = $type->getDays(new DateTimeImmutable('2024-11-05'), WeekStart::Monday);
            for ($i = 1; $i < count($days); $i++) {
                $diff = (int) $days[$i - 1]->diff($days[$i])->days;
                $this->assertSame(1, $diff, "{$type->name}: days[$i] should follow days[" . ($i - 1) . "] by exactly 1 day");
            }
        }
    }

    public function testSundayWeekStartMonthlyGrid(): void
    {
        // November 2024 starts Friday (ISO 5), Sunday-start grid must begin on Sun 27 Oct.
        // Last day of month is Saturday (ISO 6); last day of week is Saturday for Sunday-start.
        // daysForward = (6 - 6 + 7) % 7 = 0 → grid ends on Sat 30 Nov. 5 weeks = 35 days.
        $days = CalendarType::Monthly->getDays(new DateTimeImmutable('2024-11-01'), WeekStart::Sunday);
        $this->assertSame('2024-10-27', $days[0]->format('Y-m-d'), 'Grid must start on Sunday 27 Oct');
        $this->assertSame('2024-11-30', $days[array_key_last($days)]->format('Y-m-d'), 'Grid must end on Saturday 30 Nov');
        $this->assertCount(35, $days);
    }

    public function testSundayWeekStartWeeklyGrid(): void
    {
        // 2024-11-05 is Tuesday. With Sunday start the week began on Sun 3 Nov.
        $days = CalendarType::Weekly->getDays(new DateTimeImmutable('2024-11-05'), WeekStart::Sunday);
        $this->assertSame('2024-11-03', $days[0]->format('Y-m-d'), 'Week must start on Sunday 3 Nov');
        $this->assertSame('2024-11-09', $days[6]->format('Y-m-d'), 'Week must end on Saturday 9 Nov');
        $this->assertCount(7, $days);
    }

    public function testSaturdayWeekStartMonthlyGrid(): void
    {
        // November 2024: first day is Fri (ISO 5). Saturday-start: daysBack = (5-6+7)%7 = 6.
        // Grid start = Fri 1 Nov - 6 = Sat 26 Oct.
        // Last of Nov is Sat (ISO 6). lastDayIsoNumber for Saturday-start = (6+5)%7+1 = 5 (Fri).
        // daysForward = (5-6+7)%7 = 6 → grid end = Sat 30 Nov + 6 = Fri 6 Dec. 6 weeks = 42 days.
        $days = CalendarType::Monthly->getDays(new DateTimeImmutable('2024-11-01'), WeekStart::Saturday);
        $this->assertSame('2024-10-26', $days[0]->format('Y-m-d'), 'Grid must start on Saturday 26 Oct');
        $this->assertSame('2024-12-06', $days[array_key_last($days)]->format('Y-m-d'), 'Grid must end on Friday 6 Dec');
        $this->assertCount(42, $days);
    }
}
