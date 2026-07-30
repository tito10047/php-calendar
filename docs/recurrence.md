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

// Limited to 10 occurrences
RecurrenceRule::daily()->count(10);

// Until a specific date
RecurrenceRule::weekly()
    ->onDays(DayName::Monday)
    ->until(new DateTimeImmutable('2024-12-31'));

// In specific months only
RecurrenceRule::weekly()
    ->onDays(DayName::Monday)
    ->onMonths(1, 7); // January and July only
```

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

Works with `MONTHLY`, `WEEKLY`, and `YEARLY` frequencies.

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

In iCal, `EXDATE` lines are parsed and passed to the rule automatically.

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
```

The `RRULE:` prefix is optional.

Supported parts: `FREQ`, `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY` (plain and nth-weekday),
`BYMONTH`, `BYMONTHDAY` (incl. negative), `BYSETPOS`, `WKST`.

---

## Expanding to concrete dates

`expand()` returns all occurrences within a date range — safe to call from `DayDataLoaderInterface::load()`.

```php
$occurrences = $rule->expand(
    from: new DateTimeImmutable('2024-11-01'),
    to:   new DateTimeImmutable('2024-11-30'),
); // → list<DateTimeImmutable>
```

Only dates within `[from, to]` are returned. `COUNT` and `UNTIL` bounds are respected.
RDATE extra dates are merged in after RRULE expansion.

---

## Serialisation

```php
$rrule = $rule->toRruleString();
// 'FREQ=WEEKLY;BYDAY=MO,WE,FR'
// 'FREQ=MONTHLY;BYMONTHDAY=15,-1'
// 'FREQ=WEEKLY;BYDAY=MO;WKST=SU'

$rule2 = RecurrenceRule::fromRrule($rrule); // round-trip safe
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

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        foreach ($this->rules as ['rule' => $rule, 'title' => $title]) {
            foreach ($rule->expand($from, $to) as $date) {
                $this->byDate[$date->format('Y-m-d')][] = ['title' => $title];
            }
        }
    }

    public function getData(DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }
}
```

Or use `ICalDataLoader` which calls `occurrences()` / `expandOccurrences()` automatically. See [docs/ical.md](ical.md).
