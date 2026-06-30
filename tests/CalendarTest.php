<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Enum\WeekStart;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;

class CalendarTest extends TestCase
{
    public function testIsFirstDay(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-04'),
            CalendarType::WorkWeek,
            WeekStart::Monday,
        );
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-10-01')));
        $this->assertTrue($calendar->isFirstDay(new DateTimeImmutable('2024-11-01')));
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-11-02')));
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-11-15')));
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-11-30')));
    }

    public function testIsLastDay(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-04'),
            CalendarType::WorkWeek,
            WeekStart::Monday,
        );
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-11-01')));
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-11-02')));
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-11-15')));
        $this->assertTrue($calendar->isLastDay(new DateTimeImmutable('2024-11-30')));
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-10-30')));
    }

    public function testDisableDays(): void
    {
        $calendar = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $calendar = $calendar->disableDays(
            new DateTimeImmutable('2024-11-11'),
            new DateTimeImmutable('2024-11-15'),
        );

        $this->assertTrue($calendar->isDayDisabled(new DateTimeImmutable('2024-11-11')));
        $this->assertTrue($calendar->isDayDisabled(new DateTimeImmutable('2024-11-15')));
        $this->assertFalse($calendar->isDayDisabled(new DateTimeImmutable('2024-11-10')));
        $this->assertFalse($calendar->isDayDisabled(new DateTimeImmutable('2024-11-16')));
    }

    public function testDisableDaysRange(): void
    {
        $calendar = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $calendar = $calendar->disableDaysRange(
            new DateTimeImmutable('2024-11-04'),
            new DateTimeImmutable('2024-11-08'),
        );

        foreach (['2024-11-04', '2024-11-05', '2024-11-06', '2024-11-07', '2024-11-08'] as $date) {
            $this->assertTrue($calendar->isDayDisabled(new DateTimeImmutable($date)), "$date should be disabled");
        }
        $this->assertFalse($calendar->isDayDisabled(new DateTimeImmutable('2024-11-03')));
        $this->assertFalse($calendar->isDayDisabled(new DateTimeImmutable('2024-11-09')));
    }

    public function testDisableDaysByName(): void
    {
        $calendar = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $calendar = $calendar->disableDaysByName(DayName::Saturday, DayName::Sunday);

        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $dayNum => $day) {
                if ($dayNum === 6 || $dayNum === 7) {
                    $this->assertFalse($day->enabled, "{$day->date->format('Y-m-d')} (Sat/Sun) should be disabled");
                } elseif (!$day->ghost) {
                    $this->assertTrue($day->enabled, "{$day->date->format('Y-m-d')} (weekday) should be enabled");
                }
            }
        }
    }

    public function testDisableWeek(): void
    {
        $calendar = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $calendar = $calendar->disableWeek(45);

        foreach ($calendar->getDaysTable() as $weekNum => $week) {
            foreach ($week as $day) {
                if ($weekNum === 45) {
                    $this->assertFalse($day->enabled, "Week 45 day {$day->date->format('Y-m-d')} should be disabled");
                }
            }
        }
    }

    public function testGetDisabledDays(): void
    {
        $d1 = new DateTimeImmutable('2024-11-11');
        $d2 = new DateTimeImmutable('2024-11-15');
        $calendar = (new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly))
            ->disableDays($d1, $d2);

        $disabled = $calendar->getDisabledDays();
        $this->assertCount(2, $disabled);

        $formatted = array_map(fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $disabled);
        $this->assertContains('2024-11-11', $formatted);
        $this->assertContains('2024-11-15', $formatted);
    }

    public function testDisableIsImmutable(): void
    {
        $original = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $disabled = $original->disableDays(new DateTimeImmutable('2024-11-11'));

        $this->assertFalse($original->isDayDisabled(new DateTimeImmutable('2024-11-11')));
        $this->assertTrue($disabled->isDayDisabled(new DateTimeImmutable('2024-11-11')));
    }

    public function testNextPeriodForMonthly(): void
    {
        $november = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $november = $november->disableDays(new DateTimeImmutable('2024-11-15'));

        $december = $november->nextPeriod();

        $this->assertSame('2024-12', $december->getDate()->format('Y-m'));
        $this->assertCount(0, $december->getDisabledDays(), 'nextPeriod() must clear disabled days');
    }

    public function testPrevPeriodForMonthly(): void
    {
        $november = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
        $november = $november->disableDays(new DateTimeImmutable('2024-11-15'));

        $october = $november->prevPeriod();

        $this->assertSame('2024-10', $october->getDate()->format('Y-m'));
        $this->assertCount(0, $october->getDisabledDays(), 'prevPeriod() must clear disabled days');
    }

    public function testDataLoaderPopulatesDayData(): void
    {
        /** @var DayDataLoaderInterface&Stub $loader */
        $loader = $this->createStub(DayDataLoaderInterface::class);
        $loader->method('getData')->willReturnCallback(
            fn (DateTimeImmutable $date) => ['label' => $date->format('Y-m-d')]
        );

        $calendar = (new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly))
            ->setDataLoader($loader);

        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $day) {
                $this->assertIsArray($day->data);
                $this->assertSame($day->date->format('Y-m-d'), $day->data['label']);
            }
        }
    }

    public function testTodayFlagIsMarked(): void
    {
        $today = new DateTimeImmutable('today');
        $calendar = new Calendar($today, CalendarType::Monthly);

        $todayDays = [];
        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $day) {
                if ($day->today) {
                    $todayDays[] = $day;
                }
            }
        }

        $this->assertCount(1, $todayDays, 'Exactly one day should be marked as today');
        $this->assertSame($today->format('Y-m-d'), $todayDays[0]->date->format('Y-m-d'));
    }

    public function testIsDayDisabledAcceptsDayObject(): void
    {
        $calendar = (new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly))
            ->disableDays(new DateTimeImmutable('2024-11-11'));

        $days = $calendar->getDaysTable();
        foreach ($days as $week) {
            foreach ($week as $day) {
                if ($day->date->format('Y-m-d') === '2024-11-11') {
                    $this->assertTrue($calendar->isDayDisabled($day));
                }
            }
        }
    }

    public function testStartDay(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-04'),
            CalendarType::WorkWeek,
            WeekStart::Monday,
        );
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-10-01')));
        $this->assertTrue($calendar->isFirstDay(new DateTimeImmutable('2024-11-01')));
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-11-02')));
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-11-15')));
        $this->assertFalse($calendar->isFirstDay(new DateTimeImmutable('2024-11-30')));
    }

    public function testEndDay(): void
    {
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-04'),
            CalendarType::WorkWeek,
            WeekStart::Monday,
        );
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-11-01')));
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-11-02')));
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-11-15')));
        $this->assertTrue($calendar->isLastDay(new DateTimeImmutable('2024-11-30')));
        $this->assertFalse($calendar->isLastDay(new DateTimeImmutable('2024-10-30')));
    }
}
