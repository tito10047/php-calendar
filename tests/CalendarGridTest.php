<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\CalendarConfig;
use Tito10047\Calendar\DataLoader\DateRangeGenerator;
use Tito10047\Calendar\Day;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Enum\WeekStart;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;
use Tito10047\Calendar\Resource\BatchResourceDataLoaderInterface;
use Tito10047\Calendar\Resource\ResourceCalendar;
use Tito10047\Calendar\Resource\ResourceInterface;

final class CalendarGridTest extends TestCase
{
    /**
     * @param  array<int, array<int, Day>> $table
     * @return list<list<string>>
     */
    private function rows(array $table): array
    {
        return array_values(array_map(
            static fn (array $row) => array_values(array_map(static fn (Day $d) => $d->date->format('m-d'), $row)),
            $table,
        ));
    }

    public function testSundayStartRowsRunSundayToSaturday(): void
    {
        $table = Calendar::forMonth(2024, 12, WeekStart::Sunday)->getDaysTable();
        $rows  = $this->rows($table);

        $this->assertCount(5, $rows);
        $this->assertSame(['12-01', '12-02', '12-03', '12-04', '12-05', '12-06', '12-07'], $rows[0]);
        $this->assertSame(['12-29', '12-30', '12-31', '01-01', '01-02', '01-03', '01-04'], $rows[4]);
        $this->assertSame([7, 1, 2, 3, 4, 5, 6], array_keys($table[202449] ?? []));
    }

    public function testSaturdayStartWeek(): void
    {
        $table = Calendar::forWeek(new DateTimeImmutable('2024-11-06'), WeekStart::Saturday)->getDaysTable();

        $this->assertSame([['11-02', '11-03', '11-04', '11-05', '11-06', '11-07', '11-08']], $this->rows($table));
    }

    public function testSundayStartWeekIsOneRow(): void
    {
        $table = Calendar::forWeek(new DateTimeImmutable('2024-11-06'), WeekStart::Sunday)->getDaysTable();

        $this->assertCount(1, $table);
        $this->assertSame([['11-03', '11-04', '11-05', '11-06', '11-07', '11-08', '11-09']], $this->rows($table));
    }

    public function testRowKeysAreUniqueAndChronologicalAcrossYears(): void
    {
        $calendar = Calendar::fromDateRange(new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2025-01-31'));
        $table    = $calendar->getDaysTable();

        $this->assertSame(397, array_sum(array_map('count', $table)));
        $keys = array_keys($table);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
        $this->assertSame(202401, $keys[0]);
        $this->assertSame(202505, $keys[array_key_last($keys)]);

        // December 2024: last row is ISO week 1 of 2025 and must stay last after JSON round-trip
        $december = Calendar::forMonth(2024, 12)->getDaysTable();
        $this->assertSame(202501, array_key_last($december));
        $decoded = json_decode((string) json_encode(array_map(static fn () => 1, $december)), true);
        $this->assertIsArray($decoded);
        $this->assertSame(array_keys($december), array_keys($decoded));
    }

    public function testDisableWeekWithYear(): void
    {
        $calendar = Calendar::fromDateRange(new DateTimeImmutable('2024-12-23'), new DateTimeImmutable('2026-01-11'));

        $this->assertCount(14, $calendar->disableWeek(1)->getDisabledDays(), 'ISO week 1 of 2025 and 2026');
        $this->assertCount(7, $calendar->disableWeek(1, 2025)->getDisabledDays());
        $this->assertCount(7, $calendar->disableWeek(202501)->getDisabledDays());
    }

    public function testNextAndPrevPeriodFromEndOfMonth(): void
    {
        $jan31 = new Calendar(new DateTimeImmutable('2025-01-31'));
        $this->assertSame('2025-02', $jan31->nextPeriod()->getDate()->format('Y-m'));

        $mar31 = new Calendar(new DateTimeImmutable('2025-03-31'));
        $this->assertSame('2025-02', $mar31->prevPeriod()->getDate()->format('Y-m'));

        $may31 = new Calendar(new DateTimeImmutable('2024-05-31'));
        $this->assertSame('2024-06', $may31->nextPeriod()->getDate()->format('Y-m'));
    }

    public function testDateRangeCalendarNavigates(): void
    {
        $calendar = Calendar::fromDateRange(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-10'));

        $next = $calendar->nextPeriod();
        $this->assertSame('2025-01-11', $next->getDateRange()['from']->format('Y-m-d'));
        $this->assertSame('2025-01-20', $next->getDateRange()['to']->format('Y-m-d'));

        $moved = $calendar->withDate(new DateTimeImmutable('2025-03-01'));
        $this->assertSame('2025-03-10', $moved->getDateRange()['to']->format('Y-m-d'));
    }

    public function testFirstAndLastDayOfPeriod(): void
    {
        $range = Calendar::fromDateRange(new DateTimeImmutable('2025-01-05'), new DateTimeImmutable('2025-01-12'));
        $this->assertTrue($range->isFirstDayOfPeriod(new DateTimeImmutable('2025-01-05')));
        $this->assertTrue($range->isLastDayOfPeriod(new DateTimeImmutable('2025-01-12')));

        $week = Calendar::forWeek(new DateTimeImmutable('2024-11-06'));
        $this->assertTrue($week->isFirstDayOfPeriod(new DateTimeImmutable('2024-11-04')));
        $this->assertTrue($week->isLastDayOfPeriod(new DateTimeImmutable('2024-11-10')));

        $month = Calendar::forMonth(2024, 11);
        $this->assertTrue($month->isFirstDayOfPeriod(new DateTimeImmutable('2024-11-01')));
        $this->assertFalse($month->isFirstDayOfPeriod(new DateTimeImmutable('2024-10-28')), 'ghost days are not part of the period');
    }

    public function testTodayUsesCalendarTimezone(): void
    {
        $kiritimati = new DateTimeZone('Pacific/Kiritimati'); // UTC+14
        $niue       = new DateTimeZone('Pacific/Niue');       // UTC-11 — always a different date

        foreach ([$kiritimati, $niue] as $tz) {
            $calendar = Calendar::forToday(CalendarType::Weekly, timezone: $tz);
            $today    = array_values(array_filter(array_merge(...array_values($calendar->getDaysTable())), static fn (Day $d) => $d->today));

            $this->assertCount(1, $today);
            $this->assertSame((new DateTimeImmutable('now', $tz))->format('Y-m-d'), $today[0]->date->format('Y-m-d'));
        }
    }

    public function testDaysTableIsMemoised(): void
    {
        $loader = new class () implements DayDataLoaderInterface {
            public int $loads = 0;

            public function load(DateTimeImmutable $from, DateTimeImmutable $to): static
            {
                $this->loads++;
                return $this;
            }

            public function getData(DateTimeImmutable $date): array
            {
                return [];
            }
        };

        $calendar = Calendar::forMonth(2024, 11)->setDataLoader($loader);
        $calendar->getDaysTable();
        $calendar->getDaysTable();

        $this->assertSame(1, $loader->loads);
    }

    public function testConfigAcceptsCustomGeneratorAndIncludesTimezone(): void
    {
        $utc = new CalendarConfig(date: new DateTimeImmutable('2025-01-01', new DateTimeZone('UTC')));
        $ba  = new CalendarConfig(date: new DateTimeImmutable('2025-01-01', new DateTimeZone('Europe/Bratislava')));
        $this->assertNotSame($utc->cacheKey(), $ba->cacheKey());

        $range  = new CalendarConfig(
            date: new DateTimeImmutable('2025-01-01'),
            type: new DateRangeGenerator(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-14')),
        );
        $this->assertCount(14, array_merge(...array_values(Calendar::fromConfig($range)->getDaysTable())));
        $this->assertNotSame($utc->cacheKey(), $range->cacheKey());
    }

    public function testConfigValidatesDayNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (deliberately invalid input) */
        new CalendarConfig(date: new DateTimeImmutable('2025-01-01'), disabledDayNames: ['Saturday']);
    }

    public function testConfigDeduplicatesDayNames(): void
    {
        $config = new CalendarConfig(
            date: new DateTimeImmutable('2025-01-01'),
            disabledDayNames: [DayName::Sunday, DayName::Saturday, DayName::Sunday],
        );
        $this->assertCount(2, $config->disabledDayNames);
    }

    public function testBatchResourceLoaderIsCalledOnce(): void
    {
        $loader = new class () implements BatchResourceDataLoaderInterface {
            public int $batchCalls  = 0;
            public int $singleCalls = 0;

            public function loadAll(array $resources, DateTimeImmutable $from, DateTimeImmutable $to): void
            {
                $this->batchCalls++;
            }

            public function load(ResourceInterface $resource, DateTimeImmutable $from, DateTimeImmutable $to): void
            {
                $this->singleCalls++;
            }

            public function getData(ResourceInterface $resource, DateTimeImmutable $date): array
            {
                return [$resource->getResourceId()];
            }
        };

        $resources = [];
        foreach (['a', 'b', 'c'] as $id) {
            $resources[] = new class ($id) implements ResourceInterface {
                public function __construct(private readonly string $id)
                {
                }

                public function getResourceId(): string
                {
                    return $this->id;
                }

                public function getResourceName(): string
                {
                    return strtoupper($this->id);
                }
            };
        }

        $table = (new ResourceCalendar(Calendar::forMonth(2024, 11), $resources, $loader))->getResourceTable();

        $this->assertCount(3, $table);
        $this->assertSame(1, $loader->batchCalls);
        $this->assertSame(0, $loader->singleCalls);
    }
}
