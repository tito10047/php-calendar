# Recurring events (RFC 5545 RRULE)

`RecurrenceRule` is a pure value object for describing recurring date patterns.
No dependencies, no DB — just dates in, dates out.

---

## Building rules

```php
use Tito10047\Calendar\Recurrence\RecurrenceRule;
use Tito10047\Calendar\Enum\DayName;

// Every Monday, Wednesday, Friday
RecurrenceRule::weekly()
    ->onDays(DayName::Monday, DayName::Wednesday, DayName::Friday);

// First Monday of every month
RecurrenceRule::monthly()->onNthWeekday(1, DayName::Monday);

// Last Friday of every month
RecurrenceRule::monthly()->onNthWeekday(-1, DayName::Friday);

// Every other week
RecurrenceRule::weekly()->onDays(DayName::Monday)->every(2);

// Limited to 10 occurrences (COUNT)
RecurrenceRule::daily()->limitTo(10);

// Until a specific date
RecurrenceRule::weekly()
    ->onDays(DayName::Monday)
    ->until(new DateTimeImmutable('2024-12-31'));

// In specific months only
RecurrenceRule::weekly()
    ->onDays(DayName::Monday)
    ->onMonths(1, 7); // January and July only

// First Monday and first Friday of every month (several ordinal weekdays per rule)
RecurrenceRule::monthly()
    ->onNthWeekday(1, DayName::Monday)
    ->withNthWeekday(1, DayName::Friday);

// 100th and last day of the year; ISO week 20
RecurrenceRule::yearly()->onYearDays(100, -1);
RecurrenceRule::yearly()->onWeekNumbers(20)->onDays(DayName::Monday);

// Sub-daily: every 90 minutes; every day at 09:00 and 17:30
RecurrenceRule::minutely()->every(90);
RecurrenceRule::daily()->atHours(9, 17)->atMinutes(0, 30); // 09:00, 09:30, 17:00, 17:30
```

Factories: `secondly()`, `minutely()`, `hourly()`, `daily()`, `weekly()`, `monthly()`, `yearly()`.
Other builders: `atSeconds()`, `onMonths()`, `onMonthDays()`, `bySetPos()`, `weekStart()`, `excluding()`, `withExtraDates()`.

Invalid values throw `InvalidArgumentException` — e.g. `every(0)`, `limitTo(0)`, `onMonths(13)`,
an unknown `FREQ`, `BYMONTHDAY` with `WEEKLY`, or `BYWEEKNO` with anything other than `YEARLY`.

### Until

`until()` with a value at exactly midnight is a DATE — the whole day is inclusive. Any other value is
an exact inclusive instant. `isUntilDate()` tells you which one you have.

---

## BYSETPOS — Nth element of the expanded set

`bySetPos()` selects specific positions from the list of candidates generated for a period.
Positive = from the start, negative = from the end.

```php
// Last working day of every month
RecurrenceRule::monthly()
    ->onDays(DayName::Monday, DayName::Tuesday, DayName::Wednesday,
             DayName::Thursday, DayName::Friday)
    ->bySetPos(-1);

// Second Thursday of every month
RecurrenceRule::monthly()
    ->onDays(DayName::Thursday)
    ->bySetPos(2);

// First and last Monday of every month
RecurrenceRule::monthly()
    ->onDays(DayName::Monday)
    ->bySetPos(1, -1);
```

Works with every frequency — the set is the candidates of one period (one year, month, week, day, …).

---

## BYMONTHDAY — specific days of the month

`onMonthDays()` selects concrete day numbers within the month.
Negative values count from the end: `-1` = last day, `-2` = second-to-last day.

```php
// 1st and 15th of every month
RecurrenceRule::monthly()->onMonthDays(1, 15);

// Last day of every month
RecurrenceRule::monthly()->onMonthDays(-1);

// 15th and last day of every month
RecurrenceRule::monthly()->onMonthDays(15, -1);

// In iCal: FREQ=MONTHLY;BYMONTHDAY=15,-1
$rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYMONTHDAY=15,-1');
```

---

## RDATE — extra explicit dates

Add individual extra occurrence dates that fall outside the regular pattern:

```php
RecurrenceRule::weekly()
    ->onDays(DayName::Monday)
    ->withExtraDates(
        new DateTimeImmutable('2025-12-24'), // extra occurrence on Christmas Eve
        new DateTimeImmutable('2025-12-31'), // and New Year's Eve
    );
```

RDATE dates are merged with RRULE-generated dates and are not subject to `COUNT` or `UNTIL` bounds. They are preserved through parse → export.
A value at exactly midnight is treated as a date and gets the series' DTSTART time; any other value is used as-is.
`getExtraDates()` returns them.

---

## WKST — week start day

`WKST` controls which weekday is considered the start of the week when calculating
`WEEKLY` occurrences and the `BYSETPOS` window. Defaults to Monday (RFC 5545 default).

```php
// Week starts on Sunday (common in North America)
RecurrenceRule::weekly()
    ->onDays(DayName::Monday, DayName::Tuesday)
    ->weekStart(DayName::Sunday);

// Parse from iCal string containing WKST
$rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,TU;WKST=SU');

// WKST is only serialised when non-Monday (RFC 5545 default is MO)
echo $rule->toRruleString(); // 'FREQ=WEEKLY;BYDAY=MO,TU;WKST=SU'
```

---

## Exclusions

```php
$rule = RecurrenceRule::weekly()
    ->onDays(DayName::Monday)
    ->excluding(
        new DateTimeImmutable('2024-11-11'), // public holiday
        new DateTimeImmutable('2024-11-25'),
    );
```

A value at exactly midnight excludes the **whole day**; any other value excludes only the occurrence starting at that exact instant.
`getExDates()` returns them. In iCal, `EXDATE` lines are parsed and passed to the rule automatically.

---

## Parsing from RRULE string

```php
$rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE,FR');
$rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=1MO');
$rule = RecurrenceRule::fromRrule('FREQ=YEARLY;BYMONTH=11;BYDAY=4TH');   // Thanksgiving
$rule = RecurrenceRule::fromRrule('RRULE:FREQ=DAILY;INTERVAL=2;COUNT=10');
$rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYMONTHDAY=15,-1');
$rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=MO,FR;BYSETPOS=-1'); // last Mon or Fri
$rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO;WKST=SU');
$rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=1MO,1FR');            // several ordinals
$rule = RecurrenceRule::fromRrule('FREQ=DAILY;BYHOUR=9,17;BYMINUTE=0');     // twice a day
$rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20251231T235959Z');    // exact UTC instant
```

The `RRULE:` prefix is optional.

Supported parts: `FREQ` (`SECONDLY`, `MINUTELY`, `HOURLY`, `DAILY`, `WEEKLY`, `MONTHLY`, `YEARLY`),
`INTERVAL`, `COUNT`, `UNTIL` (DATE = whole day inclusive, or an exact UTC instant), `BYSECOND`, `BYMINUTE`,
`BYHOUR`, `BYDAY` (plain and ordinal, e.g. `1MO`, `-1FR`, `+2TU`, several per rule), `BYMONTHDAY` (incl. negative),
`BYYEARDAY`, `BYWEEKNO`, `BYMONTH`, `BYSETPOS`, `WKST`. All examples from RFC 5545 §3.8.5.3 are covered by the test suite.

Malformed or invalid rules (unknown `FREQ`, `INTERVAL=0`, `COUNT=0`, `BYMONTH=13`, …) throw `InvalidArgumentException`.

Getters: `getFrequency()`, `getInterval()`, `getCount()`, `getUntil()`, `isUntilDate()`, `getByDay()`,
`getByDayRules()` (all BYDAY entries as `[ordinal, DayName]`, ordinal 0 = every such weekday), `getByMonth()`,
`getByMonthDay()`, `getByYearDay()`, `getByWeekNo()`, `getByHour()`, `getByMinute()`, `getBySecond()`,
`getBySetPos()`, `getWkst()`, `getExDates()`, `getExtraDates()`.

---

## Expanding to concrete dates

`expand()` returns all occurrences within a date range — safe to call from `DayDataLoaderInterface::load()`.

```php
$occurrences = $rule->expand(
    from:    new DateTimeImmutable('2024-11-01'),
    to:      new DateTimeImmutable('2024-11-30'),
    dtStart: new DateTimeImmutable('2024-09-02 09:30', new DateTimeZone('Europe/Bratislava')),
); // → list<DateTimeImmutable>, sorted
```

The series is **anchored at DTSTART**: `COUNT`, `INTERVAL` and the implicit defaults (the weekday for
`WEEKLY`, the day of month for `MONTHLY`, month + day for `YEARLY`) are all derived from `$dtStart`, and
every returned occurrence carries DTSTART's time of day and timezone. When `$dtStart` is omitted, the series
is anchored at midnight of `$from` — fine for rule-only use, but pass the real DTSTART whenever the rule
belongs to an event (otherwise `COUNT=10` counts from `$from`, not from the event's first occurrence).

Occurrences starting within the days `[from, to]` (both inclusive) are returned. `COUNT` and `UNTIL` bounds are respected.
RDATE extra dates are merged in after RRULE expansion, EXDATEs removed.

As a guard against hostile feeds (e.g. `FREQ=SECONDLY` over decades), one `expand()` call examines at most
`RecurrenceRule::MAX_ITERATIONS` (500 000) periods; an expansion that hits the limit is truncated.

---

## Serialisation

```php
$rrule = $rule->toRruleString();
// 'FREQ=WEEKLY;BYDAY=MO,WE,FR'
// 'FREQ=MONTHLY;BYMONTHDAY=15,-1'
// 'FREQ=WEEKLY;BYDAY=MO;WKST=SU'

$rule2 = RecurrenceRule::fromRrule($rrule); // round-trip safe
```

Pass the series' DTSTART (and whether it is a DATE value) so `UNTIL` is written in the value type RFC 5545
requires — a DATE for all-day series, a UTC DATE-TIME otherwise:

```php
// $rule = RecurrenceRule::weekly()->until(new DateTimeImmutable('2024-12-31')) — a DATE UNTIL
$rule->toRruleString($dtStart);                       // ...;UNTIL=20241231T225959Z (DTSTART in Europe/Bratislava)
$rule->toRruleString($dtStart, dtStartIsDate: true);  // ...;UNTIL=20241231
```

This is the same format used in iCal files. See [docs/ical.md](ical.md) for the full integration.

---

## Connecting to the calendar grid

Implement `DayDataLoaderInterface` and call `expand()` inside `load()`:

```php
class RecurringEventLoader implements DayDataLoaderInterface
{
    private array $byDate = [];

    public function __construct(private array $rules) {}

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): static
    {
        $loaded = clone $this; // immutable style — return a new, populated instance
        foreach ($this->rules as ['rule' => $rule, 'start' => $start, 'title' => $title]) {
            foreach ($rule->expand($from, $to, $start) as $occurrence) {
                $loaded->byDate[$occurrence->format('Y-m-d')][] = ['title' => $title, 'time' => $occurrence];
            }
        }
        return $loaded;
    }

    public function getData(DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }
}
```

Or use `ICalDataLoader` which calls `occurrences()` / `expandOccurrences()` automatically. See [docs/ical.md](ical.md).
