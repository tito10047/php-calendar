<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Day;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Resource\ResourceCalendar;
use Tito10047\Calendar\Resource\ResourceDataLoaderInterface;
use Tito10047\Calendar\Resource\ResourceInterface;

class ResourceCalendarTest extends TestCase
{
    private function makeResource(string $id, string $name): ResourceInterface
    {
        return new class ($id, $name) implements ResourceInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $name,
            ) {
            }

            public function getResourceId(): string
            {
                return $this->id;
            }

            public function getResourceName(): string
            {
                return $this->name;
            }
        };
    }

    /** @param array<string, array<string, array<mixed>>> $dataMap */
    private function makeLoader(array $dataMap = []): ResourceDataLoaderInterface
    {
        return new class ($dataMap) implements ResourceDataLoaderInterface {
            /** @param array<string, array<string, array<mixed>>> $dataMap */
            public function __construct(private array $dataMap)
            {
            }

            public function load(ResourceInterface $resource, DateTimeImmutable $from, DateTimeImmutable $to): void
            {
            }

            public function getData(ResourceInterface $resource, DateTimeImmutable $date): array
            {
                return $this->dataMap[$resource->getResourceId()][$date->format('Y-m-d')] ?? [];
            }
        };
    }

    public function testGetResourcesReturnsSameInstances(): void
    {
        $r1       = $this->makeResource('r1', 'Room A');
        $r2       = $this->makeResource('r2', 'Room B');
        $calendar = new Calendar(new DateTimeImmutable('2024-11-05'), CalendarType::Weekly);
        $rc       = new ResourceCalendar($calendar, [$r1, $r2], $this->makeLoader());

        $this->assertSame([$r1, $r2], $rc->getResources());
    }

    public function testGetDaysTableForResourceReturnsDayTable(): void
    {
        $r1       = $this->makeResource('r1', 'Room A');
        $calendar = new Calendar(new DateTimeImmutable('2024-11-05'), CalendarType::Weekly);
        $rc       = new ResourceCalendar($calendar, [$r1], $this->makeLoader());

        $table = $rc->getDaysTableForResource($r1);

        $this->assertNotEmpty($table);
        foreach ($table as $week) {
            foreach ($week as $day) {
                $this->assertInstanceOf(Day::class, $day);
            }
        }
    }

    public function testEachResourceReceivesItsOwnData(): void
    {
        $r1 = $this->makeResource('r1', 'Room A');
        $r2 = $this->makeResource('r2', 'Room B');

        $loader = $this->makeLoader([
            'r1' => ['2024-11-05' => ['booking' => 'Meeting A']],
            'r2' => ['2024-11-05' => ['booking' => 'Meeting B']],
        ]);

        $calendar = new Calendar(new DateTimeImmutable('2024-11-05'), CalendarType::Weekly);
        $rc       = new ResourceCalendar($calendar, [$r1, $r2], $loader);

        $r1Table = $rc->getDaysTableForResource($r1);
        $r2Table = $rc->getDaysTableForResource($r2);

        $r1Day = null;
        $r2Day = null;
        foreach ($r1Table as $week) {
            foreach ($week as $day) {
                if ($day->date->format('Y-m-d') === '2024-11-05') {
                    $r1Day = $day;
                }
            }
        }
        foreach ($r2Table as $week) {
            foreach ($week as $day) {
                if ($day->date->format('Y-m-d') === '2024-11-05') {
                    $r2Day = $day;
                }
            }
        }

        $this->assertSame(['booking' => 'Meeting A'], $r1Day?->data);
        $this->assertSame(['booking' => 'Meeting B'], $r2Day?->data);
    }

    public function testGetResourceTableContainsAllResources(): void
    {
        $r1 = $this->makeResource('r1', 'Room A');
        $r2 = $this->makeResource('r2', 'Room B');

        $calendar = new Calendar(new DateTimeImmutable('2024-11-05'), CalendarType::Weekly);
        $rc       = new ResourceCalendar($calendar, [$r1, $r2], $this->makeLoader());
        $table    = $rc->getResourceTable();

        $this->assertArrayHasKey('r1', $table);
        $this->assertArrayHasKey('r2', $table);
    }

    public function testDaysTableIsCachedForSameResource(): void
    {
        $loadCount = 0;

        $loader = new class ($loadCount) implements ResourceDataLoaderInterface {
            public function __construct(public int &$loadCount)
            {
            }

            public function load(ResourceInterface $resource, DateTimeImmutable $from, DateTimeImmutable $to): void
            {
                $this->loadCount++;
            }

            public function getData(ResourceInterface $resource, DateTimeImmutable $date): array
            {
                return [];
            }
        };

        $r1       = $this->makeResource('r1', 'Room A');
        $calendar = new Calendar(new DateTimeImmutable('2024-11-05'), CalendarType::Weekly);
        $rc       = new ResourceCalendar($calendar, [$r1], $loader);

        $rc->getDaysTableForResource($r1);
        $rc->getDaysTableForResource($r1);

        // load() on the underlying DayDataLoaderInterface is called once per getDaysTable() call
        // but getDaysTableForResource is cached so the second call should not re-call getDaysTable()
        $this->assertSame(1, $loadCount, 'Loader::load should be called only once per resource');
    }

    public function testResourceCalendarRespectsDisabledDaysOnBaseCalendar(): void
    {
        $r1       = $this->makeResource('r1', 'Room A');
        $calendar = (new Calendar(new DateTimeImmutable('2024-11-05'), CalendarType::Weekly))
            ->disableDays(new DateTimeImmutable('2024-11-05'));

        $rc    = new ResourceCalendar($calendar, [$r1], $this->makeLoader());
        $table = $rc->getDaysTableForResource($r1);

        foreach ($table as $week) {
            foreach ($week as $day) {
                if ($day->date->format('Y-m-d') === '2024-11-05') {
                    $this->assertFalse($day->enabled);
                    return;
                }
            }
        }
        $this->fail('Day 2024-11-05 not found in table');
    }
}
