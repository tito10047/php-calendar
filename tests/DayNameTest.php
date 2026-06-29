<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\DayName;

class DayNameTest extends TestCase
{
    public function testFromDate(): void
    {
        $this->assertSame(DayName::Monday, DayName::fromDate(new DateTimeImmutable('2024-11-04')));
        $this->assertSame(DayName::Tuesday, DayName::fromDate(new DateTimeImmutable('2024-11-05')));
        $this->assertSame(DayName::Wednesday, DayName::fromDate(new DateTimeImmutable('2024-11-06')));
        $this->assertSame(DayName::Thursday, DayName::fromDate(new DateTimeImmutable('2024-11-07')));
        $this->assertSame(DayName::Friday, DayName::fromDate(new DateTimeImmutable('2024-11-08')));
        $this->assertSame(DayName::Saturday, DayName::fromDate(new DateTimeImmutable('2024-11-09')));
        $this->assertSame(DayName::Sunday, DayName::fromDate(new DateTimeImmutable('2024-11-10')));
    }

    public function testAllStartingMonday(): void
    {
        $days = DayName::all(DayName::Monday);
        $this->assertCount(7, $days);
        $this->assertSame(DayName::Monday, $days[0]);
        $this->assertSame(DayName::Tuesday, $days[1]);
        $this->assertSame(DayName::Saturday, $days[5]);
        $this->assertSame(DayName::Sunday, $days[6]);
    }

    public function testAllStartingSunday(): void
    {
        $days = DayName::all(DayName::Sunday);
        $this->assertCount(7, $days);
        $this->assertSame(DayName::Sunday, $days[0]);
        $this->assertSame(DayName::Monday, $days[1]);
        $this->assertSame(DayName::Saturday, $days[6]);
    }

    public function testGetShortName(): void
    {
        $this->assertSame('Mon', DayName::Monday->getShortName());
        $this->assertSame('Tue', DayName::Tuesday->getShortName());
        $this->assertSame('Wed', DayName::Wednesday->getShortName());
        $this->assertSame('Thu', DayName::Thursday->getShortName());
        $this->assertSame('Fri', DayName::Friday->getShortName());
        $this->assertSame('Sat', DayName::Saturday->getShortName());
        $this->assertSame('Sun', DayName::Sunday->getShortName());
    }

    public function testGetDayNumber(): void
    {
        $this->assertSame(1, DayName::Monday->getDayNumber());
        $this->assertSame(7, DayName::Sunday->getDayNumber());
    }
}
