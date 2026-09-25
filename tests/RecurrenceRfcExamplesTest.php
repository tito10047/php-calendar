<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * The recurrence examples from RFC 5545 §3.8.5.3, expanded with the DTSTART used in the RFC.
 */
final class RecurrenceRfcExamplesTest extends TestCase
{
    private const TZ = 'America/New_York';

    /**
     * @return iterable<string, array{string, string, string, string, list<string>}>
     */
    public static function rfcExamples(): iterable
    {
        yield 'daily for 10 occurrences' => ['19970902T090000', 'FREQ=DAILY;COUNT=10', '1997-01-01', '1999-12-31', [
            '1997-09-02', '1997-09-03', '1997-09-04', '1997-09-05', '1997-09-06',
            '1997-09-07', '1997-09-08', '1997-09-09', '1997-09-10', '1997-09-11',
        ]];

        yield 'every 10 days, 5 occurrences' => ['19970902T090000', 'FREQ=DAILY;INTERVAL=10;COUNT=5', '1997-01-01', '1999-12-31', [
            '1997-09-02', '1997-09-12', '1997-09-22', '1997-10-02', '1997-10-12',
        ]];

        yield 'weekly for 10 occurrences' => ['19970902T090000', 'FREQ=WEEKLY;COUNT=10', '1997-01-01', '1999-12-31', [
            '1997-09-02', '1997-09-09', '1997-09-16', '1997-09-23', '1997-09-30',
            '1997-10-07', '1997-10-14', '1997-10-21', '1997-10-28', '1997-11-04',
        ]];

        yield 'weekly on Tuesday and Thursday for five weeks' => ['19970902T090000', 'FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH', '1997-01-01', '1999-12-31', [
            '1997-09-02', '1997-09-04', '1997-09-09', '1997-09-11', '1997-09-16',
            '1997-09-18', '1997-09-23', '1997-09-25', '1997-09-30', '1997-10-02',
        ]];

        yield 'every other week on MO,WE,FR until 24 Dec' => ['19970901T090000', 'FREQ=WEEKLY;INTERVAL=2;UNTIL=19971224T000000Z;WKST=SU;BYDAY=MO,WE,FR', '1997-01-01', '1999-12-31', [
            '1997-09-01', '1997-09-03', '1997-09-05', '1997-09-15', '1997-09-17',
            '1997-09-19', '1997-09-29', '1997-10-01', '1997-10-03', '1997-10-13',
            '1997-10-15', '1997-10-17', '1997-10-27', '1997-10-29', '1997-10-31',
            '1997-11-10', '1997-11-12', '1997-11-14', '1997-11-24', '1997-11-26',
            '1997-11-28', '1997-12-08', '1997-12-10', '1997-12-12', '1997-12-22',
        ]];

        yield 'monthly on the first Friday for 10 occurrences' => ['19970905T090000', 'FREQ=MONTHLY;COUNT=10;BYDAY=1FR', '1997-01-01', '1999-12-31', [
            '1997-09-05', '1997-10-03', '1997-11-07', '1997-12-05', '1998-01-02',
            '1998-02-06', '1998-03-06', '1998-04-03', '1998-05-01', '1998-06-05',
        ]];

        yield 'every other month on the first and last Sunday' => ['19970907T090000', 'FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU', '1997-01-01', '1999-12-31', [
            '1997-09-07', '1997-09-28', '1997-11-02', '1997-11-30', '1998-01-04',
            '1998-01-25', '1998-03-01', '1998-03-29', '1998-05-03', '1998-05-31',
        ]];

        yield 'monthly on the second-to-last Monday for 6 months' => ['19970922T090000', 'FREQ=MONTHLY;COUNT=6;BYDAY=-2MO', '1997-01-01', '1999-12-31', [
            '1997-09-22', '1997-10-20', '1997-11-17', '1997-12-22', '1998-01-19', '1998-02-16',
        ]];

        yield 'monthly on the third-to-last day' => ['19970928T090000', 'FREQ=MONTHLY;BYMONTHDAY=-3', '1997-01-01', '1998-02-28', [
            '1997-09-28', '1997-10-29', '1997-11-28', '1997-12-29', '1998-01-29', '1998-02-26',
        ]];

        yield 'monthly on the 2nd and 15th for 10 occurrences' => ['19970902T090000', 'FREQ=MONTHLY;COUNT=10;BYMONTHDAY=2,15', '1997-01-01', '1999-12-31', [
            '1997-09-02', '1997-09-15', '1997-10-02', '1997-10-15', '1997-11-02',
            '1997-11-15', '1997-12-02', '1997-12-15', '1998-01-02', '1998-01-15',
        ]];

        yield 'monthly on the first and last day for 10 occurrences' => ['19970930T090000', 'FREQ=MONTHLY;COUNT=10;BYMONTHDAY=1,-1', '1997-01-01', '1999-12-31', [
            '1997-09-30', '1997-10-01', '1997-10-31', '1997-11-01', '1997-11-30',
            '1997-12-01', '1997-12-31', '1998-01-01', '1998-01-31', '1998-02-01',
        ]];

        yield 'every 18 months on the 10th thru 15th' => ['19970910T090000', 'FREQ=MONTHLY;INTERVAL=18;COUNT=10;BYMONTHDAY=10,11,12,13,14,15', '1997-01-01', '2001-12-31', [
            '1997-09-10', '1997-09-11', '1997-09-12', '1997-09-13', '1997-09-14',
            '1997-09-15', '1999-03-10', '1999-03-11', '1999-03-12', '1999-03-13',
        ]];

        yield 'every Tuesday, every other month' => ['19970902T090000', 'FREQ=MONTHLY;INTERVAL=2;BYDAY=TU', '1997-01-01', '1998-01-31', [
            '1997-09-02', '1997-09-09', '1997-09-16', '1997-09-23', '1997-09-30',
            '1997-11-04', '1997-11-11', '1997-11-18', '1997-11-25',
            '1998-01-06', '1998-01-13', '1998-01-20', '1998-01-27',
        ]];

        yield 'yearly in June and July for 10 occurrences' => ['19970610T090000', 'FREQ=YEARLY;COUNT=10;BYMONTH=6,7', '1997-01-01', '2005-12-31', [
            '1997-06-10', '1997-07-10', '1998-06-10', '1998-07-10', '1999-06-10',
            '1999-07-10', '2000-06-10', '2000-07-10', '2001-06-10', '2001-07-10',
        ]];

        yield 'every other year in January, February and March' => ['19970310T090000', 'FREQ=YEARLY;INTERVAL=2;COUNT=10;BYMONTH=1,2,3', '1997-01-01', '2005-12-31', [
            '1997-03-10', '1999-01-10', '1999-02-10', '1999-03-10', '2001-01-10',
            '2001-02-10', '2001-03-10', '2003-01-10', '2003-02-10', '2003-03-10',
        ]];

        yield 'every third year on the 1st, 100th and 200th day' => ['19970101T090000', 'FREQ=YEARLY;INTERVAL=3;COUNT=10;BYYEARDAY=1,100,200', '1997-01-01', '2008-12-31', [
            '1997-01-01', '1997-04-10', '1997-07-19', '2000-01-01', '2000-04-09',
            '2000-07-18', '2003-01-01', '2003-04-10', '2003-07-19', '2006-01-01',
        ]];

        yield 'every 20th Monday of the year' => ['19970519T090000', 'FREQ=YEARLY;BYDAY=20MO', '1997-01-01', '1999-12-31', [
            '1997-05-19', '1998-05-18', '1999-05-17',
        ]];

        yield 'Monday of week number 20' => ['19970512T090000', 'FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO', '1997-01-01', '1999-12-31', [
            '1997-05-12', '1998-05-11', '1999-05-17',
        ]];

        yield 'every Thursday in March' => ['19970313T090000', 'FREQ=YEARLY;BYMONTH=3;BYDAY=TH', '1997-01-01', '1999-12-31', [
            '1997-03-13', '1997-03-20', '1997-03-27', '1998-03-05', '1998-03-12',
            '1998-03-19', '1998-03-26', '1999-03-04', '1999-03-11', '1999-03-18', '1999-03-25',
        ]];

        yield 'every Friday the 13th' => ['19970902T090000', 'FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13', '1997-01-01', '2000-12-31', [
            '1998-02-13', '1998-03-13', '1998-11-13', '1999-08-13', '2000-10-13',
        ]];

        yield 'first Saturday that follows the first Sunday' => ['19970913T090000', 'FREQ=MONTHLY;BYDAY=SA;BYMONTHDAY=7,8,9,10,11,12,13', '1997-01-01', '1998-06-30', [
            '1997-09-13', '1997-10-11', '1997-11-08', '1997-12-13', '1998-01-10',
            '1998-02-07', '1998-03-07', '1998-04-11', '1998-05-09', '1998-06-13',
        ]];

        yield 'US presidential election day' => ['19961105T090000', 'FREQ=YEARLY;INTERVAL=4;BYMONTH=11;BYDAY=TU;BYMONTHDAY=2,3,4,5,6,7,8', '1996-01-01', '2004-12-31', [
            '1996-11-05', '2000-11-07', '2004-11-02',
        ]];

        yield 'third instance of TU, WE or TH for 3 months' => ['19970904T090000', 'FREQ=MONTHLY;COUNT=3;BYDAY=TU,WE,TH;BYSETPOS=3', '1997-01-01', '1999-12-31', [
            '1997-09-04', '1997-10-07', '1997-11-06',
        ]];

        yield 'second-to-last weekday of the month' => ['19970929T090000', 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-2', '1997-01-01', '1998-03-31', [
            '1997-09-29', '1997-10-30', '1997-11-27', '1997-12-30', '1998-01-29', '1998-02-26', '1998-03-30',
        ]];

        yield 'WKST=MO changes the result' => ['19970805T090000', 'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO', '1997-01-01', '1999-12-31', [
            '1997-08-05', '1997-08-10', '1997-08-19', '1997-08-24',
        ]];

        yield 'WKST=SU changes the result' => ['19970805T090000', 'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU', '1997-01-01', '1999-12-31', [
            '1997-08-05', '1997-08-17', '1997-08-19', '1997-08-31',
        ]];

        yield 'invalid dates (Feb 30) are skipped' => ['20070115T090000', 'FREQ=MONTHLY;BYMONTHDAY=15,30;COUNT=5', '2007-01-01', '2007-12-31', [
            '2007-01-15', '2007-01-30', '2007-02-15', '2007-03-15', '2007-03-30',
        ]];
    }

    /** @param list<string> $expected */
    #[DataProvider('rfcExamples')]
    public function testRfcExample(string $dtStart, string $rrule, string $from, string $to, array $expected): void
    {
        $tz    = new DateTimeZone(self::TZ);
        $start = DateTimeImmutable::createFromFormat('Ymd\THis', $dtStart, $tz);
        $this->assertNotFalse($start);

        $occurrences = RecurrenceRule::fromRrule($rrule)->expand(
            new DateTimeImmutable($from, $tz),
            new DateTimeImmutable($to, $tz),
            $start,
        );

        $this->assertSame($expected, array_map(static fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $occurrences));
        foreach ($occurrences as $occurrence) {
            $this->assertSame('09:00', $occurrence->format('H:i'), 'time of day comes from DTSTART');
            $this->assertSame(self::TZ, $occurrence->getTimezone()->getName());
        }
    }

    public function testDailyUntilIsExclusiveOfLaterInstants(): void
    {
        $tz     = new DateTimeZone(self::TZ);
        $start  = new DateTimeImmutable('1997-09-02 09:00', $tz);
        $result = RecurrenceRule::fromRrule('FREQ=DAILY;UNTIL=19971224T000000Z')
            ->expand(new DateTimeImmutable('1997-01-01', $tz), new DateTimeImmutable('1998-12-31', $tz), $start);

        $this->assertCount(113, $result);
        $this->assertSame('1997-12-23', $result[112]->format('Y-m-d'));
    }

    public function testEveryDayInJanuaryForThreeYears(): void
    {
        $tz    = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable('1998-01-01 09:00', $tz);

        $yearly = RecurrenceRule::fromRrule('FREQ=YEARLY;UNTIL=20000131T140000Z;BYMONTH=1;BYDAY=SU,MO,TU,WE,TH,FR,SA')
            ->expand(new DateTimeImmutable('1997-01-01', $tz), new DateTimeImmutable('2001-12-31', $tz), $start);
        $daily = RecurrenceRule::fromRrule('FREQ=DAILY;UNTIL=20000131T140000Z;BYMONTH=1')
            ->expand(new DateTimeImmutable('1997-01-01', $tz), new DateTimeImmutable('2001-12-31', $tz), $start);

        $this->assertCount(93, $yearly);
        $this->assertEquals($yearly, $daily);
    }

    public function testMinutelyEvery15MinutesForSixOccurrences(): void
    {
        $tz    = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable('1997-09-02 09:00', $tz);

        $result = RecurrenceRule::fromRrule('FREQ=MINUTELY;INTERVAL=15;COUNT=6')
            ->expand(new DateTimeImmutable('1997-09-02', $tz), new DateTimeImmutable('1997-09-02', $tz), $start);

        $this->assertSame(
            ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15'],
            array_map(static fn (DateTimeImmutable $d) => $d->format('H:i'), $result),
        );
    }

    public function testMinutelyEveryHourAndAHalf(): void
    {
        $tz    = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable('1997-09-02 09:00', $tz);

        $result = RecurrenceRule::fromRrule('FREQ=MINUTELY;INTERVAL=90;COUNT=4')
            ->expand(new DateTimeImmutable('1997-09-02', $tz), new DateTimeImmutable('1997-09-02', $tz), $start);

        $this->assertSame(
            ['09:00', '10:30', '12:00', '13:30'],
            array_map(static fn (DateTimeImmutable $d) => $d->format('H:i'), $result),
        );
    }

    public function testEvery20MinutesDuringOfficeHoursDailyAndMinutelyAgree(): void
    {
        $tz    = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable('1997-09-02 09:00', $tz);
        $from  = new DateTimeImmutable('1997-09-02', $tz);
        $to    = new DateTimeImmutable('1997-09-03', $tz);

        $daily = RecurrenceRule::fromRrule('FREQ=DAILY;BYHOUR=9,10,11,12,13,14,15,16;BYMINUTE=0,20,40')
            ->expand($from, $to, $start);
        $minutely = RecurrenceRule::fromRrule('FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10,11,12,13,14,15,16')
            ->expand($from, $to, $start);

        $this->assertCount(48, $daily);
        $this->assertSame('09:00', $daily[0]->format('H:i'));
        $this->assertSame('16:40', $daily[23]->format('H:i'));
        $this->assertEquals($daily, $minutely);
    }

    public function testHourlyEveryThreeHoursUntil(): void
    {
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('1997-09-02 09:00', $utc);

        $result = RecurrenceRule::fromRrule('FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T170000Z')
            ->expand(new DateTimeImmutable('1997-09-01', $utc), new DateTimeImmutable('1997-09-03', $utc), $start);

        $this->assertSame(
            ['09:00', '12:00', '15:00'],
            array_map(static fn (DateTimeImmutable $d) => $d->format('H:i'), $result),
        );
    }

    public function testBuilderMatchesParsedRule(): void
    {
        $tz    = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable('1997-09-07 09:00', $tz);
        $from  = new DateTimeImmutable('1997-01-01', $tz);
        $to    = new DateTimeImmutable('1999-12-31', $tz);

        $built = RecurrenceRule::monthly()->every(2)->limitTo(10)
            ->onNthWeekday(1, DayName::Sunday)
            ->withNthWeekday(-1, DayName::Sunday);

        $this->assertEquals(
            RecurrenceRule::fromRrule('FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU')->expand($from, $to, $start),
            $built->expand($from, $to, $start),
        );
        $this->assertSame('FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU', $built->toRruleString());
    }
}
