<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

class CalendarApiExtensionsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    public function testForMonth(): void
    {
        $calendar = Calendar::forMonth(2024, 11);
        $this->assertSame('2024-11', $calendar->getDate()->format('Y-m'));
    }

    public function testForMonthWithCustomStartDay(): void
    {
        $calendar = Calendar::forMonth(2024, 11, DayName::Sunday);
        $this->assertSame(DayName::Sunday, $calendar->getStartDay());
    }

    public function testForWeek(): void
    {
        $calendar = Calendar::forWeek(new DateTimeImmutable('2024-11-05'));
        $range = $calendar->getDateRange();
        $this->assertSame('2024-11-04', $range['from']->format('Y-m-d'));
        $this->assertSame('2024-11-10', $range['to']->format('Y-m-d'));
    }

    public function testForToday(): void
    {
        $calendar = Calendar::forToday();
        $today = new DateTimeImmutable('today');
        $this->assertSame($today->format('Y-m'), $calendar->getDate()->format('Y-m'));
    }

    public function testForTodayWithWeeklyType(): void
    {
        $calendar = Calendar::forToday(CalendarType::Weekly);
        $range = $calendar->getDateRange();
        $today = new DateTimeImmutable('today');
        $this->assertGreaterThanOrEqual(
            $range['from']->format('Y-m-d'),
            $today->format('Y-m-d'),
        );
        $this->assertLessThanOrEqual(
            $range['to']->format('Y-m-d'),
            $today->format('Y-m-d'),
        );
    }

    // -------------------------------------------------------------------------
    // getDateRange()
    // -------------------------------------------------------------------------

    public function testGetDateRangeForMonthly(): void
    {
        $calendar = Calendar::forMonth(2024, 11);
        $range = $calendar->getDateRange();
        $this->assertArrayHasKey('from', $range);
        $this->assertArrayHasKey('to', $range);
        // November 2024 with Monday start: grid Mon 28 Oct – Sun 01 Dec
        $this->assertSame('2024-10-28', $range['from']->format('Y-m-d'));
        $this->assertSame('2024-12-01', $range['to']->format('Y-m-d'));
    }

    public function testGetDateRangeForWeekly(): void
    {
        $calendar = Calendar::forWeek(new DateTimeImmutable('2024-11-05'));
        $range = $calendar->getDateRange();
        $this->assertSame('2024-11-04', $range['from']->format('Y-m-d'));
        $this->assertSame('2024-11-10', $range['to']->format('Y-m-d'));
    }

    // -------------------------------------------------------------------------
    // withDate()
    // -------------------------------------------------------------------------

    public function testWithDateChangesDatePreservesSettings(): void
    {
        $original = (new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly, DayName::Sunday))
            ->disableDays(new DateTimeImmutable('2024-11-11'));

        $moved = $original->withDate(new DateTimeImmutable('2025-03-01'));

        $this->assertSame('2025-03', $moved->getDate()->format('Y-m'));
        $this->assertSame(DayName::Sunday, $moved->getStartDay());
        // disabled days carry over
        $this->assertTrue($moved->isDayDisabled(new DateTimeImmutable('2024-11-11')));
    }

    public function testWithDateDoesNotMutateOriginal(): void
    {
        $original = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $moved = $original->withDate(new DateTimeImmutable('2025-01-01'));

        $this->assertSame('2024-11', $original->getDate()->format('Y-m'));
        $this->assertSame('2025-01', $moved->getDate()->format('Y-m'));
    }

    // -------------------------------------------------------------------------
    // enableDays()
    // -------------------------------------------------------------------------

    public function testEnableDaysRestoresPreviouslyDisabledDay(): void
    {
        $calendar = (new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly))
            ->disableDaysByName(DayName::Saturday, DayName::Sunday)
            ->enableDays(new DateTimeImmutable('2024-11-30')); // exceptional Saturday

        $this->assertFalse($calendar->isDayDisabled(new DateTimeImmutable('2024-11-30')));
        $this->assertTrue($calendar->isDayDisabled(new DateTimeImmutable('2024-11-23')), 'Other Saturdays still disabled');
    }

    public function testEnableDaysIsImmutable(): void
    {
        $disabled = (new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly))
            ->disableDays(new DateTimeImmutable('2024-11-11'));

        $enabled = $disabled->enableDays(new DateTimeImmutable('2024-11-11'));

        $this->assertTrue($disabled->isDayDisabled(new DateTimeImmutable('2024-11-11')));
        $this->assertFalse($enabled->isDayDisabled(new DateTimeImmutable('2024-11-11')));
    }

    // -------------------------------------------------------------------------
    // nextPeriod() / prevPeriod()
    // -------------------------------------------------------------------------

    public function testNextPeriodMonthly(): void
    {
        $november = Calendar::forMonth(2024, 11);
        $december = $november->nextPeriod();

        $this->assertSame('2024-12', $december->getDate()->format('Y-m'));
        $this->assertCount(0, $december->getDisabledDays());
    }

    public function testPrevPeriodMonthly(): void
    {
        $november = Calendar::forMonth(2024, 11);
        $october = $november->prevPeriod();

        $this->assertSame('2024-10', $october->getDate()->format('Y-m'));
    }

    public function testNextPeriodWeekly(): void
    {
        $week1 = Calendar::forWeek(new DateTimeImmutable('2024-11-05')); // Mon 4 – Sun 10 Nov
        $week2 = $week1->nextPeriod();

        $range = $week2->getDateRange();
        $this->assertSame('2024-11-11', $range['from']->format('Y-m-d'));
        $this->assertSame('2024-11-17', $range['to']->format('Y-m-d'));
    }

    public function testPrevPeriodWeekly(): void
    {
        $week2 = Calendar::forWeek(new DateTimeImmutable('2024-11-12')); // Mon 11 – Sun 17 Nov
        $week1 = $week2->prevPeriod();

        $range = $week1->getDateRange();
        $this->assertSame('2024-11-04', $range['from']->format('Y-m-d'));
        $this->assertSame('2024-11-10', $range['to']->format('Y-m-d'));
    }

    public function testPeriodNavigationClearsDateSpecificDisabledDays(): void
    {
        $calendar = Calendar::forMonth(2024, 11)
            ->disableDays(new DateTimeImmutable('2024-11-15'));

        $next = $calendar->nextPeriod();
        $this->assertCount(0, $next->getDisabledDays(), 'Date-specific disabled days must reset on nextPeriod');

        $prev = $calendar->prevPeriod();
        $this->assertCount(0, $prev->getDisabledDays(), 'Date-specific disabled days must reset on prevPeriod');
    }

    public function testPeriodNavigationPreservesDisabledDayNames(): void
    {
        $november = Calendar::forMonth(2024, 11)
            ->disableDaysByName(DayName::Saturday, DayName::Sunday);

        $december = $november->nextPeriod();

        $this->assertSame(
            [DayName::Saturday, DayName::Sunday],
            $december->getDisabledDayNames(),
            'Structural day-name rules must survive period navigation',
        );

        // Verify weekends are actually disabled in December
        foreach ($december->getDaysTable() as $week) {
            foreach ($week as $isoDay => $day) {
                if ($isoDay === 6 || $isoDay === 7) {
                    $this->assertFalse($day->enabled, "Weekend {$day->date->format('Y-m-d')} must be disabled in December");
                }
            }
        }
    }

    public function testPeriodNavigationResetsBothDisabledDaysAndEnabledDays(): void
    {
        $calendar = Calendar::forMonth(2024, 11)
            ->disableDaysByName(DayName::Saturday, DayName::Sunday)
            ->disableDays(new DateTimeImmutable('2024-11-11'))  // public holiday
            ->enableDays(new DateTimeImmutable('2024-11-30'));  // exceptional Saturday

        $next = $calendar->nextPeriod();

        $this->assertCount(0, $next->getDisabledDays(), 'Date-specific disabled days must reset');
        $this->assertCount(0, $next->getEnabledDays(), 'Enabled-day exceptions must reset');
        $this->assertCount(2, $next->getDisabledDayNames(), 'Structural day-name rules must persist');
    }

    public function testDisableDaysByNameStoresNamesNotDates(): void
    {
        $calendar = Calendar::forMonth(2024, 11)
            ->disableDaysByName(DayName::Saturday, DayName::Sunday);

        $this->assertSame([DayName::Saturday, DayName::Sunday], $calendar->getDisabledDayNames());
        $this->assertCount(0, $calendar->getDisabledDays(), 'disableDaysByName must not pollute the date-specific list');
    }

    public function testEnableDaysOverridesNameBasedDisable(): void
    {
        $calendar = Calendar::forMonth(2024, 11)
            ->disableDaysByName(DayName::Saturday, DayName::Sunday)
            ->enableDays(new DateTimeImmutable('2024-11-30')); // exceptional Saturday

        $this->assertFalse($calendar->isDayDisabled(new DateTimeImmutable('2024-11-30')), '2024-11-30 must be enabled as exception');
        $this->assertTrue($calendar->isDayDisabled(new DateTimeImmutable('2024-11-23')), 'Other Saturdays must remain disabled');
        $this->assertCount(1, $calendar->getEnabledDays());
    }

    public function testExplicitDisableWinsOverEnabledException(): void
    {
        // disableDays() must override a previous enableDays() for the same date
        $calendar = Calendar::forMonth(2024, 11)
            ->enableDays(new DateTimeImmutable('2024-11-11'))
            ->disableDays(new DateTimeImmutable('2024-11-11'));

        $this->assertTrue($calendar->isDayDisabled(new DateTimeImmutable('2024-11-11')));
        $this->assertCount(0, $calendar->getEnabledDays(), 'disableDays must remove the date from enabledDays');
    }

    public function testWithDatePreservesAllThreeLayers(): void
    {
        $original = Calendar::forMonth(2024, 11)
            ->disableDaysByName(DayName::Saturday, DayName::Sunday)
            ->disableDays(new DateTimeImmutable('2024-11-11'))
            ->enableDays(new DateTimeImmutable('2024-11-30'));

        $march = $original->withDate(new DateTimeImmutable('2025-03-01'));

        $this->assertSame($original->getDisabledDayNames(), $march->getDisabledDayNames());
        $this->assertCount(1, $march->getDisabledDays());
        $this->assertCount(1, $march->getEnabledDays());
    }

    public function testNextPeriodPreservesDataLoader(): void
    {
        $loader = $this->createStub(\Tito10047\Calendar\Interface\DayDataLoaderInterface::class);
        $loader->method('getData')->willReturn(['test' => true]);

        $calendar = Calendar::forMonth(2024, 11)->setDataLoader($loader);
        $next = $calendar->nextPeriod();

        foreach ($next->getDaysTable() as $week) {
            foreach ($week as $day) {
                $this->assertSame(['test' => true], $day->data);
            }
        }
    }
}
