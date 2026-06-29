<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Day;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

class CalendarDaysTest extends TestCase
{
    public function testGetWorkWeekDays(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-04'),
            CalendarType::WorkWeek,
            DayName::Monday,
        );
        $calendar = $calendar->disableDaysByName(DayName::Saturday, DayName::Sunday);
        $dayTable = $calendar->getDaysTable();
        $this->assertCount(1, $dayTable);

        $weekKey = array_key_first($dayTable);
        $this->assertNotNull($weekKey);
        $days = $dayTable[$weekKey];

        $enabledDays = array_filter($days, fn (Day $day) => $day->enabled);
        $expected = [
            1 => '2024-11-04',
            2 => '2024-11-05',
            3 => '2024-11-06',
            4 => '2024-11-07',
            5 => '2024-11-08',
        ];
        foreach ($enabledDays as $key => $day) {
            $this->assertEquals($expected[$key], $day->date->format('Y-m-d'));
        }
    }

    public function testGetWorkWeekDaysStartOfMonth(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-01'),
            CalendarType::WorkWeek,
            DayName::Monday,
        );
        $calendar = $calendar->disableDaysByName(DayName::Saturday, DayName::Sunday);
        $daysTable = $calendar->getDaysTable();
        $this->assertCount(1, $daysTable);

        $weekKey = array_key_first($daysTable);
        $this->assertNotNull($weekKey);
        $days = $daysTable[$weekKey];

        $this->assertCount(7, $days);

        $enabledDays = array_filter($days, fn (Day $day) => $day->enabled);
        $this->assertCount(5, $enabledDays);

        $expected = [
            1 => '2024-10-28',
            2 => '2024-10-29',
            3 => '2024-10-30',
            4 => '2024-10-31',
            5 => '2024-11-01',
        ];
        foreach ($enabledDays as $key => $day) {
            $this->assertEquals($expected[$key], $day->date->format('Y-m-d'));
        }
    }

    public function testGetMonthDays(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-01'),
            CalendarType::Monthly,
            DayName::Monday,
        );
        $daysTable = $calendar->getDaysTable();
        $this->assertCount(5, $daysTable);

        /** @var Day[] $days */
        $days = array_merge(...$daysTable);

        // November 2024 with Monday start: grid is Mon 28 Oct – Sun 01 Dec (35 days).
        $this->assertCount(35, $days);
        $gridStart = new DateTimeImmutable('2024-10-28');
        foreach ($days as $i => $day) {
            $expected = $gridStart->add(new \DateInterval("P{$i}D"));
            $this->assertSame($expected->format('Y-m-d'), $day->date->format('Y-m-d'));
        }
        $this->assertSame('2024-12-01', $days[34]->date->format('Y-m-d'));
    }

    public function testWeekDays(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-05'),
            CalendarType::Weekly,
            DayName::Monday,
        );
        $daysTable = $calendar->getDaysTable();
        $this->assertCount(1, $daysTable);

        /** @var Day[] $days */
        $days = array_merge(...$daysTable);
        $this->assertCount(7, $days);

        $gridStart = new DateTimeImmutable('2024-11-04');
        foreach ($days as $i => $day) {
            $expected = $gridStart->add(new \DateInterval("P{$i}D"));
            $this->assertSame($expected->format('Y-m-d'), $day->date->format('Y-m-d'));
        }
    }

    public function testWeeklyViewHasNoGhostDays(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-05'),
            CalendarType::Weekly,
            DayName::Monday,
        );
        foreach (array_merge(...$calendar->getDaysTable()) as $day) {
            $this->assertFalse($day->ghost, "Weekly view should have no ghost days, found one on {$day->date->format('Y-m-d')}");
        }
    }

    public function testMonthlyGhostDaysAtBoundaries(): void
    {
        // November 2024: starts on Friday (day 1). Grid fills Mon 28 Oct – Sun 01 Dec.
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-04'),
            CalendarType::Monthly,
            DayName::Monday,
        );
        /** @var Day[] $days */
        $days = array_merge(...$calendar->getDaysTable());

        $ghostMonths = [];
        foreach ($days as $day) {
            if ($day->ghost) {
                $ghostMonths[$day->date->format('m')] = true;
            }
        }

        $this->assertNotEmpty($ghostMonths, 'Monthly calendar should have ghost days');
        $this->assertArrayHasKey('10', $ghostMonths, 'Ghost days from October expected');
        $this->assertArrayHasKey('12', $ghostMonths, 'Ghost days from December expected');
    }
}
