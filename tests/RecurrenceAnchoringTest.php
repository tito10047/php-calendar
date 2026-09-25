<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\Recurrence\Frequency;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Recurrence expansion must be anchored at DTSTART, never at the queried window.
 */
final class RecurrenceAnchoringTest extends TestCase
{
    /**
     * @param  list<DateTimeImmutable> $dates
     * @return list<string>
     */
    private function days(array $dates): array
    {
        return array_map(static fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public function testCountIsNotRestartedInLaterWindows(): void
    {
        $rule  = RecurrenceRule::fromRrule('FREQ=DAILY;COUNT=3');
        $start = $this->utc('2024-01-01 09:00');

        $this->assertSame([], $rule->expand($this->utc('2025-06-01'), $this->utc('2025-06-30'), $start));
        $this->assertSame(
            ['2024-01-01', '2024-01-02', '2024-01-03'],
            $this->days($rule->expand($this->utc('2023-12-01'), $this->utc('2024-12-31'), $start)),
        );
    }

    public function testNoOccurrencesBeforeDtStart(): void
    {
        $rule  = RecurrenceRule::fromRrule('FREQ=DAILY;COUNT=3');
        $start = $this->utc('2024-01-01 09:00');

        $this->assertSame(
            ['2024-01-01', '2024-01-02'],
            $this->days($rule->expand($this->utc('2023-12-28'), $this->utc('2024-01-02'), $start)),
        );
    }

    public function testIntervalPhaseDoesNotDependOnWindow(): void
    {
        $rule  = RecurrenceRule::fromRrule('FREQ=DAILY;INTERVAL=2');
        $start = $this->utc('2024-01-01 09:00');

        $this->assertSame(
            ['2024-01-03', '2024-01-05', '2024-01-07'],
            $this->days($rule->expand($this->utc('2024-01-02'), $this->utc('2024-01-07'), $start)),
        );
    }

    public function testImplicitDefaultsComeFromDtStart(): void
    {
        $from = $this->utc('2024-01-01');
        $to   = $this->utc('2024-03-31');

        // MONTHLY without BYxxx → day of month of DTSTART
        $this->assertSame(
            ['2024-01-15', '2024-02-15', '2024-03-15'],
            $this->days(RecurrenceRule::monthly()->expand($from, $to, $this->utc('2024-01-15 10:00'))),
        );

        // WEEKLY without BYDAY → weekday of DTSTART (Wednesday)
        $this->assertSame(
            ['2024-01-03', '2024-01-10', '2024-01-17'],
            $this->days(RecurrenceRule::weekly()->expand($from, $this->utc('2024-01-20'), $this->utc('2024-01-03 10:00'))),
        );

        // YEARLY without BYxxx → one date per year
        $this->assertSame(
            ['2024-03-10', '2025-03-10', '2026-03-10'],
            $this->days(RecurrenceRule::yearly()->expand($from, $this->utc('2026-12-31'), $this->utc('2024-03-10 10:00'))),
        );
    }

    public function testFarFutureQueryWithoutCountSkipsAhead(): void
    {
        $rule  = RecurrenceRule::fromRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO');
        $start = $this->utc('2000-01-03 09:00'); // a Monday

        $result = $rule->expand($this->utc('2024-01-01'), $this->utc('2024-01-31'), $start);
        // 2024-01-01 is 1252 weeks after 2000-01-03 (even) → Jan 1, 15, 29
        $this->assertSame(['2024-01-01', '2024-01-15', '2024-01-29'], $this->days($result));
    }

    public function testOccurrencesKeepTimeAndTimezoneOfDtStart(): void
    {
        $ny    = new DateTimeZone('America/New_York');
        $start = new DateTimeImmutable('2024-03-08 22:00', $ny); // DST starts 2024-03-10
        $rule  = RecurrenceRule::daily()->limitTo(4);

        $result = $rule->expand(new DateTimeImmutable('2024-03-01', $ny), new DateTimeImmutable('2024-03-31', $ny), $start);

        $this->assertCount(4, $result);
        foreach ($result as $occurrence) {
            $this->assertSame('22:00', $occurrence->format('H:i'));
            $this->assertSame('America/New_York', $occurrence->getTimezone()->getName());
        }
    }

    public function testInvalidIntervalAndCountAreRejected(): void
    {
        foreach (['FREQ=DAILY;INTERVAL=0', 'FREQ=WEEKLY;INTERVAL=-1', 'FREQ=DAILY;COUNT=0', 'FREQ=DAILY;INTERVAL=abc'] as $rrule) {
            try {
                RecurrenceRule::fromRrule($rrule);
                $this->fail("{$rrule} must be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        RecurrenceRule::daily()->every(0);
    }

    public function testRangeValidation(): void
    {
        foreach (['FREQ=YEARLY;BYMONTH=13', 'FREQ=DAILY;BYHOUR=24', 'FREQ=MONTHLY;BYDAY=0MO', 'FREQ=MONTHLY;BYYEARDAY=1', 'FREQ=MONTHLY;BYWEEKNO=1'] as $rrule) {
            try {
                RecurrenceRule::fromRrule($rrule);
                $this->fail("{$rrule} must be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUnknownFrequencyThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecurrenceRule::fromRrule('FREQ=FORTNIGHTLY');
    }

    public function testSubDailyFrequenciesAreSupported(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=HOURLY;COUNT=3');
        $this->assertSame(Frequency::Hourly, $rule->getFrequency());

        $result = $rule->expand($this->utc('2024-01-01'), $this->utc('2024-01-01'), $this->utc('2024-01-01 10:30'));
        $this->assertSame(['10:30', '11:30', '12:30'], array_map(static fn ($d) => $d->format('H:i'), $result));
    }

    public function testMultipleOrdinalsWithSameNumberAreKept(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=1MO,1FR');

        $this->assertSame('FREQ=MONTHLY;BYDAY=1MO,1FR', $rule->toRruleString());
        $this->assertSame(
            ['2024-01-01', '2024-01-05'],
            $this->days($rule->expand($this->utc('2024-01-01'), $this->utc('2024-01-31'), $this->utc('2024-01-01'))),
        );
    }

    public function testPlusSignedOrdinalAndMixedByday(): void
    {
        $this->assertSame([[1, DayName::Monday]], RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=+1MO')->getByDayRules());

        $mixed = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=MO,-1FR');
        $this->assertSame('FREQ=MONTHLY;BYDAY=MO,-1FR', $mixed->toRruleString());
        $this->assertSame(
            ['2024-01-01', '2024-01-08', '2024-01-15', '2024-01-22', '2024-01-26', '2024-01-29'],
            $this->days($mixed->expand($this->utc('2024-01-01'), $this->utc('2024-01-31'), $this->utc('2024-01-01'))),
        );
    }

    public function testDuplicateCandidatesAreRemovedBeforeCount(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYMONTHDAY=-1,31;COUNT=3');

        $this->assertSame(
            ['2024-01-31', '2024-02-29', '2024-03-31'],
            $this->days($rule->expand($this->utc('2024-01-01'), $this->utc('2024-12-31'), $this->utc('2024-01-31'))),
        );
    }

    public function testUntilWithTimeIsExact(): void
    {
        $rule  = RecurrenceRule::fromRrule('FREQ=DAILY;UNTIL=20240103T080000Z');
        $start = $this->utc('2024-01-01 09:00');

        $this->assertSame(
            ['2024-01-01', '2024-01-02'],
            $this->days($rule->expand($this->utc('2024-01-01'), $this->utc('2024-01-31'), $start)),
        );
        $this->assertFalse($rule->isUntilDate());
    }

    public function testUntilSerialisationMatchesDtStartValueType(): void
    {
        $rule = RecurrenceRule::daily()->until(new DateTimeImmutable('2024-12-31'));

        $this->assertSame('FREQ=DAILY;UNTIL=20241231', $rule->toRruleString(new DateTimeImmutable('2024-01-01'), true));
        $this->assertSame(
            'FREQ=DAILY;UNTIL=20241231T225959Z',
            $rule->toRruleString(new DateTimeImmutable('2024-01-01 09:00', new DateTimeZone('Europe/Bratislava'))),
        );
    }

    public function testExdateMatchesExactInstantAcrossTimezones(): void
    {
        $ny    = new DateTimeZone('America/New_York');
        $start = new DateTimeImmutable('2024-01-01 22:00', $ny);
        // 2024-01-03 03:00 UTC == 2024-01-02 22:00 New York
        $rule = RecurrenceRule::daily()->limitTo(4)->excluding($this->utc('2024-01-03 03:00'));

        $this->assertSame(
            ['2024-01-01', '2024-01-03', '2024-01-04'],
            $this->days($rule->expand(new DateTimeImmutable('2024-01-01', $ny), new DateTimeImmutable('2024-01-31', $ny), $start)),
        );
    }

    public function testRdateKeepsItsTime(): void
    {
        $rule   = RecurrenceRule::daily()->limitTo(1)->withExtraDates($this->utc('2024-01-05 15:30'));
        $result = $rule->expand($this->utc('2024-01-01'), $this->utc('2024-01-31'), $this->utc('2024-01-01 09:00'));

        $this->assertSame(['2024-01-01 09:00', '2024-01-05 15:30'], array_map(static fn ($d) => $d->format('Y-m-d H:i'), $result));
    }

    public function testEventInOtherTimezoneThanQuery(): void
    {
        $ny    = new DateTimeZone('America/New_York');
        $event = new ICalEvent(
            uid: 'ny',
            dtStart: new DateTimeImmutable('2024-01-01 09:00', $ny),
            dtEnd: new DateTimeImmutable('2024-01-01 10:00', $ny),
            summary: 'NY daily',
            description: null,
            location: null,
            rrule: RecurrenceRule::daily()->limitTo(3),
        );

        $instances = $event->expandOccurrences($this->utc('2023-12-01'), $this->utc('2024-01-31'));

        $this->assertSame(
            ['2024-01-01 09:00', '2024-01-02 09:00', '2024-01-03 09:00'],
            array_map(static fn (ICalEvent $e) => $e->dtStart->format('Y-m-d H:i'), $instances),
        );
    }

    public function testNonRecurringEventDayIsTakenInQueryTimezone(): void
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $event = new ICalEvent(
            uid: 'tokyo',
            dtStart: new DateTimeImmutable('2024-01-05 08:00', $tokyo),
            dtEnd: null,
            summary: 'Tokyo',
            description: null,
            location: null,
            rrule: null,
        );

        // 2024-01-05 08:00 Tokyo = 2024-01-04 23:00 UTC
        $this->assertCount(1, $event->occurrences(new DateTimeImmutable('2024-01-05', $tokyo), new DateTimeImmutable('2024-01-05', $tokyo)));
        $this->assertCount(1, $event->occurrences($this->utc('2024-01-04'), $this->utc('2024-01-04')));
        $this->assertCount(0, $event->occurrences($this->utc('2024-01-05'), $this->utc('2024-01-05')));
    }

    public function testMultiDayEventOverlappingRangeStartIsIncluded(): void
    {
        $event = new ICalEvent(
            uid: 'conf',
            dtStart: new DateTimeImmutable('2024-01-01'),
            dtEnd: new DateTimeImmutable('2024-01-06'),
            summary: 'Conference',
            description: null,
            location: null,
            rrule: null,
            allDay: true,
        );

        $this->assertCount(1, $event->expandOccurrences(new DateTimeImmutable('2024-01-03'), new DateTimeImmutable('2024-01-07')));
        $this->assertCount(0, $event->expandOccurrences(new DateTimeImmutable('2024-01-06'), new DateTimeImmutable('2024-01-07')));
    }

    public function testRecurringMultiDayEventStartedBeforeRange(): void
    {
        $event = new ICalEvent(
            uid: 'weekend',
            dtStart: $this->utc('2024-01-06 18:00'),
            dtEnd: $this->utc('2024-01-08 06:00'),
            summary: 'Weekend on call',
            description: null,
            location: null,
            rrule: RecurrenceRule::weekly(),
        );

        $instances = $event->expandOccurrences($this->utc('2024-01-14'), $this->utc('2024-01-14'));

        $this->assertCount(1, $instances);
        $this->assertSame('2024-01-13 18:00', $instances[0]->dtStart->format('Y-m-d H:i'));
        $this->assertSame('2024-01-15 06:00', $instances[0]->dtEnd?->format('Y-m-d H:i'));
        $this->assertNull($instances[0]->rrule, 'instances are single occurrences');
        $this->assertSame('2024-01-13 18:00', $instances[0]->recurrenceId?->format('Y-m-d H:i'));
    }

    public function testThisAndFutureOverrideShiftsLaterInstances(): void
    {
        $master = new ICalEvent(
            uid: 'tf',
            dtStart: $this->utc('2024-01-01 10:00'),
            dtEnd: $this->utc('2024-01-01 11:00'),
            summary: 'Standup',
            description: null,
            location: null,
            rrule: RecurrenceRule::weekly()->limitTo(4),
        );
        $override = new ICalEvent(
            uid: 'tf',
            dtStart: $this->utc('2024-01-15 14:00'),
            dtEnd: $this->utc('2024-01-15 14:30'),
            summary: 'Standup (afternoon)',
            description: null,
            location: null,
            rrule: null,
            recurrenceId: $this->utc('2024-01-15 10:00'),
            thisAndFuture: true,
        );
        $master = $master->withModifiedOccurrence($this->utc('2024-01-15 10:00'), $override);

        $instances = $master->expandOccurrences($this->utc('2024-01-01'), $this->utc('2024-01-31'));

        $this->assertSame(
            ['2024-01-01 10:00', '2024-01-08 10:00', '2024-01-15 14:00', '2024-01-22 14:00'],
            array_map(static fn (ICalEvent $e) => $e->dtStart->format('Y-m-d H:i'), $instances),
        );
        $this->assertSame('Standup (afternoon)', $instances[3]->summary);
        $this->assertSame('2024-01-22 14:30', $instances[3]->dtEnd?->format('Y-m-d H:i'));
    }
}
