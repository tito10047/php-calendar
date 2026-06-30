<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\CalendarConfig;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

class CalendarConfigTest extends TestCase
{
    public function testCacheKeyIsDeterministic(): void
    {
        $config1 = new CalendarConfig(
            date: new DateTimeImmutable('2024-11-01'),
            type: CalendarType::Monthly,
            startDay: DayName::Monday,
        );
        $config2 = new CalendarConfig(
            date: new DateTimeImmutable('2024-11-01'),
            type: CalendarType::Monthly,
            startDay: DayName::Monday,
        );

        $this->assertSame($config1->cacheKey(), $config2->cacheKey());
    }

    public function testCacheKeyChangesWhenDateChanges(): void
    {
        $a = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'));
        $b = new CalendarConfig(date: new DateTimeImmutable('2024-12-01'));

        $this->assertNotSame($a->cacheKey(), $b->cacheKey());
    }

    public function testCacheKeyChangesWhenTypeChanges(): void
    {
        $a = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'), type: CalendarType::Monthly);
        $b = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'), type: CalendarType::Weekly);

        $this->assertNotSame($a->cacheKey(), $b->cacheKey());
    }

    public function testCacheKeyChangesWhenDisabledDaysChange(): void
    {
        $a = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'));
        $b = new CalendarConfig(
            date: new DateTimeImmutable('2024-11-01'),
            disabledDays: [new DateTimeImmutable('2024-11-11')],
        );

        $this->assertNotSame($a->cacheKey(), $b->cacheKey());
    }

    public function testDisabledDayKeysAreOrderIndependent(): void
    {
        $d1 = new DateTimeImmutable('2024-11-11');
        $d2 = new DateTimeImmutable('2024-11-15');

        $a = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'), disabledDays: [$d1, $d2]);
        $b = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'), disabledDays: [$d2, $d1]);

        $this->assertSame($a->cacheKey(), $b->cacheKey());
    }

    public function testToStringReturnsCacheKey(): void
    {
        $config = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'));
        $this->assertSame($config->cacheKey(), (string) $config);
    }

    public function testFromConfigReconstructsCalendar(): void
    {
        $config = new CalendarConfig(
            date: new DateTimeImmutable('2024-11-01'),
            type: CalendarType::Monthly,
            startDay: DayName::Monday,
            disabledDays: [new DateTimeImmutable('2024-11-11')],
        );

        $calendar = Calendar::fromConfig($config);

        $this->assertSame('2024-11', $calendar->getDate()->format('Y-m'));
        $this->assertSame(DayName::Monday, $calendar->getStartDay());
        $this->assertTrue($calendar->isDayDisabled(new DateTimeImmutable('2024-11-11')));
    }

    public function testFromConfigWithData(): void
    {
        $config = new CalendarConfig(
            date: new DateTimeImmutable('2024-11-01'),
            type: CalendarType::Monthly,
        );
        $data = ['2024-11-05' => ['title' => 'Team meeting']];

        $calendar = Calendar::fromConfig($config, $data);

        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $day) {
                if ($day->date->format('Y-m-d') === '2024-11-05') {
                    $this->assertSame(['title' => 'Team meeting'], $day->data);
                    return;
                }
            }
        }
        $this->fail('Day 2024-11-05 not found in table');
    }

    public function testFromConfigWithNoDataReturnsNullDayData(): void
    {
        $config = new CalendarConfig(date: new DateTimeImmutable('2024-11-01'));
        $calendar = Calendar::fromConfig($config);

        foreach ($calendar->getDaysTable() as $week) {
            foreach ($week as $day) {
                $this->assertNull($day->data);
            }
        }
    }

    public function testConfigIsSerializable(): void
    {
        $config = new CalendarConfig(
            date: new DateTimeImmutable('2024-11-01'),
            type: CalendarType::Monthly,
            startDay: DayName::Monday,
            disabledDays: [new DateTimeImmutable('2024-11-11')],
        );

        $serialized = serialize($config);
        /** @var CalendarConfig $restored */
        $restored = unserialize($serialized);

        $this->assertSame($config->cacheKey(), $restored->cacheKey());
    }
}
