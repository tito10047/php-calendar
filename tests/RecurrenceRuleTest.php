<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Recurrence\Frequency;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

class RecurrenceRuleTest extends TestCase
{
    /** @param list<DateTimeImmutable> $dates
     *  @return list<string> */
    private function format(array $dates): array
    {
        return array_map(fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
    }

    // -------------------------------------------------------------------------
    // fromRrule() parsing
    // -------------------------------------------------------------------------

    public function testFromRruleParsesDailyFrequency(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=DAILY');
        $this->assertSame(Frequency::Daily, $rule->getFrequency());
        $this->assertSame(1, $rule->getInterval());
    }

    public function testFromRruleParsesWeeklyWithByday(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE,FR');
        $this->assertSame(Frequency::Weekly, $rule->getFrequency());
        $this->assertSame([DayName::Monday, DayName::Wednesday, DayName::Friday], $rule->getByDay());
    }

    public function testFromRruleStripsRrulePrefixAsString(): void
    {
        // ltrim() would strip individual chars from {R,U,L,E,:} — so a bare rule starting
        // with 'U' (e.g. UNTIL=...) would silently lose its first character.
        // str_starts_with() fixes this: only the exact prefix "RRULE:" is removed.
        $withPrefix    = RecurrenceRule::fromRrule('RRULE:FREQ=DAILY;COUNT=3');
        $withoutPrefix = RecurrenceRule::fromRrule('FREQ=DAILY;COUNT=3');

        $this->assertSame(Frequency::Daily, $withPrefix->getFrequency());
        $this->assertSame(3, $withPrefix->getCount());
        $this->assertSame($withPrefix->getFrequency(), $withoutPrefix->getFrequency());
        $this->assertSame($withPrefix->getCount(), $withoutPrefix->getCount());
    }

    public function testFromRruleDoesNotStripLeadingCharsWithoutPrefix(): void
    {
        // A rule starting with 'U' must not be mutilated when no "RRULE:" prefix is present.
        $rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20241231T235959Z');
        $this->assertNotNull($rule->getUntil(), 'UNTIL must be parsed when rule has no RRULE: prefix');
        $this->assertSame('2024-12-31', $rule->getUntil()?->format('Y-m-d'));
    }

    public function testFromRruleParsesInterval(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=DAILY;INTERVAL=2');
        $this->assertSame(2, $rule->getInterval());
    }

    public function testFromRruleParsesCount(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=DAILY;COUNT=5');
        $this->assertSame(5, $rule->getCount());
    }

    public function testFromRruleParsesUntil(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=DAILY;UNTIL=20241231T000000Z');
        $this->assertSame('2024-12-31', $rule->getUntil()?->format('Y-m-d'));
    }

    public function testFromRruleParsesNthWeekday(): void
    {
        $rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=1MO');
        $nth = $rule->getByNthWeekday();
        $this->assertNotNull($nth);
        $this->assertSame(DayName::Monday, $nth[1]);
    }

    public function testFromRruleWithRrulePrefix(): void
    {
        $rule = RecurrenceRule::fromRrule('RRULE:FREQ=WEEKLY;BYDAY=TU');
        $this->assertSame(Frequency::Weekly, $rule->getFrequency());
    }

    public function testFromRruleThrowsOnMissingFreq(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecurrenceRule::fromRrule('INTERVAL=1');
    }

    // -------------------------------------------------------------------------
    // Daily expansion
    // -------------------------------------------------------------------------

    public function testDailyExpandsEveryDay(): void
    {
        $rule   = RecurrenceRule::daily();
        $from   = new DateTimeImmutable('2024-11-01');
        $to     = new DateTimeImmutable('2024-11-05');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2024-11-01', '2024-11-02', '2024-11-03', '2024-11-04', '2024-11-05'], $result);
    }

    public function testDailyWithInterval2(): void
    {
        $rule   = RecurrenceRule::daily()->every(2);
        $from   = new DateTimeImmutable('2024-11-01');
        $to     = new DateTimeImmutable('2024-11-10');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2024-11-01', '2024-11-03', '2024-11-05', '2024-11-07', '2024-11-09'], $result);
    }

    public function testDailyWithCount(): void
    {
        $rule   = RecurrenceRule::daily()->limitTo(3);
        $from   = new DateTimeImmutable('2024-11-01');
        $to     = new DateTimeImmutable('2024-11-30');
        $result = $this->format($rule->expand($from, $to));

        $this->assertCount(3, $result);
        $this->assertSame(['2024-11-01', '2024-11-02', '2024-11-03'], $result);
    }

    public function testDailyWithUntil(): void
    {
        $rule   = RecurrenceRule::daily()->until(new DateTimeImmutable('2024-11-03'));
        $from   = new DateTimeImmutable('2024-11-01');
        $to     = new DateTimeImmutable('2024-11-30');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2024-11-01', '2024-11-02', '2024-11-03'], $result);
    }

    // -------------------------------------------------------------------------
    // Weekly expansion
    // -------------------------------------------------------------------------

    public function testWeeklyExpandsEveryWeek(): void
    {
        $rule   = RecurrenceRule::weekly()->onDays(DayName::Monday);
        $from   = new DateTimeImmutable('2024-11-01');
        $to     = new DateTimeImmutable('2024-11-30');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2024-11-04', '2024-11-11', '2024-11-18', '2024-11-25'], $result);
    }

    public function testWeeklyOnMultipleDays(): void
    {
        $rule   = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE,FR');
        $from   = new DateTimeImmutable('2024-11-04');
        $to     = new DateTimeImmutable('2024-11-08');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2024-11-04', '2024-11-06', '2024-11-08'], $result);
    }

    public function testWeeklyEvery2Weeks(): void
    {
        $rule   = RecurrenceRule::weekly()->onDays(DayName::Monday)->every(2);
        $from   = new DateTimeImmutable('2024-11-04');
        $to     = new DateTimeImmutable('2024-11-30');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2024-11-04', '2024-11-18'], $result);
    }

    // -------------------------------------------------------------------------
    // Monthly expansion
    // -------------------------------------------------------------------------

    public function testMonthlyFirstMonday(): void
    {
        $rule   = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=1MO');
        $from   = new DateTimeImmutable('2024-01-01');
        $to     = new DateTimeImmutable('2024-03-31');
        $result = $this->format($rule->expand($from, $to));

        // First Monday of Jan 2024 = 1 Jan, Feb = 5 Feb, Mar = 4 Mar
        $this->assertSame(['2024-01-01', '2024-02-05', '2024-03-04'], $result);
    }

    public function testMonthlyLastMonday(): void
    {
        $rule   = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=-1MO');
        $from   = new DateTimeImmutable('2024-11-01');
        $to     = new DateTimeImmutable('2024-11-30');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2024-11-25'], $result);
    }

    // -------------------------------------------------------------------------
    // Yearly expansion
    // -------------------------------------------------------------------------

    public function testYearlyThanksgiving(): void
    {
        // Thanksgiving: 4th Thursday in November
        $rule   = RecurrenceRule::fromRrule('FREQ=YEARLY;BYMONTH=11;BYDAY=4TH');
        $from   = new DateTimeImmutable('2023-01-01');
        $to     = new DateTimeImmutable('2025-12-31');
        $result = $this->format($rule->expand($from, $to));

        $this->assertSame(['2023-11-23', '2024-11-28', '2025-11-27'], $result);
    }

    // -------------------------------------------------------------------------
    // Exclusions (EXDATE)
    // -------------------------------------------------------------------------

    public function testExcludingRemovesSpecificDates(): void
    {
        $rule   = RecurrenceRule::weekly()->onDays(DayName::Monday)
            ->excluding(new DateTimeImmutable('2024-11-11'));
        $from   = new DateTimeImmutable('2024-11-01');
        $to     = new DateTimeImmutable('2024-11-30');
        $result = $this->format($rule->expand($from, $to));

        $this->assertNotContains('2024-11-11', $result);
        $this->assertContains('2024-11-04', $result);
        $this->assertContains('2024-11-18', $result);
    }

    // -------------------------------------------------------------------------
    // toRruleString() round-trip
    // -------------------------------------------------------------------------

    public function testToRruleStringRoundTrip(): void
    {
        $original = 'FREQ=WEEKLY;BYDAY=MO,WE,FR';
        $rule     = RecurrenceRule::fromRrule($original);
        $this->assertSame($original, $rule->toRruleString());
    }

    public function testToRruleStringIncludesInterval(): void
    {
        $rule = RecurrenceRule::daily()->every(3)->limitTo(5);
        $this->assertStringContainsString('INTERVAL=3', $rule->toRruleString());
        $this->assertStringContainsString('COUNT=5', $rule->toRruleString());
    }

    public function testLimitToThrowsOnZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecurrenceRule::daily()->limitTo(0);
    }

    public function testLimitToThrowsOnNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecurrenceRule::daily()->limitTo(-1);
    }
}
