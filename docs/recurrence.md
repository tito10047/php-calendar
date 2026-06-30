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
RecurrenceRule::monthly()
    ->onNthWeekday(1, DayName::Monday);

// Last Friday of every month
RecurrenceRule::monthly()
    ->onNthWeekday(-1, DayName::Friday);

// Every other week
RecurrenceRule::weekly()->onDays(DayName::Monday)->every(2);

// Limited to 10 occurrences
RecurrenceRule::daily()->count(10);

// Until a specific date
RecurrenceRule::weekly()
    ->onDays(DayName::Monday)
    ->until(new DateTimeImmutable('2024-12-31'));
```

---

## Parsing from RRULE string

```php
$rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE,FR');
$rule = RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=1MO');
$rule = RecurrenceRule::fromRrule('FREQ=YEARLY;BYMONTH=11;BYDAY=4TH'); // Thanksgiving
$rule = RecurrenceRule::fromRrule('RRULE:FREQ=DAILY;INTERVAL=2;COUNT=10');
```

The `RRULE:` prefix is optional.

Supported parts: `FREQ`, `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY` (plain and nth-weekday), `BYMONTH`.

---

## Expanding to concrete dates

`expand()` returns all occurrences within a date range — safe to call from `DayDataLoaderInterface::load()`.

```php
$occurrences = $rule->expand(
    from: new DateTimeImmutable('2024-11-01'),
    to:   new DateTimeImmutable('2024-11-30'),
); // → DateTimeImmutable[]
```

Only dates within `[from, to]` are returned. `COUNT` and `UNTIL` bounds are respected.

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

---

## Connecting to the calendar

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

---

## Serialisation

```php
$rrule = $rule->toRruleString();
// 'FREQ=WEEKLY;BYDAY=MO,WE,FR'

$rule2 = RecurrenceRule::fromRrule($rrule); // round-trip safe
```

This is the same format used by iCal files. See [docs/ical.md](ical.md) for the full integration.
